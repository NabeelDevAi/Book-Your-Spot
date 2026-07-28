<?php

namespace App\Enums;

enum ConflictStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Needs resolution',
            self::Resolved => 'Resolved',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Resolved => 'neutral',
        };
    }
}
