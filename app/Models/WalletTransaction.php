<?php

namespace App\Models;

use App\Enums\LedgerBucket;
use App\Enums\WalletTransactionType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ledger row. Written once by WalletService, never updated, never deleted.
 *
 * `$timestamps = false` because the table has no `updated_at` and the
 * `created_at` default comes from the database. Both facts are deliberate: an
 * entry that can be modified after the fact proves nothing in a dispute.
 */
class WalletTransaction extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'bucket' => LedgerBucket::class,
            'type' => WalletTransactionType::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function counterpartyWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'counterparty_wallet_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    public function isCredit(): bool
    {
        return $this->amount_minor > 0;
    }

    /** "+ Rs. 500" / "- Rs. 500", for the statement. */
    public function signedAmount(): string
    {
        return ($this->isCredit() ? '+ ' : '- ').Money::pkrMinor(abs($this->amount_minor));
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeForBucket(Builder $query, LedgerBucket $bucket): Builder
    {
        return $query->where('bucket', $bucket->value);
    }

    public function scopeOfType(Builder $query, WalletTransactionType ...$types): Builder
    {
        return $query->whereIn('type', array_map(fn ($t) => $t->value, $types));
    }

    /** Newest first -- the order every statement is read in. */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
