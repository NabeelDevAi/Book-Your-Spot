<?php

namespace App\Services\Booking;

use App\Enums\CancellationEvent;
use App\Enums\RejectionReason;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Models\WalletHold;
use App\Notifications\ReservationCancelled;
use App\Notifications\ReservationConfirmed;
use App\Notifications\ReservationExpired;
use App\Notifications\ReservationNoShow;
use App\Notifications\ReservationRejected;
use App\Notifications\ReservationRequested;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns every reservation state transition (SRS section 5).
 *
 * Nothing outside this class writes `reservations.status`. That single rule is
 * what keeps the lifecycle honest and the audit trail complete rather than
 * mostly complete.
 */
class ReservationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BookingValidator $validator,
        private readonly PricingCalculator $pricing,
        private readonly DeadlineCalculator $deadlines,
        private readonly AvailabilityService $availability,
        private readonly SettlementService $settlement,
        private readonly RefundResolver $refunds,
    ) {}

    /**
     * A customer requests a slot (FR-4.3 – FR-4.5).
     *
     * @throws BookingException
     */
    public function request(
        Spot $spot,
        User $user,
        Carbon $start,
        int $durationMinutes,
        ?string $note = null,
    ): Reservation {
        $this->validator->validate($spot, $user, $start, $durationMinutes);

        $end = $start->copy()->addMinutes($durationMinutes);

        $reservation = DB::transaction(function () use ($spot, $user, $start, $end, $durationMinutes, $note) {
            $reservation = new Reservation([
                'spot_id' => $spot->id,
                'user_id' => $user->id,
                'business_id' => $spot->business_id,
                'start_datetime' => $start,
                'end_datetime' => $end,
                'duration_minutes' => $durationMinutes,
                'customer_note' => $note,
            ]);

            // Price is snapshotted now so a later change to the Spot cannot
            // rewrite what this customer agreed to (SRS 9.10).
            $reservation->forceFill($this->pricing->snapshotFor($spot, $durationMinutes));

            // The authoritative money figure, in exact integer paisa. The
            // decimal `total_price` above stays for display and reporting.
            $reservation->amount_paid_minor = $this->pricing->totalMinor($spot, $durationMinutes);

            // The refund terms as they stand right now. Same principle as the
            // price snapshot: editing config/wallet.php later must not rewrite
            // what this customer agreed to.
            $reservation->refund_policy_snapshot = $this->refunds->snapshotTiers();

            $reservation->status = ReservationStatus::Pending;
            $reservation->requested_at = now();
            $reservation->response_deadline = $this->deadlines->for($start);
            $reservation->save();

            // Reserve the money before the request is visible to anyone. Throws
            // if the wallet cannot cover it, rolling the reservation back with
            // it -- a pending request the customer cannot pay for would waste
            // the Owner's response window.
            $this->settlement->hold($reservation, $user);

            return $reservation;
        });

        $reservation->load('spot', 'business.owner', 'user');
        $reservation->business->owner->notify(new ReservationRequested($reservation));

        return $reservation;
    }

    /**
     * The Owner approves (FR-4.6). This is the critical section.
     *
     * MySQL has no exclusion constraint, so the no-double-booking guarantee
     * (NFR-1) has to be an explicit lock. Locking the SPOT row -- not the
     * reservation -- is what serialises competing approvals: two owners
     * approving overlapping requests on the same spot queue behind each other,
     * so the second sees the first's `confirmed` row and refuses.
     *
     * Locking the reservation rows instead would not work: the two requests are
     * different rows, so both locks would be granted and both would confirm.
     *
     * Approving also auto-rejects every other overlapping pending request. That
     * is the flip side of allowing several customers to queue on one slot -- if
     * losers were left pending, they would block the slot forever and the
     * owner's queue would fill with requests that can never be honoured.
     *
     * @throws BookingException
     */
    public function approve(Reservation $reservation, User $actor): Reservation
    {
        [$confirmed, $displaced] = DB::transaction(function () use ($reservation, $actor) {
            // Serialise all approvals for this spot. The venue comes along
            // because isBookable() and the overlap check both need it, and every
            // query issued here runs while the lock is held.
            $spot = Spot::whereKey($reservation->spot_id)
                ->with('business')
                ->lockForUpdate()
                ->firstOrFail();

            $fresh = Reservation::whereKey($reservation->getKey())->firstOrFail();

            // Re-validate everything under the lock. Between the owner opening
            // the page and clicking approve, the request may have expired, the
            // customer may have cancelled, or a rival may have been confirmed.
            if (! $fresh->status->awaitsOwner()) {
                throw BookingException::notPending();
            }

            if ($fresh->end_datetime->isPast()) {
                throw BookingException::inThePast();
            }

            if (! $spot->isBookable()) {
                throw BookingException::notBookable();
            }

            if (! $this->availability->isFree($spot, $fresh->start_datetime, $fresh->end_datetime, $fresh->id)) {
                throw BookingException::slotTaken();
            }

            $fresh->forceFill([
                'status' => ReservationStatus::Confirmed,
                'responded_at' => now(),
                'responded_by' => $actor->id,
            ])->save();

            // The customer's held funds become the Owner's PENDING earnings --
            // not withdrawable until the booking completes and the dispute
            // window passes. That gap is what lets a later cancellation refund
            // without clawing money out of an account already paid out.
            $this->settlement->capture($fresh);

            $displaced = $this->autoRejectCompeting($fresh, $actor);

            $this->audit->log(
                AuditLogger::RESERVATION_APPROVED,
                $fresh,
                meta: [
                    'reference' => $fresh->reference,
                    'auto_rejected' => $displaced->pluck('reference')->all(),
                ],
                actor: $actor,
            );

            return [$fresh, $displaced];
        });

        $confirmed->load('spot', 'business', 'user');
        $confirmed->user->notify(new ReservationConfirmed($confirmed));

        foreach ($displaced as $loser) {
            $loser->load('spot', 'business', 'user');
            $loser->user->notify(new ReservationRejected($loser));
        }

        return $confirmed;
    }

    /**
     * Reject every other pending request overlapping the newly confirmed one.
     *
     * Runs inside the approval transaction and under the same spot lock, so no
     * request can slip in between the confirmation and this sweep.
     *
     * @return \Illuminate\Support\Collection<int, Reservation>
     */
    private function autoRejectCompeting(Reservation $winner, User $actor): \Illuminate\Support\Collection
    {
        $competing = Reservation::query()
            ->forSpot($winner->spot_id)
            ->pending()
            ->whereKeyNot($winner->getKey())
            ->overlapping($winner->start_datetime, $winner->end_datetime)
            ->get();

        foreach ($competing as $loser) {
            $loser->forceFill([
                'status' => ReservationStatus::Rejected,
                'responded_at' => now(),
                'responded_by' => $actor->id,
                // A distinct reason so the customer is told they lost a race,
                // not that the venue turned them down personally.
                'rejection_reason_code' => RejectionReason::SlotTaken,
            ])->save();

            // They lost the race, so their money goes straight back. Releasing
            // a hold costs nothing and takes effect immediately, which is what
            // makes several customers queueing on one slot workable at all.
            $this->settlement->release($loser, WalletHold::REASON_SLOT_TAKEN);

            $this->audit->log(
                AuditLogger::RESERVATION_AUTO_REJECTED,
                $loser,
                meta: ['reference' => $loser->reference, 'winner' => $winner->reference],
                actor: $actor,
            );
        }

        return $competing;
    }

    /**
     * The Owner declines (FR-4.6, SRS 9.19).
     *
     * @throws BookingException
     */
    public function reject(
        Reservation $reservation,
        User $actor,
        RejectionReason $reason,
        ?string $note = null,
    ): Reservation {
        $rejected = DB::transaction(function () use ($reservation, $actor, $reason, $note) {
            $fresh = Reservation::whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->status->awaitsOwner()) {
                throw BookingException::notPending();
            }

            $fresh->forceFill([
                'status' => ReservationStatus::Rejected,
                'responded_at' => now(),
                'responded_by' => $actor->id,
                'rejection_reason_code' => $reason,
                'rejection_reason_text' => $note,
            ])->save();

            // Declined, so nothing was ever owed. Full release, no tiers.
            $this->settlement->release($fresh, WalletHold::REASON_REJECTED);

            $this->audit->log(
                AuditLogger::RESERVATION_REJECTED,
                $fresh,
                $note,
                ['reference' => $fresh->reference, 'reason' => $reason->value],
                $actor,
            );

            return $fresh;
        });

        $rejected->load('spot', 'business', 'user');
        $rejected->user->notify(new ReservationRejected($rejected));

        return $rejected;
    }

    /**
     * Cancel and release the slot (FR-4.9, SRS 9.3 / 9.4).
     *
     * A cancellation inside the cutoff is still allowed -- there is no payment
     * to forfeit in V1 -- but it is flagged. The flag is computed from the
     * reservation's own timing rather than passed in, so a caller cannot
     * suppress it.
     */
    public function cancel(Reservation $reservation, User $actor, ?string $reason = null): Reservation
    {
        $cancelled = DB::transaction(function () use ($reservation, $actor, $reason) {
            $fresh = Reservation::whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->status->isCancellable()) {
                return $fresh;
            }

            $isLate = $fresh->isWithinCancellationCutoff();

            $fresh->forceFill([
                'status' => ReservationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancelled_by_role' => $actor->role,
                'cancellation_reason' => $reason,
                'is_late_cancellation' => $isLate,
            ])->save();

            // Who cancelled decides the split, not merely when. A customer is
            // held to the timing tiers they agreed to; an Owner or Admin
            // cancellation always refunds in full, because the customer did
            // nothing wrong and must not pay for the venue's change of mind.
            $event = CancellationEvent::forCanceller($actor->role);
            $split = $this->settlement->settle($fresh, $event);

            $fresh->forceFill([
                'refund_amount_minor' => $split['user_minor'],
                'owner_settlement_minor' => $split['owner_minor'],
            ])->save();

            $this->audit->log(
                AuditLogger::RESERVATION_CANCELLED,
                $fresh,
                $reason,
                [
                    'reference' => $fresh->reference,
                    'late' => $isLate,
                    'cancelled_by_role' => $actor->role->value,
                    'refunded_minor' => $split['user_minor'],
                    'owner_keeps_minor' => $split['owner_minor'],
                    'refund_percent' => $split['refund_percent'],
                ],
                $actor,
            );

            return $fresh;
        });

        if ($cancelled->status === ReservationStatus::Cancelled && $cancelled->wasChanged()) {
            $cancelled->load('spot', 'business.owner', 'user');

            // Tell the other party, never the person who just clicked cancel.
            $recipient = $actor->id === $cancelled->user_id
                ? $cancelled->business->owner
                : $cancelled->user;

            $recipient->notify(new ReservationCancelled($cancelled));
        }

        return $cancelled;
    }

    /**
     * FR-4.7 -- the owner let the window lapse. Driven by the scheduler.
     *
     * Notifies both parties: the customer needs to look elsewhere, and the
     * owner should see they lost business by not answering.
     */
    public function expire(Reservation $reservation): Reservation
    {
        $expired = DB::transaction(function () use ($reservation) {
            $fresh = Reservation::whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->status->awaitsOwner()) {
                return $fresh;
            }

            $fresh->forceFill(['status' => ReservationStatus::Expired])->save();

            // The Owner never answered. The customer is owed everything back --
            // they should not be out of pocket for a venue's silence.
            $this->settlement->release($fresh, WalletHold::REASON_EXPIRED);

            $this->audit->system(
                AuditLogger::RESERVATION_EXPIRED,
                $fresh,
                ['reference' => $fresh->reference],
            );

            return $fresh;
        });

        if ($expired->status === ReservationStatus::Expired && $expired->wasChanged()) {
            $expired->load('spot', 'business.owner', 'user');
            $expired->user->notify(new ReservationExpired($expired));
            $expired->business->owner->notify(new ReservationExpired($expired));
        }

        return $expired;
    }

    /**
     * FR-4.10 -- a confirmed booking whose end time has passed becomes
     * `completed`, unless the Owner flagged a no-show first.
     *
     * Silent by design: nobody needs a notification saying an evening they
     * already played went ahead as planned.
     */
    public function complete(Reservation $reservation): Reservation
    {
        return DB::transaction(function () use ($reservation) {
            $fresh = Reservation::whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== ReservationStatus::Confirmed || ! $fresh->hasEnded()) {
                return $fresh;
            }

            $fresh->forceFill(['status' => ReservationStatus::Completed])->save();

            return $fresh;
        });
    }

    /**
     * FR-2.8 / SRS 9.12 -- the Owner reports the customer never arrived.
     *
     * Self-reported, since there is no check-in or payment system in V1. The
     * counter on the user is what makes it a deterrent: owners see it when
     * reviewing that customer's future requests.
     *
     * @throws BookingException
     */
    public function flagNoShow(Reservation $reservation, User $actor): Reservation
    {
        $flagged = DB::transaction(function () use ($reservation, $actor) {
            $fresh = Reservation::whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            // Only a confirmed booking that has already ended can be a no-show.
            // Flagging one that has not happened yet would unfairly mark a
            // customer who still has time to turn up.
            if ($fresh->status !== ReservationStatus::Confirmed || ! $fresh->hasEnded()) {
                throw new BookingException(
                    'A no-show can only be recorded after a confirmed booking has ended.',
                );
            }

            $fresh->forceFill([
                'status' => ReservationStatus::NoShow,
                'no_show_flagged_at' => now(),
                'no_show_flagged_by' => $actor->id,
            ])->save();

            // The venue held the slot and turned other customers away, so they
            // keep the full amount. This is the first time no_show_count has
            // had any financial weight behind it -- in V1 it was the only
            // deterrent available precisely because no money changed hands.
            $split = $this->settlement->settle($fresh, CancellationEvent::NoShow);

            $fresh->forceFill([
                'refund_amount_minor' => $split['user_minor'],
                'owner_settlement_minor' => $split['owner_minor'],
            ])->save();

            // Atomic increment, not read-modify-write: two owners flagging the
            // same customer at once must not lose a count.
            $fresh->user()->increment('no_show_count');

            $this->audit->log(
                AuditLogger::RESERVATION_NO_SHOW,
                $fresh,
                meta: ['reference' => $fresh->reference, 'customer_id' => $fresh->user_id],
                actor: $actor,
            );

            return $fresh;
        });

        $flagged->load('spot', 'business', 'user');
        $flagged->user->notify(new ReservationNoShow($flagged));

        return $flagged;
    }
}
