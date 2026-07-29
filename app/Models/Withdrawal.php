<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Owner cashing out.
 *
 * State is written only by WithdrawalService, following the same rule
 * ReservationService holds over reservation status.
 */
#[Fillable(['owner_id', 'amount_minor', 'payout_account_snapshot'])]
class Withdrawal extends Model
{
    use HasFactory;

    protected $attributes = [
        'status' => WithdrawalStatus::Requested->value,
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => WithdrawalStatus::class,
            'payout_account_snapshot' => 'array',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Withdrawal $withdrawal) {
            $withdrawal->reference ??= static::generateReference();
            $withdrawal->requested_at ??= now();
        });
    }

    /**
     * "BYSW-8F3K2P". Same alphabet as a booking reference and for the same
     * reason -- it gets read aloud and typed into a banking app, so 0/O and
     * 1/I are excluded.
     */
    public static function generateReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $reference = 'BYSW-'.$code;
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    public function amountLabel(): string
    {
        return Money::pkrMinor($this->amount_minor);
    }

    public function bankName(): string
    {
        return $this->payout_account_snapshot['bank_name'] ?? 'Unknown bank';
    }

    public function accountTitle(): string
    {
        return $this->payout_account_snapshot['account_title'] ?? '';
    }

    public function accountNumber(): string
    {
        return $this->payout_account_snapshot['account_number'] ?? '';
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', WithdrawalStatus::openValues());
    }

    public function scopeForOwner(Builder $query, int $ownerId): Builder
    {
        return $query->where('owner_id', $ownerId);
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }
}
