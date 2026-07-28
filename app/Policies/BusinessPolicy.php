<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

/**
 * Admin is handled by the `Gate::before` hook in AppServiceProvider, so these
 * methods only ever describe Owner and Customer access (FR-3.6).
 */
class BusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Business $business): bool
    {
        return $user->ownsBusiness($business);
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Business $business): bool
    {
        return $user->ownsBusiness($business);
    }

    /**
     * A Business is only ever soft-deleted, and only by its Owner while it has
     * no reservation history. Anything with history stays for the record.
     */
    public function delete(User $user, Business $business): bool
    {
        return $user->ownsBusiness($business)
            && ! $business->reservations()->exists();
    }

    /** Manage games, spots, reservations and blocks under this Business. */
    public function manage(User $user, Business $business): bool
    {
        return $user->ownsBusiness($business);
    }

    /**
     * Approval, rejection and suspension are Admin-only (FR-3.1, FR-3.2).
     * Owners never reach these -- Gate::before lets Admin through.
     */
    public function moderate(User $user, Business $business): bool
    {
        return false;
    }
}
