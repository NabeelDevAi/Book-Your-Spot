<?php

namespace App\Models;

use App\Enums\WalletStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single account's money.
 *
 * Read freely from anywhere. WRITE ONLY THROUGH WalletService -- the same rule
 * `ReservationService` holds over `reservations.status`, and for the same
 * reason: a balance changed outside the service has no ledger row behind it,
 * and the reconciliation invariant fails with no way to tell what happened.
 *
 * The three balances are independent pots:
 *
 *   balance_minor  settled funds. A customer spends from here; an Owner
 *                  withdraws from here.
 *   held_minor     reserved against pending reservations. Still inside
 *                  balance_minor, not additional to it.
 *   pending_minor  an Owner's unmatured earnings. Not withdrawable.
 */
#[Fillable(['user_id'])]
class Wallet extends Model
{
    use HasFactory;

    /**
     * Column defaults are declared here as well as in the migration.
     *
     * The database default only applies to rows the database inserts on its
     * own; a freshly created model carries null until it is re-read. Without
     * these, `firstOrCreate()` hands back a wallet whose balances are null and
     * the first arithmetic on it silently produces null rather than a number.
     */
    protected $attributes = [
        'balance_minor' => 0,
        'held_minor' => 0,
        'pending_minor' => 0,
        'status' => WalletStatus::Active->value,
    ];

    protected function casts(): array
    {
        return [
            'balance_minor' => 'integer',
            'held_minor' => 'integer',
            'pending_minor' => 'integer',
            'status' => WalletStatus::class,
            'frozen_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function holds(): HasMany
    {
        return $this->hasMany(WalletHold::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Balances
    |--------------------------------------------------------------------------
    */

    /**
     * What the customer can actually spend right now.
     *
     * Holds sit inside the balance rather than beside it, so this subtracts
     * rather than adds. Showing raw `balance_minor` to a customer with pending
     * bookings would promise money that is already spoken for.
     */
    public function availableMinor(): int
    {
        return $this->balance_minor - $this->held_minor;
    }

    public function hasAvailable(int $minor): bool
    {
        return $this->availableMinor() >= $minor;
    }

    /** Everything an Owner is owed, matured or not. For the earnings header. */
    public function totalOwnedMinor(): int
    {
        return $this->balance_minor + $this->pending_minor;
    }

    public function isFrozen(): bool
    {
        return $this->status === WalletStatus::Frozen;
    }

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    | The cached columns must always equal the ledger that produced them. These
    | recompute from `wallet_transactions` and are what the reconciliation test
    | and the Admin report compare against.
    */

    public function ledgerBalanceMinor(): int
    {
        return (int) $this->transactions()->where('bucket', 'balance')->sum('amount_minor');
    }

    public function ledgerPendingMinor(): int
    {
        return (int) $this->transactions()->where('bucket', 'pending')->sum('amount_minor');
    }

    public function activeHoldsMinor(): int
    {
        return (int) $this->holds()->where('status', 'active')->sum('amount_minor');
    }

    /** True when every cached column agrees with the ledger and the holds. */
    public function reconciles(): bool
    {
        return $this->balance_minor === $this->ledgerBalanceMinor()
            && $this->pending_minor === $this->ledgerPendingMinor()
            && $this->held_minor === $this->activeHoldsMinor();
    }
}
