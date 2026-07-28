<?php

namespace App\Enums;

/**
 * How an Owner settled a clash between a booking and a block/deactivation.
 *
 * "Kept" means the Owner decided the customer's booking wins and withdrew the
 * block instead -- the honest outcome when the Owner blocked a spot without
 * checking what was already on it.
 */
enum ConflictResolution: string
{
    case Cancelled = 'cancelled';
    case Kept = 'kept';
    case RescheduledOffline = 'rescheduled_offline';

    public function label(): string
    {
        return match ($this) {
            self::Cancelled => 'Booking cancelled',
            self::Kept => 'Booking honoured',
            self::RescheduledOffline => 'Rescheduled with the customer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Cancelled => 'The reservation was cancelled and the customer notified.',
            self::Kept => 'The block was withdrawn and the reservation stands.',
            self::RescheduledOffline => 'A new time was agreed with the customer directly.',
        };
    }
}
