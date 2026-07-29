<?php

namespace App\Services\Admin;

use App\Contracts\PaymentGateway;
use App\Enums\LedgerBucket;
use App\Enums\TopupStatus;
use App\Enums\WalletStatus;
use App\Enums\WalletTransactionType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\PaymentException;
use App\Exceptions\WalletException;
use App\Models\Topup;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\AuditLogger;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * Platform-level money oversight.
 *
 * Two jobs. The first is telling whoever runs this what the float position is:
 * customer balances and owner earnings are LIABILITIES, not revenue, and
 * somebody needs to be able to see the number daily.
 *
 * The second is reconciliation. Every cached balance must equal the ledger that
 * produced it, and the whole system must conserve money. If either ever fails
 * it is a production incident, and the only way to know is to check.
 */
class TreasuryService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuditLogger $audit,
        private readonly PaymentGateway $gateway,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Float position
    |--------------------------------------------------------------------------
    */

    /**
     * What the platform is holding and on whose behalf.
     *
     * @return array<string, int>
     */
    public function float(): array
    {
        $customerBalances = (int) Wallet::whereHas('user', fn ($q) => $q->where('role', 'user'))->sum('balance_minor');
        $ownerAvailable = (int) Wallet::whereHas('user', fn ($q) => $q->where('role', 'owner'))->sum('balance_minor');
        $ownerPending = (int) Wallet::sum('pending_minor');
        $held = (int) Wallet::sum('held_minor');

        $toppedUp = (int) Topup::where('status', TopupStatus::Succeeded)->sum('amount_minor');
        $paidOut = (int) Withdrawal::where('status', WithdrawalStatus::Paid)->sum('amount_minor');
        $inFlight = (int) Withdrawal::open()->sum('amount_minor');

        // Money that entered or left WITHOUT a top-up or a withdrawal: admin
        // adjustments, refunds to source, and chargeback recoveries. Signed, so
        // the sum is the net effect. Reconciliation is wrong without these --
        // a single goodwill credit would otherwise read as a discrepancy.
        $adjustments = (int) WalletTransaction::query()
            ->whereIn('type', [
                WalletTransactionType::AdminAdjustment->value,
                WalletTransactionType::ChargebackReversal->value,
            ])
            ->sum('amount_minor');

        return [
            // Owed to customers, spendable by them.
            'customer_balances_minor' => $customerBalances,
            // Inside customer balances, already committed to a pending request.
            'held_minor' => $held,
            // Owed to owners and withdrawable now.
            'owner_available_minor' => $ownerAvailable,
            // Owed to owners but still inside the dispute window.
            'owner_pending_minor' => $ownerPending,
            // Debited from owners, not yet transferred out.
            'withdrawals_in_flight_minor' => $inFlight,

            // The total liability: everything the platform is holding for
            // somebody else. This is the figure that must be backed by cash.
            'total_liability_minor' => $customerBalances + $ownerAvailable + $ownerPending + $inFlight,

            'lifetime_topups_minor' => $toppedUp,
            'lifetime_payouts_minor' => $paidOut,
            'net_adjustments_minor' => $adjustments,
        ];
    }

    /**
     * Conservation of money, platform-wide.
     *
     * Everything that came in, minus everything paid out, must equal everything
     * currently held on someone's behalf. A non-zero discrepancy means money
     * was created or destroyed and is an incident, not a rounding quirk.
     *
     * @return array<string, int|bool>
     */
    public function reconcile(): array
    {
        $float = $this->float();

        $expected = $float['lifetime_topups_minor']
            - $float['lifetime_payouts_minor']
            + $float['net_adjustments_minor'];

        $actual = $float['total_liability_minor'];

        // Wallets whose cached columns disagree with their own ledger. Checked
        // in SQL rather than by loading every wallet, so this stays usable once
        // there are more than a few thousand.
        $ledgerByWallet = WalletTransaction::query()
            ->selectRaw('wallet_id')
            ->selectRaw('SUM(CASE WHEN bucket = ? THEN amount_minor ELSE 0 END) AS ledger_balance', [LedgerBucket::Balance->value])
            ->selectRaw('SUM(CASE WHEN bucket = ? THEN amount_minor ELSE 0 END) AS ledger_pending', [LedgerBucket::Pending->value])
            ->groupBy('wallet_id');

        $drifted = Wallet::query()
            ->joinSub($ledgerByWallet, 'l', 'l.wallet_id', '=', 'wallets.id')
            ->whereColumn('wallets.balance_minor', '!=', 'l.ledger_balance')
            ->orWhereColumn('wallets.pending_minor', '!=', 'l.ledger_pending')
            ->count();

        // Held funds must equal the sum of active holds, wallet by wallet.
        $heldDrift = (int) Wallet::sum('held_minor') - (int) WalletHold::active()->sum('amount_minor');

        return [
            'expected_minor' => $expected,
            'actual_minor' => $actual,
            'discrepancy_minor' => $actual - $expected,
            'drifted_wallets' => $drifted,
            'held_drift_minor' => $heldDrift,
            'balanced' => $actual === $expected && $drifted === 0 && $heldDrift === 0,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Interventions
    |--------------------------------------------------------------------------
    */

    /**
     * Move money by hand.
     *
     * Needed on day one: without it, every mistake and every goodwill gesture
     * needs a developer and a SQL client, which is exactly how a ledger stops
     * reconciling. The reason is mandatory and lands in the audit log.
     */
    public function adjust(User $subject, int $amountMinor, bool $credit, string $reason, User $admin, ?LedgerBucket $bucket = null): WalletTransaction
    {
        return DB::transaction(function () use ($subject, $amountMinor, $credit, $reason, $admin, $bucket) {
            $wallet = $this->wallets->lock($subject);
            $bucket ??= LedgerBucket::Balance;

            $context = ['meta' => ['reason' => $reason, 'admin_id' => $admin->id]];

            $transaction = $credit
                ? $this->wallets->credit($wallet, $amountMinor, WalletTransactionType::AdminAdjustment, $context, $bucket)
                : $this->wallets->debit($wallet, $amountMinor, WalletTransactionType::AdminAdjustment, $context, $bucket);

            $this->audit->log(
                AuditLogger::WALLET_ADJUSTED,
                $wallet,
                $reason,
                [
                    'user_id' => $subject->id,
                    'amount_minor' => $credit ? $amountMinor : -$amountMinor,
                    'bucket' => $bucket->value,
                ],
                $admin,
            );

            return $transaction;
        });
    }

    /**
     * Stop a wallet spending, immediately.
     *
     * The lever for a chargeback or suspected fraud. Deliberately blunt: it is
     * reached for when something is wrong and the priority is that no more
     * money moves while somebody works out what.
     */
    public function freeze(User $subject, string $reason, User $admin): Wallet
    {
        return DB::transaction(function () use ($subject, $reason, $admin) {
            $wallet = $this->wallets->lock($subject);

            $wallet->forceFill([
                'status' => WalletStatus::Frozen,
                'frozen_reason' => $reason,
                'frozen_at' => now(),
            ])->save();

            $this->audit->log(
                AuditLogger::WALLET_FROZEN,
                $wallet,
                $reason,
                ['user_id' => $subject->id],
                $admin,
            );

            return $wallet;
        });
    }

    public function unfreeze(User $subject, User $admin): Wallet
    {
        return DB::transaction(function () use ($subject, $admin) {
            $wallet = $this->wallets->lock($subject);

            $wallet->forceFill([
                'status' => WalletStatus::Active,
                'frozen_reason' => null,
                'frozen_at' => null,
            ])->save();

            $this->audit->log(AuditLogger::WALLET_UNFROZEN, $wallet, meta: ['user_id' => $subject->id], actor: $admin);

            return $wallet;
        });
    }

    /**
     * Send a top-up back to the card it came from.
     *
     * Ordering matters and there is no perfect answer, because a database
     * transaction cannot span a network call to a card processor. The wallet is
     * debited FIRST and the debit is reversed if the gateway refuses:
     *
     *   debit then refund  -> worst case, money is stuck in a wallet we can see
     *   refund then debit  -> worst case, money left the platform with no record
     *
     * The first failure is recoverable by a person; the second is a hole.
     *
     * @throws PaymentException|WalletException
     */
    public function refundTopupToSource(Topup $topup, string $reason, User $admin): string
    {
        if (! $topup->status->hasCredited() || blank($topup->gateway_reference)) {
            throw PaymentException::notRefundable();
        }

        $topup->loadMissing('user');

        $transaction = DB::transaction(function () use ($topup, $reason, $admin) {
            $wallet = $this->wallets->lock($topup->user);

            // Throws if the customer has already spent it. That is correct:
            // refunding money that is no longer there would mint it.
            return $this->wallets->debit(
                $wallet,
                $topup->amount_minor,
                WalletTransactionType::AdminAdjustment,
                [
                    'topup_id' => $topup->id,
                    'allow_frozen' => true,
                    'meta' => ['reason' => $reason, 'refund_to_source' => true, 'admin_id' => $admin->id],
                ],
            );
        });

        try {
            $refundId = $this->gateway->refund($topup->gateway_reference, $topup->amount_minor);
        } catch (\Throwable $e) {
            // Put it back. The customer's balance must not silently absorb a
            // refund that never happened.
            DB::transaction(fn () => $this->wallets->credit(
                $this->wallets->lock($topup->user),
                $topup->amount_minor,
                WalletTransactionType::AdminAdjustment,
                ['topup_id' => $topup->id, 'meta' => ['reason' => 'Refund failed at gateway, reversed']],
            ));

            throw $e;
        }

        $this->audit->log(
            AuditLogger::TOPUP_REFUNDED,
            $topup,
            $reason,
            ['topup_id' => $topup->id, 'amount_minor' => $topup->amount_minor, 'refund_id' => $refundId],
            $admin,
        );

        return $refundId;
    }

    /**
     * A card charge was disputed after the money had already been spent.
     *
     * The one place a balance is permitted to go negative. The customer has the
     * goods -- the booking happened, the venue was paid -- and the bank is
     * taking the money back regardless. Refusing the debit would leave the
     * ledger claiming funds the platform no longer has, which is worse than an
     * honest negative.
     */
    public function applyChargeback(Topup $topup, string $reason): ?WalletTransaction
    {
        return DB::transaction(function () use ($topup, $reason) {
            $topup->loadMissing('user');
            $wallet = $this->wallets->lock($topup->user);

            $existing = WalletTransaction::where('idempotency_key', 'chargeback:'.$topup->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $transaction = $this->wallets->debit(
                $wallet,
                $topup->amount_minor,
                WalletTransactionType::ChargebackReversal,
                [
                    'topup_id' => $topup->id,
                    'idempotency_key' => 'chargeback:'.$topup->id,
                    'allow_negative' => true,
                    // A second dispute against an account the first one froze
                    // must still be recoverable.
                    'allow_frozen' => true,
                    'meta' => ['reason' => $reason],
                ],
            );

            $wallet->forceFill([
                'status' => WalletStatus::Frozen,
                'frozen_reason' => 'Chargeback on top-up #'.$topup->id,
                'frozen_at' => now(),
            ])->save();

            $this->audit->system(
                AuditLogger::WALLET_FROZEN,
                $wallet,
                ['user_id' => $topup->user_id, 'topup_id' => $topup->id, 'reason' => $reason],
            );

            return $transaction;
        });
    }
}
