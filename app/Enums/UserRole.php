<?php

namespace App\Enums;

/**
 * One account holds exactly one role (SRS 9.14, decided for V1).
 *
 * An Owner cannot submit reservations at all -- not even at venues they do not
 * own. An owner who wants to play elsewhere registers a separate User account.
 * This keeps every authorisation check a single comparison and removes the
 * "am I acting as a customer right now?" ambiguity entirely.
 */
enum UserRole: string
{
    case User = 'user';
    case Owner = 'owner';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Customer',
            self::Owner => 'Business owner',
            self::Admin => 'Administrator',
        };
    }

    /** Roles a visitor may choose at registration. Admins are seeded, never self-registered (FR-1.3). */
    public static function selfRegisterable(): array
    {
        return [self::User, self::Owner];
    }

    public function canBook(): bool
    {
        return $this === self::User;
    }

    public function homeRoute(): string
    {
        return match ($this) {
            self::User => 'dashboard',
            self::Owner => 'owner.dashboard',
            self::Admin => 'admin.dashboard',
        };
    }
}
