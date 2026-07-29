<?php

namespace App\Services\Wallet;

use App\Enums\WalletTransactionType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\WithdrawalException;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\WithdrawalPaid;
use App\Notifications\WithdrawalRejected;
use App\Notifications\WithdrawalRequested;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Money leaving the platform.
 *
 * Owns every `withdrawals.status` transition, the same way ReservationService
 * owns reservation status and WalletService owns balances.
 *
 * THE CENTRAL DECISION: the Owner's balance is debited at REQUEST time, not at
 * settlement. Debiting later would let an Owner queue three withdrawals against
 * one balance and leave the platform to notice at transfer time -- by which
 * point an Admin may already have sent the first one. Rejecting or failing a
 * withdrawal reverses the debit; paying it simply leaves it debited.
 *
 * Settlement itself is a human making a bank transfer. Payouts to Pakistani
 * bank accounts have no card-processor path, so the outbound leg was always
 * going to be local rails; this class tracks that process rather than
 * automating it. It is unaffected by payments being simulated -- the money-in
 * leg is the only part standing in for a real provider.
 */
class WithdrawalService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The Owner asks to be paid out.
     *
     * @throws WithdrawalException
     */
    public function request(User $owner, PayoutAccount $account, int $amountMinor): Withdrawal
    {
        if ($account->owner_id !== $owner->id) {
            throw WithdrawalException::accountNotYours();
        }

        $minimum = (int) config('wallet.min_withdrawal_minor');

        if ($amountMinor < $minimum) {
            throw WithdrawalException::belowMinimum($minimum);
        }

        $withdrawal = DB::transaction(function () use ($owner, $account, $amountMinor) {
            $wallet = $this->wallets->lock($owner);

            if ($wallet->isFrozen()) {
                throw WithdrawalException::walletFrozen();
            }

            // Withdrawable is the SETTLED balance only. Pending earnings are
            // still inside their dispute window and may yet be refunded.
            if ($wallet->balance_minor < $amountMinor) {
                throw WithdrawalException::insufficientBalance(
                    $amountMinor,
                    $wallet->balance_minor,
                    $wallet->pending_minor,
                );
            }

            $withdrawal = Withdrawal::create([
                'owner_id' => $owner->id,
                'amount_minor' => $amountMinor,
                'payout_account_snapshot' => $account->toSnapshot(),
            ]);

            $this->wallets->debit($wallet, $amountMinor, WalletTransactionType::WithdrawalDebit, [
                'withdrawal_id' => $withdrawal->id,
                'meta' => ['reference' => $withdrawal->reference],
            ]);

            $this->audit->log(
                AuditLogger::WITHDRAWAL_REQUESTED,
                $withdrawal,
                meta: ['reference' => $withdrawal->reference, 'amount_minor' => $amountMinor],
                actor: $owner,
            );

            return $withdrawal;
        });

        $withdrawal->owner->notify(new WithdrawalRequested($withdrawal));

        return $withdrawal;
    }

    /**
     * An Admin has accepted the request and is about to make the transfer.
     *
     * A separate step from `markPaid` so the queue distinguishes "nobody has
     * looked at this" from "this is in someone's banking app right now".
     *
     * @throws WithdrawalException
     */
    public function approve(Withdrawal $withdrawal, User $admin): Withdrawal
    {
        return $this->transition($withdrawal, WithdrawalStatus::Processing, $admin, AuditLogger::WITHDRAWAL_APPROVED);
    }

    /**
     * The transfer landed. The money left the platform for real.
     *
     * No ledger movement here -- the debit happened at request time. Recording
     * the bank's own reference is what makes a line on a statement traceable
     * back to this row months later.
     *
     * @throws WithdrawalException
     */
    public function markPaid(Withdrawal $withdrawal, User $admin, ?string $externalReference = null): Withdrawal
    {
        $paid = DB::transaction(function () use ($withdrawal, $admin, $externalReference) {
            $fresh = Withdrawal::whereKey($withdrawal->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status->isTerminal()) {
                throw WithdrawalException::alreadyResolved($fresh->status->value);
            }

            $fresh->forceFill([
                'status' => WithdrawalStatus::Paid,
                'processed_by' => $admin->id,
                'processed_at' => now(),
                'external_reference' => $externalReference,
            ])->save();

            // First successful payout to an account is what verifies it: the
            // money demonstrably arrived somewhere real.
            $accountId = $fresh->payout_account_snapshot['payout_account_id'] ?? null;

            if ($accountId !== null) {
                PayoutAccount::whereKey($accountId)->whereNull('verified_at')->update(['verified_at' => now()]);
            }

            $this->audit->log(
                AuditLogger::WITHDRAWAL_PAID,
                $fresh,
                meta: [
                    'reference' => $fresh->reference,
                    'amount_minor' => $fresh->amount_minor,
                    'external_reference' => $externalReference,
                ],
                actor: $admin,
            );

            return $fresh;
        });

        $paid->loadMissing('owner');
        $paid->owner->notify(new WithdrawalPaid($paid));

        return $paid;
    }

    /**
     * Refused, or the transfer bounced. Either way the Owner gets their money
     * back into their withdrawable balance.
     *
     * @throws WithdrawalException
     */
    public function reverse(Withdrawal $withdrawal, User $admin, string $reason, bool $failed = false): Withdrawal
    {
        $reversed = DB::transaction(function () use ($withdrawal, $admin, $reason, $failed) {
            $fresh = Withdrawal::whereKey($withdrawal->getKey())->with('owner')->lockForUpdate()->firstOrFail();

            if ($fresh->status->isTerminal()) {
                throw WithdrawalException::alreadyResolved($fresh->status->value);
            }

            $fresh->forceFill([
                'status' => $failed ? WithdrawalStatus::Failed : WithdrawalStatus::Rejected,
                'processed_by' => $admin->id,
                'processed_at' => now(),
                'failure_reason' => $reason,
            ])->save();

            // Put it back. A frozen wallet is not checked here on purpose --
            // returning an Owner's own money to their own balance is never the
            // thing a freeze is meant to stop.
            $this->wallets->credit(
                $this->wallets->lock($fresh->owner),
                $fresh->amount_minor,
                WalletTransactionType::WithdrawalReversal,
                [
                    'withdrawal_id' => $fresh->id,
                    'meta' => ['reference' => $fresh->reference, 'reason' => $reason],
                ],
            );

            $this->audit->log(
                $failed ? AuditLogger::WITHDRAWAL_FAILED : AuditLogger::WITHDRAWAL_REJECTED,
                $fresh,
                $reason,
                ['reference' => $fresh->reference, 'amount_minor' => $fresh->amount_minor],
                $admin,
            );

            return $fresh;
        });

        $reversed->owner->notify(new WithdrawalRejected($reversed));

        return $reversed;
    }

    /** @throws WithdrawalException */
    private function transition(Withdrawal $withdrawal, WithdrawalStatus $to, User $admin, string $action): Withdrawal
    {
        return DB::transaction(function () use ($withdrawal, $to, $admin, $action) {
            $fresh = Withdrawal::whereKey($withdrawal->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status->isTerminal()) {
                throw WithdrawalException::alreadyResolved($fresh->status->value);
            }

            $fresh->forceFill([
                'status' => $to,
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ])->save();

            $this->audit->log($action, $fresh, meta: ['reference' => $fresh->reference], actor: $admin);

            return $fresh;
        });
    }
}
