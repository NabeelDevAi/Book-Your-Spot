<?php

namespace App\Policies;

use App\Models\Spot;
use App\Models\User;

class SpotPolicy
{
    /**
     * Ownership runs through the venue, and a route-bound spot arrives without
     * it. Load it here rather than trust every controller to remember -- see
     * ReservationPolicy::ownsVenue() for why authorization must not depend on
     * the caller's eager loads.
     */
    private function ownsVenue(User $user, Spot $spot): bool
    {
        return $user->ownsBusiness($spot->loadMissing('business')->business);
    }

    public function view(User $user, Spot $spot): bool
    {
        return $this->ownsVenue($user, $spot);
    }

    public function create(User $user, Spot $spot): bool
    {
        return $this->ownsVenue($user, $spot);
    }

    public function update(User $user, Spot $spot): bool
    {
        return $this->ownsVenue($user, $spot);
    }

    /**
     * SRS 9.9: a hard delete is only ever permitted when the Spot has never
     * held a reservation. Everything else is a deactivation.
     */
    public function delete(User $user, Spot $spot): bool
    {
        return $this->ownsVenue($user, $spot)
            && $spot->canBeHardDeleted();
    }

    /** Deactivating is always available to the Owner -- history is preserved. */
    public function deactivate(User $user, Spot $spot): bool
    {
        return $this->ownsVenue($user, $spot);
    }

    /** Blocking out time on the spot (FR-2.9). */
    public function block(User $user, Spot $spot): bool
    {
        return $this->ownsVenue($user, $spot);
    }
}
