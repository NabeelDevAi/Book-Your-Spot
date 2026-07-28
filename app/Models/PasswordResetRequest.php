<?php

namespace App\Models;

use App\Enums\PasswordResetStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stands in for the emailed reset link, which cannot work in V1 (no mail
 * delivery). An Admin sees the request, issues a temporary password, and the
 * user is forced to change it at next login.
 */
#[Fillable(['user_id', 'submitted_email', 'ip_address'])]
class PasswordResetRequest extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => PasswordResetStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->status === PasswordResetStatus::Open;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', PasswordResetStatus::Open);
    }
}
