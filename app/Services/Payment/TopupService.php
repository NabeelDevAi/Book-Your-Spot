<?php

namespace App\Services\Payment;

use App\Contracts\PaymentGateway;
use App\Enums\TopupStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\PaymentException;
use App\Models\Topup;
use App\Models\User;
use App\Notifications\WalletToppedUp;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves money from a card into a wallet.
 *
 * The rule this class exists to enforce: A WALLET IS CREDITED ONLY BY
 * `fulfil()`. Never from a browser request that merely claims a payment
 * worked -- users close tabs, networks drop, and a success URL that can be
 * replayed is a wallet that mints itself.
 *
 * Payments are currently SIMULATED (see SimulatedGateway): the gateway settles
 * synchronously, so `start()` credits the wallet immediately. Everything
 * downstream of that credit is real and reconciles exactly as it would against
 * a live provider.
 *
 * `fulfil()` is idempotent at two layers, which is what will let an
 * asynchronous gateway call it from a callback later without any change here:
 *
 *   1. The status check below runs under a row lock on the top-up.
 *   2. `wallet_transactions.idempotency_key` is unique, so even a caller that
 *      defeated the first would hit a constraint rather than double-credit.
 */
class TopupService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly WalletService $wallets,
    ) {}

    /**
     * Begin a top-up: record the attempt, then ask the gateway for an intent.
     *
     * The local row is written FIRST so an attempt that dies mid-call still
     * leaves evidence. A gateway charge with no local record is the one
     * outcome that cannot be reconciled afterwards.
     *
     * @throws PaymentException
     */
    public function start(User $user, int $amountMinor): StartedTopup
    {
        $this->assertWithinLimits($amountMinor);

        $topup = Topup::create([
            'user_id' => $user->id,
            'amount_minor' => $amountMinor,
        ]);

        try {
            $intent = $this->gateway->createIntent(
                $amountMinor,
                metadata: [
                    'topup_id' => (string) $topup->id,
                    'user_id' => (string) $user->id,
                ],
                // Keyed on the local row, so a resubmitted form resolves to the
                // same intent instead of a second charge.
                idempotencyKey: 'topup_'.$topup->id,
            );
        } catch (PaymentException $e) {
            $topup->forceFill([
                'status' => TopupStatus::Failed,
                'failure_message' => $e->getMessage(),
            ])->save();

            throw $e;
        }

        $topup->forceFill([
            'gateway_reference' => $intent->id,
            'gateway' => $this->gateway->settlesInstantly() ? 'simulated' : 'external',
        ])->save();

        // A synchronous gateway has already taken the money by the time it
        // returns, so waiting for a callback that will never arrive would leave
        // the top-up pending forever. `fulfil()` is idempotent, so an
        // asynchronous gateway that happens to settle immediately is safe here
        // too -- its later callback finds the work already done.
        if ($intent->isSucceeded()) {
            $this->fulfil($topup, $intent->amountMinor, $intent->chargeId);
            $topup->refresh();
        }

        return new StartedTopup($topup, $intent);
    }

    /**
     * The gateway confirmed the money is settled. Credit the wallet.
     *
     * @param  int  $paidAmountMinor  what the gateway says was actually taken
     * @return bool true if this call performed the credit, false if it had
     *              already happened
     */
    public function fulfil(Topup $topup, int $paidAmountMinor, ?string $chargeId = null): bool
    {
        return DB::transaction(function () use ($topup, $paidAmountMinor, $chargeId) {
            // `user` is eager-loaded: the wallet lookup and the notification
            // both need it, and this runs inside a webhook where a lazy-loading
            // violation would surface as a 500 from a background caller.
            $fresh = Topup::whereKey($topup->getKey())->with('user')->lockForUpdate()->firstOrFail();

            if ($fresh->status->hasCredited()) {
                return false;
            }

            // Credit what was genuinely taken, not what we asked for. The two
            // should always agree; if they ever do not, the customer's real
            // money is the authority and the discrepancy is worth shouting
            // about rather than silently papering over.
            if ($paidAmountMinor !== $fresh->amount_minor) {
                Log::warning('Top-up amount mismatch', [
                    'topup_id' => $fresh->id,
                    'expected_minor' => $fresh->amount_minor,
                    'paid_minor' => $paidAmountMinor,
                ]);
            }

            $wallet = $this->wallets->lock($fresh->user);

            $this->wallets->credit($wallet, $paidAmountMinor, WalletTransactionType::Topup, [
                'topup_id' => $fresh->id,
                // Last line of defence against a double credit.
                'idempotency_key' => 'topup:'.$fresh->id,
                'meta' => array_filter([
                    'charge_id' => $chargeId,
                    'expected_minor' => $paidAmountMinor !== $fresh->amount_minor ? $fresh->amount_minor : null,
                ]),
            ]);

            $fresh->forceFill([
                'status' => TopupStatus::Succeeded,
                'gateway_charge_id' => $chargeId,
                'succeeded_at' => now(),
                'amount_minor' => $paidAmountMinor,
            ])->save();

            // Inside the transaction deliberately: the notification is queued,
            // and telling someone their money arrived before the credit commits
            // is worse than telling them slightly late. The balance comes from
            // the wallet we just wrote, not a re-read -- see WalletToppedUp.
            $fresh->user->notify(new WalletToppedUp($fresh, $wallet->balance_minor));

            return true;
        });
    }

    /** The card was declined or the customer gave up. Nothing has moved. */
    public function fail(Topup $topup, ?string $code, ?string $message, bool $cancelled = false): void
    {
        DB::transaction(function () use ($topup, $code, $message, $cancelled) {
            $fresh = Topup::whereKey($topup->getKey())->lockForUpdate()->firstOrFail();

            // A late failure event for something already credited is noise --
            // the money settled, whatever arrived afterwards.
            if ($fresh->status->isTerminal()) {
                return;
            }

            $fresh->forceFill([
                'status' => $cancelled ? TopupStatus::Cancelled : TopupStatus::Failed,
                'failure_code' => $code,
                'failure_message' => $message,
            ])->save();
        });
    }

    /**
     * Ask the gateway what really happened to a top-up that never resolved.
     *
     * Webhooks get dropped. This is what stops that becoming a customer whose
     * money left their account and never arrived.
     *
     * @return bool whether this call changed anything
     */
    public function reconcile(Topup $topup): bool
    {
        if ($topup->status->isTerminal() || blank($topup->gateway_reference)) {
            return false;
        }

        $intent = $this->gateway->retrieveIntent($topup->gateway_reference);

        if ($intent->isSucceeded()) {
            return $this->fulfil($topup, $intent->amountMinor, $intent->chargeId);
        }

        if ($intent->isCancelled()) {
            $this->fail($topup, $intent->failureCode, $intent->failureMessage, cancelled: true);

            return true;
        }

        return false;
    }

    /**
     * Re-checked here as well as in the Form Request. The request guards the
     * form; this guards the operation, and only one of those is reachable from
     * every caller.
     *
     * @throws PaymentException
     */
    private function assertWithinLimits(int $amountMinor): void
    {
        $min = (int) config('wallet.min_topup_minor');
        $max = (int) config('wallet.max_topup_minor');

        if ($amountMinor < $min) {
            throw PaymentException::belowMinimum($min);
        }

        if ($amountMinor > $max) {
            throw PaymentException::aboveMaximum($max);
        }
    }
}
