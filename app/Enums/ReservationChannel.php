<?php

namespace App\Enums;

/**
 * How a booking reached the system.
 *
 * `Online` is every booking a customer made through the platform. The other
 * two are recorded by the Owner on the customer's behalf, for the walk-in and
 * phone-call bookings the platform previously had no way to represent.
 */
enum ReservationChannel: string
{
    case Online = 'online';
    case WalkIn = 'walk_in';
    case Phone = 'phone';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::WalkIn => 'Walk-in',
            self::Phone => 'Phone call',
        };
    }

    /** Channels an Owner may pick when recording a booking by hand. */
    public static function manual(): array
    {
        return [self::WalkIn, self::Phone];
    }
}
