<?php

namespace App\Enums;

/**
 * Predefined rejection reasons (SRS 9.19).
 *
 * Owners get one-click reasons so they aren't forced to type an explanation
 * every time, while Users still receive context instead of a bare "rejected".
 * Free text remains available alongside the code.
 */
enum RejectionReason: string
{
    case SlotTaken = 'slot_taken';
    case SlotUnavailable = 'slot_unavailable';
    case FullyBooked = 'fully_booked';
    case Maintenance = 'maintenance';
    case VenueUnavailable = 'venue_unavailable';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SlotTaken => 'Slot taken by another booking',
            self::SlotUnavailable => 'Slot unavailable',
            self::FullyBooked => 'Fully booked',
            self::Maintenance => 'Closed for maintenance',
            self::VenueUnavailable => 'Venue temporarily unavailable',
            self::Other => 'Other',
        };
    }

    /** What the User sees on their booking history. */
    public function userMessage(): string
    {
        return match ($this) {
            self::SlotTaken => 'This slot was confirmed for another booking first.',
            self::SlotUnavailable => 'The venue could not offer this slot.',
            self::FullyBooked => 'The venue is fully booked at this time.',
            self::Maintenance => 'The spot is closed for maintenance.',
            self::VenueUnavailable => 'The venue is temporarily unavailable.',
            self::Other => 'The venue declined this request.',
        };
    }

    /**
     * Reasons an Owner may pick manually.
     *
     * SlotTaken and VenueUnavailable are excluded: those are set by the system
     * when an approval displaces competing requests, or when Admin suspends a
     * Business. Letting an Owner choose them by hand would muddy the audit trail.
     */
    public static function ownerSelectable(): array
    {
        return [
            self::SlotUnavailable,
            self::FullyBooked,
            self::Maintenance,
            self::Other,
        ];
    }

    /** Whether this reason reflects on the Owner's reliability metrics. */
    public function countsAgainstOwner(): bool
    {
        return ! in_array($this, [self::SlotTaken, self::VenueUnavailable], true);
    }
}
