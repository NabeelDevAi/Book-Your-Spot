<?php

namespace App\Services\Wallet;

use App\Enums\HoldStatus;
use App\Enums\LedgerBucket;
use App\Enums\WalletTransactionType;
use App\Exceptions\WalletException;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Models\WalletTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Owns every movement of money.
 *
 * Nothing outside this class writes a wallet balance or a ledger row. That is
 * the same rule `ReservationService` holds over `reservations.status`, and it
 * exists for the same reason: a balance changed elsewhere has no ledger row
 * behind it, so reconciliation fails with no way to reconstruct what happened.
 *
 * Two structural rules the whole class is built around:
 *
 * 1. EVERY MUTATING METHOD RUNS INSIDE THE CALLER'S TRANSACTION.
 *    Money and reservation status must commit or roll back together. A captured
 *    hold against a reservation that never confirmed cannot be repaired without
 *    someone reading the ledger by hand. The methods here therefore refuse to
 *    run outside a transaction rather than opening their own.
 *
 * 2. LOCK ORDER IS spots -> reservations -> wallets (ascending wallet id).
 *    `approve()` already holds the spot lock when it reaches this class, so
 *    wallets are always acquired last. `lockPair()` enforces the ascending-id
 *    half mechanically, because a convention that only breaks under production
 *    concurrency is not a convention anybody remembers.
 *
 * Amounts are always positive integers at this boundary. The transaction type
 * decides the sign, so no caller can credit where it meant to debit.
 */
class WalletService
{
    /*
    |--------------------------------------------------------------------------
    | Acquisition
    |--------------------------------------------------------------------------
    */

    /**
     * The user's wallet, created on first touch.
     *
     * Lazy creation is why no backfill migration is needed: existing accounts
     * get a wallet the first time one is required.
     */
    public function for(User $user): Wallet
    {
        try {
            return Wallet::firstOrCreate(['user_id' => $user->id]);
        } catch (UniqueConstraintViolationException) {
            // Two requests touched a brand-new account at once. The other one
            // won; its row is the wallet. InnoDB does not abort the surrounding
            // transaction on a duplicate key, so recovering here is safe.
            return Wallet::where('user_id', $user->id)->firstOrFail();
        }
    }

