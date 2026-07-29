<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'no_show_count' => 'integer',
            'must_change_password' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /** Businesses this Owner runs. One account may run several (FR-1.7). */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'owner_id');
    }

    /** Reservations this User has requested. */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function passwordResetRequests(): HasMany
    {
        return $this->hasMany(PasswordResetRequest::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    /**
     * This account's money. Created on first touch by WalletService, so it may
     * legitimately be null for an account that has never transacted -- read it
     * through `WalletService::for()` rather than this relation when a wallet is
     * actually required.
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Spendable balance in paisa, safe to call from a view.
     *
     * `loadMissing` rather than reading `$this->wallet` directly: the topbar
     * renders this on every authenticated page, and an implicit lazy load there
     * trips `preventLazyLoading()` in local and testing and costs a query per
     * request in production. An explicit load is permitted by the guard and
     * resolves once per request.
     *
     * Returns 0 for an account that has never transacted -- a wallet is created
     * on first touch by WalletService, not at registration.
     */
    public function walletAvailableMinor(): int
    {
        $this->loadMissing('wallet');

        return $this->wallet?->availableMinor() ?? 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Role & status
    |--------------------------------------------------------------------------
    | One account holds exactly one role (SRS 9.14), so these are simple
    | comparisons rather than a permission lookup.
    */

    public function isUser(): bool
    {
        return $this->role === UserRole::User;
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    /**
     * Whether this account may submit reservations.
     *
     * Owners are blocked outright -- they use the spot-block feature for
     * personal use of their own venue, and register a separate customer
     * account to play elsewhere.
     */
    public function canBook(): bool
    {
        return $this->role->canBook() && $this->isActive();
    }

    /** Does this Owner own the given Business? */
    public function ownsBusiness(Business $business): bool
    {
        return $this->id === $business->owner_id;
    }

    /**
     * SRS 9.12: surfaced to Owners at approval time. Without a payment penalty,
     * a visible no-show record is the only deterrent V1 has.
     */
    public function isRepeatNoShow(): bool
    {
        return $this->no_show_count >= config('booking.no_show_warning_threshold');
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return match (count($words)) {
            0 => '?',
            1 => mb_strtoupper(mb_substr($words[0], 0, 2)),
            default => mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr(end($words), 0, 1)),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeRole(Builder $query, UserRole $role): Builder
    {
        return $query->where('role', $role);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active);
    }

    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('role', UserRole::User);
    }

    public function scopeOwners(Builder $query): Builder
    {
        return $query->where('role', UserRole::Owner);
    }
}
