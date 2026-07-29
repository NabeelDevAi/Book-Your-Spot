<?php

namespace App\Services\Booking;

use App\Enums\CancellationEvent;
use App\Exceptions\WalletException;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * The money side of a reservation's lifecycle.
 *
 * Split out of ReservationService so that class keeps owning STATE and this one
 * owns MONEY. Every method here is called from inside one of ReservationService's
 * existing transactions, never on its own -- a captured hold against a booking
 * whose confirmation rolled back cannot be repaired without reading the ledger
 * by hand.
 *
 * Lock order is honoured by construction: ReservationService already holds the
 * spot and/or reservation lock by the time anything here runs, and wallet locks
 * are always acquired last.
 *
 * Every method no-ops on a reservation with no `amount_paid_minor`. That covers
 * the rows written before wallets existed, which must stay cancellable.
 */
class SettlementService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly RefundResolver $refunds,
    ) {}

    /**
     * Reserve the booking's cost against the customer's wallet.
     *
     * At REQUEST time, not approval. Several customers may hold requests on one
     * slot -- `ReservationStatus::blocking()` excludes `pending` -- and because
     * placing and releasing a wallet hold costs nothing, each of them can
     * reserve their own funds and the losers get theirs straight back. This is
     * the step a card payment could not have taken.
     *
     * It also removes the "approved but the customer is now broke" failure:
     * the money is already committed before the Owner ever sees the request.
     *
     * @throws WalletException when the wallet cannot cover it
     */
    public function hold(Reservation $reservation, User $customer): ?WalletHold
    {
        $amount = (int) ($reservation->amount_paid_minor ?? 0);

        if ($amount <= 0) {
            return null;
        }

        return $this->wallets->hold($this->wallets->lock($customer), $reservation, $amount);
    }

    /**
     * The Owner approved: the held funds become their pending earnings.
     *
     * Pending, not withdrawable. See `mature()`.
     */
    public function capture(Reservation $reservation): void
    {
        $hold = $this->activeHold($reservation);

        if ($hold === null) {
            return;
        }

        $this->wallets->capture($hold, $this->ownerWallet($reservation));
    }

    /**
     * Give the customer their money back without anything ever having moved.
     *
     * @param  string  $reason  a WalletHold::REASON_* constant
     */
    public function release(Reservation $reservation, string $reason): void
    {
        $hold = $this->activeHold($reservation);

        if ($hold !== null) {
            $this->wallets->release($hold, $reason);
        }
    }

    /**
     * Split a paid booking's money when it ends without being played.
     *
     * Two shapes, depending on how far the booking got:
     *
     *  - still `pending`: nothing was captured, so the hold is simply released
     *    in full. The tiers do not apply -- the venue never accepted it, so
     *    there is nothing for them to be compensated for.
     *
     *  - already `confirmed`: the money is sitting in the Owner's PENDING
     *    earnings. The customer's share is reversed out of pending and credited
     *    back to their wallet; the Owner's share simply stays where it is and
     *    matures on schedule.
     *
     * Resolving against pending is what makes this safe. A clawback from a
     * settled Owner balance is never required, because the maturity window
     * guarantees the money is still ours to move.
     *
     * @return array{user_minor: int, owner_minor: int, refund_percent: int}
     */
    public function settle(Reservation $reservation, CancellationEvent $event): array
    {
        $paid = (int) ($reservation->amount_paid_minor ?? 0);

        if ($paid <= 0) {
            return ['user_minor' => 0, 'owner_minor' => 0, 'refund_percent' => 0];
        }

        // Never captured -- the customer gets everything back, full stop.
        if ($this->activeHold($reservation) !== null) {
            $this->release($reservation, WalletHold::REASON_CANCELLED);

            return ['user_minor' => $paid, 'owner_minor' => 0, 'refund_percent' => 100];
        }

        $split = $this->refunds->resolve($reservation, $event);

        if ($split['user_minor'] > 0) {
            [$customerWallet, $ownerWallet] = $this->wallets->lockPair(
                $this->customerOf($reservation),
                $this->ownerOf($reservation),
            );

            $this->wallets->reverseEarning($ownerWallet, $reservation, $split['user_minor'], $event->value);
            $this->wallets->refund($customerWallet, $reservation, $split['user_minor'], $event->value);
        }

        return $split;
    }

    /**
     * Release an Owner's earnings for a finished booking into their withdrawable
     * balance.
     *
     * Driven by the maturity sweep, never by the completion itself: the dispute
     * window has to elapse first, and until it does the money must stay
     * reversible.
     *
     * @return bool whether anything moved
     */
    public function mature(Reservation $reservation): bool
    {
        if ($reservation->earnings_matured_at !== null) {
            return false;
        }

        // What the Owner actually keeps: the settled figure if the booking was
        // cancelled or no-showed, otherwise the whole amount.
        $amount = (int) ($reservation->owner_settlement_minor ?? $reservation->amount_paid_minor ?? 0);

        if ($amount > 0) {
            $this->wallets->matureEarnings(
                $this->wallets->lock($this->ownerOf($reservation)),
                $reservation,
                $amount,
            );
        }

        $reservation->forceFill(['earnings_matured_at' => now()])->save();

        return $amount > 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Lookups
    |--------------------------------------------------------------------------
    */

    private function activeHold(Reservation $reservation): ?WalletHold
    {
        return WalletHold::where('reservation_id', $reservation->getKey())->active()->first();
    }

    /**
     * Explicit queries rather than relation access: these run inside webhook
     * and scheduler contexts where `preventLazyLoading()` turns an implicit
     * load into a hard failure, and the caller cannot be relied on to have
     * eager-loaded the right chain.
     */
    private function customerOf(Reservation $reservation): User
    {
        return User::findOrFail($reservation->user_id);
    }

    private function ownerOf(Reservation $reservation): User
    {
        $reservation->loadMissing('business');

        return User::findOrFail($reservation->business->owner_id);
    }

    private function ownerWallet(Reservation $reservation): Wallet
    {
        return $this->wallets->for($this->ownerOf($reservation));
    }

    /**
     * Wraps a settlement in its own transaction, for the sweeps that are not
     * already inside one. Booking-lifecycle callers must NOT use this -- they
     * join their own transaction so money and status commit together.
     */
    public function inTransaction(callable $work): mixed
    {
        return DB::transaction($work);
    }
}