    /**
     * Fetch a wallet under `SELECT ... FOR UPDATE`.
     *
     * The row exists to be locked -- that is why balances are cached columns
     * instead of sums over the ledger. A derived balance has nothing to lock,
     * so two concurrent spends read the same figure and both succeed.
     */
    public function lock(Wallet|User $subject): Wallet
    {
        $this->assertInTransaction('lock');

        $wallet = $subject instanceof User ? $this->for($subject) : $subject;

        return Wallet::whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock two wallets in one transaction without risking a deadlock.
     *
     * Locks are taken in ascending id order regardless of the argument order,
     * which removes the cycle in the classic A-B / B-A deadlock: two captures
     * touching the same pair of wallets queue behind each other instead of
     * waiting on each other.
     *
     * Deliberately two single-row queries rather than one `whereIn`. Lock
     * acquisition follows the scan order, and a single query's scan order is
     * the optimiser's choice, not ours.
     *
     * @return array{0: Wallet, 1: Wallet} in the order the arguments were given
     */
    public function lockPair(Wallet|User $first, Wallet|User $second): array
    {
        $this->assertInTransaction('lockPair');

        $a = $first instanceof User ? $this->for($first) : $first;
        $b = $second instanceof User ? $this->for($second) : $second;

        if ($a->getKey() === $b->getKey()) {
            $locked = $this->lock($a);

            return [$locked, $locked];
        }

        $ids = [$a->getKey(), $b->getKey()];
        sort($ids);

        $locked = [];
        foreach ($ids as $id) {
            $locked[$id] = Wallet::whereKey($id)->lockForUpdate()->firstOrFail();
        }

        return [$locked[$a->getKey()], $locked[$b->getKey()]];
    }

    public function availableMinor(Wallet $wallet): int
    {
        return $wallet->availableMinor();
    }

    /*
    |--------------------------------------------------------------------------
    | Holds
    |--------------------------------------------------------------------------
    */

    /**
     * Reserve funds against a pending reservation.
     *
     * Nothing moves and no ledger row is written -- the money is still the
     * customer's, merely spoken for. This is what lets several customers queue
     * on one slot: placing and releasing a hold is free, so the losers of the
     * race give their funds straight back when `autoRejectCompeting()` rejects
     * them.
     *
     * @throws WalletException when the wallet is frozen or cannot cover it
     */
    public function hold(Wallet $wallet, Reservation $reservation, int $minor): WalletHold
    {
        $this->assertInTransaction('hold');
        $this->assertPositive($minor);
        $this->assertTransactable($wallet);

        if (! $wallet->hasAvailable($minor)) {
            throw WalletException::insufficientFunds($minor, $wallet->availableMinor());
        }

        $wallet->held_minor += $minor;
        $wallet->save();

        $this->assertConsistent($wallet);

        return WalletHold::create([
            'wallet_id' => $wallet->getKey(),
            'reservation_id' => $reservation->getKey(),
            'amount_minor' => $minor,
        ]);
    }

    /**
     * Give the funds back. Still no ledger row: nothing ever moved.
     *
     * Permitted on a frozen wallet. Freezing stops money going out, and a
     * release only returns a customer's own funds to their available pool.
     *
     * @param  string  $reason  one of the WalletHold::REASON_* constants
     *
     * @throws WalletException
     */
    public function release(WalletHold $hold, string $reason): void
    {
        $this->assertInTransaction('release');

        if ($hold->status->isResolved()) {
            throw WalletException::holdAlreadyResolved($hold->status->value);
        }

        $wallet = $this->lock(Wallet::findOrFail($hold->wallet_id));

        $wallet->held_minor -= $hold->amount_minor;
        $wallet->save();

        $this->assertConsistent($wallet);

        $hold->forceFill([
            'status' => HoldStatus::Released,
            'released_reason' => $reason,
            'resolved_at' => now(),
        ])->save();
    }

    /**
     * The Owner approved: the customer's held funds become the Owner's pending
     * earnings.
     *
     * Earnings land in `pending`, never in the withdrawable balance. That gap
     * is the reason a refund never needs clawing back out of a bank account the
     * Owner has already been paid into -- see `matureEarnings()`.
     *
     * @throws WalletException
     */
    public function capture(WalletHold $hold, Wallet $ownerWallet): void
    {
        $this->assertInTransaction('capture');

        if ($hold->status->isResolved()) {
            throw WalletException::holdAlreadyResolved($hold->status->value);
        }

        [$customer, $owner] = $this->lockPair(
            Wallet::findOrFail($hold->wallet_id),
            $ownerWallet,
        );

        if ($customer->getKey() !== $hold->wallet_id) {
            throw WalletException::holdMismatch();
        }

        $this->assertTransactable($customer);

        $amount = $hold->amount_minor;

        // Release the hold BEFORE debiting the balance. The other order leaves
        // held_minor briefly greater than balance_minor and trips the
        // consistency assertion on a state that is only ever transient.
        $customer->held_minor -= $amount;
        $customer->save();

        $context = [
            'reservation_id' => $hold->reservation_id,
            'meta' => ['hold_id' => $hold->getKey()],
        ];

        $this->applyDelta(
            $customer,
            LedgerBucket::Balance,
            -$amount,
            WalletTransactionType::BookingPayment,
            $context + ['counterparty_wallet_id' => $owner->getKey()],
        );

        $this->applyDelta(
            $owner,
            LedgerBucket::Pending,
            $amount,
            WalletTransactionType::BookingEarning,
            $context + ['counterparty_wallet_id' => $customer->getKey()],
        );

        $this->assertConsistent($customer);
        $this->assertConsistent($owner);

        $hold->forceFill([
            'status' => HoldStatus::Captured,
            'resolved_at' => now(),
        ])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Earnings
    |--------------------------------------------------------------------------
    */

    /**
     * Pending earnings become withdrawable, once the booking has completed and
     * the dispute window has passed.
     *
     * Two ledger rows, not one: a row belongs to exactly one bucket, so a
     * single row could not carry a meaningful `balance_after_minor` for both
     * sides of the move.
     */
    public function matureEarnings(Wallet $owner, Reservation $reservation, int $minor): void
    {
        $this->assertInTransaction('matureEarnings');
        $this->assertPositive($minor);

        $context = ['reservation_id' => $reservation->getKey()];

        $this->applyDelta($owner, LedgerBucket::Pending, -$minor, WalletTransactionType::EarningMaturedOut, $context);
        $this->applyDelta($owner, LedgerBucket::Balance, $minor, WalletTransactionType::EarningMaturedIn, $context);

        $this->assertConsistent($owner);
    }

    /**
     * Take back part or all of an Owner's unmatured earnings, because the
     * customer cancelled or the booking was called off.
     *
     * Always resolves against `pending`. If this ever needs to touch a settled
     * balance, the maturity window was too short and that is the bug to fix.
     */
    public function reverseEarning(Wallet $owner, Reservation $reservation, int $minor, string $reason): void
    {
        $this->assertInTransaction('reverseEarning');
        $this->assertPositive($minor);

        $this->applyDelta($owner, LedgerBucket::Pending, -$minor, WalletTransactionType::EarningReversal, [
            'reservation_id' => $reservation->getKey(),
            'meta' => ['reason' => $reason],
        ]);

        $this->assertConsistent($owner);
    }

    /** Money returned to a customer after a cancellation (Phase 4). */
    public function refund(Wallet $customer, Reservation $reservation, int $minor, string $reason): WalletTransaction
    {
        $this->assertInTransaction('refund');
        $this->assertPositive($minor);

        $transaction = $this->applyDelta($customer, LedgerBucket::Balance, $minor, WalletTransactionType::BookingRefund, [
            'reservation_id' => $reservation->getKey(),
            'meta' => ['reason' => $reason],
        ]);

        $this->assertConsistent($customer);

        return $transaction;
    }

    /*
    |--------------------------------------------------------------------------
    | Generic movements
    |--------------------------------------------------------------------------
    | Used by top-ups (Phase 2), withdrawals (Phase 6) and Admin adjustments.
    | The type decides the bucket unless one is passed explicitly, which only
    | AdminAdjustment needs since it is the sole type that can target either.
    */

    public function credit(
        Wallet $wallet,
        int $minor,
        WalletTransactionType $type,
        array $context = [],
        ?LedgerBucket $bucket = null,
    ): WalletTransaction {
        $this->assertInTransaction('credit');
        $this->assertPositive($minor);

        $transaction = $this->applyDelta($wallet, $bucket ?? $type->bucket(), $minor, $type, $context);

        $this->assertConsistent($wallet);

        return $transaction;
    }

    public function debit(
        Wallet $wallet,
        int $minor,
        WalletTransactionType $type,
        array $context = [],
        ?LedgerBucket $bucket = null,
    ): WalletTransaction {
        $this->assertInTransaction('debit');
        $this->assertPositive($minor);

        // `allow_frozen` exists for chargebacks. A freeze stops money going OUT
        // to a venue or a bank; it must not stop the platform recovering money
        // a bank has already taken back, which would otherwise be impossible on
        // the second dispute against an account the first one froze.
        if (! ($context['allow_frozen'] ?? false)) {
            $this->assertTransactable($wallet);
        }

        $transaction = $this->applyDelta($wallet, $bucket ?? $type->bucket(), -$minor, $type, $context);

        $this->assertConsistent($wallet);

        return $transaction;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The single point at which a balance changes and a ledger row is written.
     *
     * Pass `allow_negative` in the context only for a chargeback reversal
     * (Phase 7), where the customer has already spent money the bank is taking
     * back. Every other negative result is a bug and throws.
     */
    private function applyDelta(
        Wallet $wallet,
        LedgerBucket $bucket,
        int $delta,
        WalletTransactionType $type,
        array $context = [],
    ): WalletTransaction {
        $column = $bucket->column();
        $resulting = $wallet->{$column} + $delta;

        if ($resulting < 0 && ! ($context['allow_negative'] ?? false)) {
            throw WalletException::negativeBalance($column, $resulting);
        }

        $wallet->{$column} = $resulting;
        $wallet->save();

        return WalletTransaction::create([
            'wallet_id' => $wallet->getKey(),
            'amount_minor' => $delta,
            'bucket' => $bucket,
            'type' => $type,
            'balance_after_minor' => $resulting,
            'reservation_id' => $context['reservation_id'] ?? null,
            'topup_id' => $context['topup_id'] ?? null,
            'withdrawal_id' => $context['withdrawal_id'] ?? null,
            'counterparty_wallet_id' => $context['counterparty_wallet_id'] ?? null,
            'idempotency_key' => $context['idempotency_key'] ?? null,
            'meta' => $context['meta'] ?? null,
            'created_at' => now(),
        ]);
    }

    /**
     * Money must commit or roll back with the reservation status it belongs to,
     * so an unwrapped call is always a bug rather than a style preference.
     */
    private function assertInTransaction(string $operation): void
    {
        if (DB::transactionLevel() < 1) {
            throw WalletException::outsideTransaction($operation);
        }
    }

    private function assertPositive(int $minor): void
    {
        if ($minor <= 0) {
            throw WalletException::nonPositiveAmount($minor);
        }
    }

    private function assertTransactable(Wallet $wallet): void
    {
        if (! $wallet->status->canTransact()) {
            throw WalletException::walletFrozen();
        }
    }

    /**
     * Invariants checked after every mutation, never before.
     *
     * These throw rather than clamp. A clamped balance is a lost rupee that
     * nobody notices until reconciliation, months later, with no record of
     * which operation ate it.
     */
    private function assertConsistent(Wallet $wallet): void
    {
        if ($wallet->held_minor < 0) {
            throw WalletException::negativeBalance('held_minor', $wallet->held_minor);
        }

        if ($wallet->pending_minor < 0) {
            throw WalletException::negativeBalance('pending_minor', $wallet->pending_minor);
        }

        // Only meaningful while the balance is non-negative. A chargeback can
        // legitimately drive it below zero, at which point "holds must fit
        // inside the balance" describes a state that no longer exists -- the
        // holds were placed against money the bank has since taken back.
        if ($wallet->balance_minor >= 0 && $wallet->held_minor > $wallet->balance_minor) {
            throw WalletException::heldExceedsBalance($wallet->held_minor, $wallet->balance_minor);
        }
    }
}
