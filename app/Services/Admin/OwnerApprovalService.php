<?php

namespace App\Services\Admin;

use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\OwnerAccountApproved;
use App\Notifications\OwnerAccountRejected;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Owner-account approval gate: a new Owner registers `pending_approval` and
 * cannot log in (LoginRequest) until an Admin decides here. Existing Owner
 * accounts predating this feature were left `active` by their migration and
 * never pass through this service at all.
 *
 * Mirrors BusinessModerationService's approve/reject shape deliberately --
 * same two-state decision, same audit trail, same notification pattern.
 */
class OwnerApprovalService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function approve(User $owner, User $admin): User
    {
        DB::transaction(function () use ($owner, $admin) {
            $owner->forceFill([
                'status' => UserStatus::Active,
                'rejection_reason' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->log(AuditLogger::OWNER_ACCOUNT_APPROVED, $owner, actor: $admin);
        });

        $owner->notify(new OwnerAccountApproved);

        return $owner;
    }

    public function reject(User $owner, User $admin, string $reason): User
    {
        DB::transaction(function () use ($owner, $admin, $reason) {
            $owner->forceFill([
                'status' => UserStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->log(AuditLogger::OWNER_ACCOUNT_REJECTED, $owner, $reason, actor: $admin);
        });

        $owner->notify(new OwnerAccountRejected($reason));

        return $owner;
    }
}
