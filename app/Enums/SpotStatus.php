<?php

namespace App\Enums;

/**
 * Spot availability flag (SRS FR-2.4).
 *
 * Inactive is how an Owner takes a table or court out of service temporarily
 * without deleting it. Deletion is only permitted when a Spot has never held a
 * reservation (SRS 9.9) -- history is never destroyed.
 */
enum SpotStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }

    public function isBookable(): bool
    {
        return $this === self::Active;
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'neutral',
        };
    }
}
