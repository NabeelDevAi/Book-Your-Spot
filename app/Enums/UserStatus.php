<?php

namespace App\Enums;

/**
 * Account standing. Admin may suspend a User or Owner for abuse (FR-3.4) --
 * repeat no-shows, spam reservations, fraudulent listings.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }
}
