<?php

namespace App\Enums;

/**
 * Why a paid booking ended without being played.
 *
 * The distinction drives the whole refund matrix, and the important asymmetry
 * lives here: a customer's refund depends on how close to the start time they
 * pulled out, while an Owner or Admin cancellation always refunds in full
 * regardless of timing.
 *
 * That asymmetry is deliberate and is NOT configurable. If an Owner could
 * cancel a paid booking at no cost, "confirmed" would mean nothing -- they
 * could take a better walk-in at 7:55pm and hand back the money with a shrug.
 */
enum CancellationEvent: string
{
    case CustomerCancelled = 'customer_cancelled';
    case OwnerCancelled = 'owner_cancelled';
    case AdminCancelled = 'admin_cancelled';
    case NoShow = 'no_show';

    /** Whether the timing tiers apply, or the outcome is fixed. */
    public function usesTiers(): bool
    {
        return $this === self::CustomerCancelled;
    }

    /** Reason string recorded on the ledger rows. */
    public function label(): string
    {
        return match ($this) {
            self::CustomerCancelled => 'Cancelled by customer',
            self::OwnerCancelled => 'Cancelled by venue',
            self::AdminCancelled => 'Cancelled by BookYourSpot',
            self::NoShow => 'Customer did not arrive',
        };
    }

    /** Map the acting role onto the right event. */
    public static function forCanceller(UserRole $role): self
    {
        return match ($role) {
            UserRole::User => self::CustomerCancelled,
            UserRole::Owner => self::OwnerCancelled,
            UserRole::Admin => self::AdminCancelled,
        };
    }
}
