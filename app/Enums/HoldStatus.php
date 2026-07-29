<?php

namespace App\Enums;

/**
 * Lifecycle of a reservation's funds hold.
 *
 * A hold reserves a customer's money without moving it, which is what lets
 * several customers queue on one slot (ReservationStatus::blocking() excludes
 * `pending`) without anybody being charged for a booking they might lose. The
 * first approval captures; every other hold is released at the same moment
 * `autoRejectCompeting()` rejects its reservation.
 *
 * Both outcomes are terminal -- a hold is resolved exactly once.
 */
enum HoldStatus: string
{
    case Active = 'active';
    case Captured = 'captured';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Captured => 'Captured',
            self::Released => 'Released',
        };
    }

    public function isResolved(): bool
    {
        return $this !== self::Active;
    }

    /** Only an active hold reduces the wallet's available balance. */
    public function reservesFunds(): bool
    {
        return $this === self::Active;
    }
}
