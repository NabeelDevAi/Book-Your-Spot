<?php

namespace App\Enums;

/**
 * V1 sends no email, so the self-serve reset link (FR-1.5) cannot work.
 * A "forgot password" submission instead files a request that appears in the
 * Admin panel; the Admin issues a temporary password and the user is forced
 * to change it at next login.
 */
enum PasswordResetStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Awaiting admin',
            self::Resolved => 'Password reset',
            self::Dismissed => 'Dismissed',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Resolved => 'success',
            self::Dismissed => 'neutral',
        };
    }
}
