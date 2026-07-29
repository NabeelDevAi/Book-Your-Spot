<?php

namespace App\Models;

use App\Enums\TopupStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to move money into a wallet.
 *
 * The actual credit lives in `wallet_transactions`; this row is the evidence
 * for why that credit exists. `gateway` records which provider produced it, so
 * once a real one is introduced, simulated history stays distinguishable from
 * real money at a glance.
 */
#[Fillable(['user_id', 'amount_minor', 'gateway_reference'])]
class Topup extends Model
{
    use HasFactory;

    protected $attributes = [
        'status' => TopupStatus::Pending->value,
        'gateway' => 'simulated',
    ];

    /** True while payments are standing in rather than really being taken. */
    public function isSimulated(): bool
    {
        return $this->gateway === 'simulated';
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => TopupStatus::class,
            'succeeded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The ledger row this top-up produced, if it got that far. */
    public function transaction(): ?WalletTransaction
    {
        return WalletTransaction::where('topup_id', $this->getKey())->first();
    }

    public function amount(): string
    {
        return Money::pkrMinor($this->amount_minor);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', TopupStatus::Pending->value);
    }

    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', TopupStatus::Succeeded->value);
    }

    /**
     * Pending long enough that a webhook should have arrived. Drives the
     * reconcile sweep.
     */
    public function scopeStale(Builder $query, int $minutes = 30): Builder
    {
        return $query->pending()->where('created_at', '<', now()->subMinutes($minutes));
    }
}
