<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only moderation trail (NFR-6, SRS 9.20). Never updated, never deleted.
 */
#[Fillable([
    'actor_id', 'actor_role', 'action', 'target_type', 'target_id',
    'reason', 'meta', 'ip_address',
])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return [
            'actor_role' => UserRole::class,
            'meta' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Actions taken by the scheduler rather than a person. */
    public function isSystemAction(): bool
    {
        return $this->actor_id === null;
    }

    public function actorLabel(): string
    {
        return $this->isSystemAction()
            ? 'System'
            : ($this->actor?->name ?? 'Deleted user');
    }

    public function scopeForTarget(Builder $query, string $type, int $id): Builder
    {
        return $query->where('target_type', $type)->where('target_id', $id);
    }

    public function scopeAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }
}
