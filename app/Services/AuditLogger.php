<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes the moderation trail (NFR-6, SRS 9.20).
 *
 * Append-only: entries are never updated or deleted, because their entire value
 * is being trustworthy after the fact in a "who changed what" dispute.
 */
class AuditLogger
{
    // Account & moderation
    public const USER_SUSPENDED = 'user.suspended';

    public const USER_REINSTATED = 'user.reinstated';

    public const OWNER_ACCOUNT_APPROVED = 'owner_account.approved';

    public const OWNER_ACCOUNT_REJECTED = 'owner_account.rejected';

    public const PASSWORD_RESET_ISSUED = 'user.password_reset_issued';

    public const PASSWORD_RESET_REQUESTED = 'user.password_reset_requested';

    public const PASSWORD_RESET_DISMISSED = 'user.password_reset_dismissed';

    // Business lifecycle
    public const BUSINESS_APPROVED = 'business.approved';

    public const BUSINESS_REJECTED = 'business.rejected';

    public const BUSINESS_SUSPENDED = 'business.suspended';

    public const BUSINESS_REINSTATED = 'business.reinstated';

    public const BUSINESS_OVERRIDDEN = 'business.overridden';

    // Listings
    public const SPOT_OVERRIDDEN = 'spot.overridden';

    public const SPOT_DEACTIVATED = 'spot.deactivated';

    public const GAME_DEACTIVATED = 'game.deactivated';

    // Reservations
    public const RESERVATION_APPROVED = 'reservation.approved';

    public const RESERVATION_REJECTED = 'reservation.rejected';

    public const RESERVATION_AUTO_REJECTED = 'reservation.auto_rejected';

    public const RESERVATION_EXPIRED = 'reservation.expired';

    public const RESERVATION_CANCELLED = 'reservation.cancelled';

    public const RESERVATION_NO_SHOW = 'reservation.no_show';

    public const RESERVATION_MANUAL_CREATED = 'reservation.manual_created';

    public const CONFLICT_RAISED = 'conflict.raised';

    public const CONFLICT_RESOLVED = 'conflict.resolved';

    /**
     * Record an action against a target model.
     *
     * @param  array<string, mixed>  $meta
     */
    public function log(
        string $action,
        ?Model $target = null,
        ?string $reason = null,
        array $meta = [],
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return AuditLog::create([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role,
            'action' => $action,
            'target_type' => $target ? $target::class : null,
            'target_id' => $target?->getKey(),
            'reason' => $reason,
            'meta' => $meta ?: null,
            'ip_address' => $this->clientIp(),
        ]);
    }

    /**
     * Record an action taken by the scheduler rather than a person -- expiry
     * sweeps, auto-completion, cascade cancellations. Deliberately logs a null
     * actor so "System" is distinguishable from "an admin we failed to record".
     *
     * @param  array<string, mixed>  $meta
     */
    public function system(string $action, ?Model $target = null, array $meta = []): AuditLog
    {
        return AuditLog::create([
            'actor_id' => null,
            'actor_role' => null,
            'action' => $action,
            'target_type' => $target ? $target::class : null,
            'target_id' => $target?->getKey(),
            'reason' => null,
            'meta' => $meta ?: null,
            'ip_address' => null,
        ]);
    }

    /**
     * Record an Admin overriding someone else's data. A reason is mandatory --
     * this is precisely the case SRS 9.20 exists for.
     *
     * @param  array<string, mixed>  $changes
     */
    public function override(string $action, Model $target, string $reason, array $changes = []): AuditLog
    {
        return $this->log($action, $target, $reason, ['changes' => $changes]);
    }

    private function clientIp(): ?string
    {
        // Guard for console contexts (scheduler, seeders) where no request exists.
        return app()->runningInConsole() ? null : Request::ip();
    }
}
