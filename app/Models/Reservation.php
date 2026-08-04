<?php

namespace App\Models;

use App\Enums\RejectionReason;
use App\Enums\ReservationChannel;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable([
    'spot_id', 'user_id', 'business_id', 'start_datetime', 'end_datetime',
    'duration_minutes', 'customer_note', 'customer_name', 'customer_phone', 'channel',
])]
class Reservation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'response_deadline' => 'datetime',
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'no_show_flagged_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'status' => ReservationStatus::class,
            'channel' => ReservationChannel::class,
            'rejection_reason_code' => RejectionReason::class,
            'cancelled_by_role' => UserRole::class,
            'price_amount_snapshot' => 'decimal:2',
            'total_price' => 'decimal:2',
            'duration_minutes' => 'integer',
            'price_unit_minutes_snapshot' => 'integer',
            'is_late_cancellation' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Reservation $reservation) {
            $reservation->reference ??= static::generateReference();
        });
    }

    /**
     * Short code the customer quotes at the venue, e.g. "BYS-8F3K2P".
     *
     * Ambiguous characters (0/O, 1/I) are excluded because this gets read aloud
     * across a counter and typed in by an owner in a hurry.
     */
    public static function generateReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $prefix = config('booking.reference_prefix', 'BYS');

        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $reference = $prefix.'-'.$code;
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function noShowFlagger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'no_show_flagged_by');
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(ReservationConflict::class);
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return $this->status === ReservationStatus::Pending;
    }

    public function isConfirmed(): bool
    {
        return $this->status === ReservationStatus::Confirmed;
    }

    public function hasStarted(): bool
    {
        return $this->start_datetime->isPast();
    }

    /** Recorded by the Owner rather than requested by the customer. */
    public function isManual(): bool
    {
        return $this->channel !== ReservationChannel::Online;
    }

    public function hasEnded(): bool
    {
        return $this->end_datetime->isPast();
    }

    /** Whether the Owner's response window has run out (FR-4.7). */
    public function hasExpired(): bool
    {
        return $this->isPending() && $this->response_deadline->isPast();
    }

    /**
     * SRS 9.3: a cancellation inside the cutoff is still allowed -- there is no
     * payment to forfeit -- but it is flagged so Owners and Admin can see the
     * pattern.
     */
    public function isWithinCancellationCutoff(): bool
    {
        $cutoff = $this->start_datetime->copy()
            ->subHours((int) config('booking.cancellation_cutoff_hours'));

        return now()->greaterThanOrEqualTo($cutoff);
    }

    public function canBeCancelledBy(User $user): bool
    {
        if (! $this->status->isCancellable()) {
            return false;
        }

        // The venue is only consulted for the owner check, which the first two
        // clauses usually short-circuit past. loadMissing keeps this honest when
        // the reservation arrived on its own.
        return $user->id === $this->user_id
            || $user->isAdmin()
            || $user->id === $this->loadMissing('business')->business->owner_id;
    }

    /** An unresolved clash raised by a block, deactivation or suspension. */
    public function hasOpenConflict(): bool
    {
        return $this->conflicts()
            ->where('status', \App\Enums\ConflictStatus::Open)
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Display
    |--------------------------------------------------------------------------
    */

    /**
     * A walk-in or phone booking may have no linked User account -- the Owner
     * recorded whatever the customer gave them at the counter.
     */
    public function customerDisplayName(): string
    {
        return $this->user?->name ?? $this->customer_name ?? 'Walk-in customer';
    }

    public function customerDisplayPhone(): ?string
    {
        return $this->user?->phone ?? $this->customer_phone;
    }

    public function timeRangeLabel(): string
    {
        return $this->start_datetime->format('g:i A').' – '.$this->end_datetime->format('g:i A');
    }

    public function dateLabel(): string
    {
        return $this->start_datetime->format('D, j M Y');
    }

    public function totalPriceLabel(): string
    {
        return Money::pkr($this->total_price);
    }

    public function durationLabel(): string
    {
        return Money::duration($this->duration_minutes);
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Half-open interval overlap: [start, end).
     *
     * Back-to-back bookings must not collide -- a slot ending at 18:00 and
     * another starting at 18:00 do not overlap.
     */
    public function scopeOverlapping(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->where('start_datetime', '<', $end)
            ->where('end_datetime', '>', $start);
    }

    /**
     * Reservations that actually occupy the Spot.
     *
     * Only `confirmed` blocks. Multiple users may hold `pending` requests on
     * the same slot -- the first approval wins and the rest are auto-rejected.
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', ReservationStatus::blockingValues());
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ReservationStatus::openValues());
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ReservationStatus::Pending);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', ReservationStatus::Confirmed);
    }

    public function scopeForSpot(Builder $query, int $spotId): Builder
    {
        return $query->where('spot_id', $spotId);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('start_datetime', '>', now());
    }

    public function scopeOnDate(Builder $query, Carbon $date): Builder
    {
        return $query->whereBetween('start_datetime', [
            $date->copy()->startOfDay(),
            $date->copy()->endOfDay(),
        ]);
    }
}
