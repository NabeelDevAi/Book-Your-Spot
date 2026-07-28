<?php

namespace App\Enums;

/**
 * Master Game category status (SRS FR-3.3).
 *
 * Deactivating a category does not unlink Businesses already offering it --
 * it only prevents new links, so existing Spots keep working.
 */
enum GameStatus: string
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

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'neutral',
        };
    }
}
