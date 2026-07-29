<?php

namespace App\Models;

use App\Enums\HoldStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Funds reserved against one reservation.
 *
 * Resolved exactly once, either by capture (the Owner approved) or release
 * (rejected, expired, cancelled, or lost the race to another customer).
 */
#[Fillable(['wallet_id', 'reservation_id', 'amount_minor'])]
class WalletHold extends Model
{
    /**
     * As on Wallet: the migration's default only fires for database-side
     * inserts, so a newly created hold would carry a null status and every
     * `$hold->status->isResolved()` call would fatal.
     */
    protected $attributes = [
        'status' => HoldStatus::Active->value,
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => HoldStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** Reasons a hold is let go. Recorded so support can answer "why?". */
    public const REASON_REJECTED = 'rejected';

    public const REASON_EXPIRED = 'expired';

    public const REASON_CANCELLED = 'cancelled';

    public const REASON_SLOT_TAKEN = 'slot_taken';

    public const REASON_ADMIN = 'admin';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        return $this->status === HoldStatus::Active;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', HoldStatus::Active->value);
    }

    public function scopeForWallet(Builder $query, int $walletId): Builder
    {
        return $query->where('wallet_id', $walletId);
    }
}
