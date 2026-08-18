<?php

namespace App\Enums;

/**
 * Account standing. Admin may suspend a User or Owner for abuse (FR-3.4) --
 * repeat no-shows, spam reservations, fraudulent listings.
 *
 * PendingApproval and Rejected exist only for the Owner role: a new Owner
 * account is not usable until an Admin approves it. Customers and Admins are
 * never anything but Active/Suspended -- they skip the approval gate entirely
 * (RegisteredUserController decides the starting status at registration).
 */
enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case PendingApproval = 'pending_approval';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::PendingApproval => 'Pending approval',
            self::Rejected => 'Rejected',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended, self::Rejected => 'danger',
            self::PendingApproval => 'warning',
        };
    }
}
