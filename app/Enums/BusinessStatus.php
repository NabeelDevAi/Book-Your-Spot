<?php

namespace App\Enums;

/**
 * Business lifecycle (SRS FR-2.2, FR-3.1, FR-3.2).
 *
 * Every new Business lands in PendingReview and stays invisible to Users until
 * an Admin approves it. Suspension is reversible; rejection is a dead end the
 * Owner must resubmit from.
 */
enum BusinessStatus: string
{
    case PendingReview = 'pending_review';
    case Active = 'active';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            self::Active => 'Active',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * Whether the Business may appear in User-facing search.
     *
     * Note this is necessary but NOT sufficient -- SRS 9.17 additionally
     * requires at least one active Spot, which is enforced by a query scope.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Active;
    }

    /** Whether new reservation requests may be accepted. */
    public function acceptsBookings(): bool
    {
        return $this === self::Active;
    }

    public function badge(): string
    {
        return match ($this) {
            self::PendingReview => 'warning',
            self::Active => 'success',
            self::Rejected, self::Suspended => 'danger',
        };
    }
}
