<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    /**
     * Ownership runs through the venue, and a policy is handed whatever the
     * router resolved -- for a route-bound reservation, the row on its own.
     * Load the venue here rather than trust the caller to have done it:
     * authorization that depends on someone else's eager loads is a policy that
     * fails open the first time a new controller forgets.
     *
     * No view calls `@can`, so this runs once per request, never in a loop.
     */
    private function ownsVenue(User $user, Reservation $reservation): bool
    {
        return $user->id === $reservation->loadMissing('business')->business->owner_id;
    }

    /** Both sides of a booking can see it. */
    public function view(User $user, Reservation $reservation): bool
    {
        return $user->id === $reservation->user_id
            || $this->ownsVenue($user, $reservation);
    }

    /**
     * Only the venue's Owner decides on a request (FR-4.6), and only while it
     * is still pending. Re-approving a cancelled booking would resurrect a slot
     * the customer has already walked away from.
     */
    public function respond(User $user, Reservation $reservation): bool
    {
        return $this->ownsVenue($user, $reservation)
            && $reservation->status->awaitsOwner();
    }

    /**
     * FR-4.9: the customer may cancel. The Owner may too (SRS 9.4), with a
     * mandatory reason, since owner-side cancellations reflect on the venue.
     */
    public function cancel(User $user, Reservation $reservation): bool
    {
        return $reservation->canBeCancelledBy($user);
    }

    /**
     * FR-2.8: only the Owner flags a no-show, and only once the booked time has
     * actually passed -- flagging a booking that has not happened yet is
     * meaningless and would unfairly mark the customer.
     */
    public function flagNoShow(User $user, Reservation $reservation): bool
    {
        return $this->ownsVenue($user, $reservation)
            && $reservation->isConfirmed()
            && $reservation->hasEnded();
    }

    /** Resolving a conflict raised against this booking (SRS 9.6). */
    public function resolveConflict(User $user, Reservation $reservation): bool
    {
        return $this->ownsVenue($user, $reservation);
    }
}
