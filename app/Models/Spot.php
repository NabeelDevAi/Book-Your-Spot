<?php

namespace App\Models;

use App\Casts\OperatingHoursCast;
use App\Enums\ReservationStatus;
use App\Enums\SpotStatus;
use App\Support\Money;
use App\Support\OperatingHours;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The physical bookable unit -- a table, court or room. Reservations attach here.
 */
#[Fillable([
    'name', 'description', 'price_amount', 'price_unit_minutes',
    'min_duration_minutes', 'max_duration_minutes', 'status',
    'operating_hours_override', 'sort_order',
])]
class Spot extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => SpotStatus::class,
            'price_amount' => 'decimal:2',
            'price_unit_minutes' => 'integer',
            'min_duration_minutes' => 'integer',
            'max_duration_minutes' => 'integer',
            'sort_order' => 'integer',
            'operating_hours_override' => OperatingHoursCast::class,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function businessGame(): BelongsTo
    {
        return $this->belongsTo(BusinessGame::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(SpotImage::class)->orderBy('sort_order');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(SpotBlock::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Hours & pricing
    |--------------------------------------------------------------------------
    */

    /**
     * Effective hours: the Spot's own override if it has one, otherwise the
     * Business default (SRS FR-2.4). A null override means "inherit", which is
     * deliberately different from an all-days-closed override.
     */
    public function effectiveHours(): OperatingHours
    {
        return $this->operating_hours_override ?? $this->business->hours();
    }

    public function hasHoursOverride(): bool
    {
        return $this->operating_hours_override !== null;
    }

    /** Price for a duration, rounded up to whole billing units. */
    public function priceFor(int $durationMinutes): float
    {
        $units = (int) ceil($durationMinutes / $this->price_unit_minutes);

        return round($units * (float) $this->price_amount, 2);
    }

    public function rateLabel(): string
    {
        return Money::rate($this->price_amount, $this->price_unit_minutes);
    }

    /**
     * Durations a User may pick: every multiple of the billing unit between the
     * min and max. Drives the booking form's duration options and the "valid
     * durations" hint on a SRS 9.7 validation failure.
     *
     * @return list<int>
     */
    public function allowedDurations(): array
    {
        $durations = [];
        $unit = $this->price_unit_minutes;

        // Start at the first multiple of the unit that reaches the minimum, so
        // a min of 25 on a 10-minute table yields 30, not 25.
        $start = (int) (ceil($this->min_duration_minutes / $unit) * $unit);

        for ($minutes = $start; $minutes <= $this->max_duration_minutes; $minutes += $unit) {
            $durations[] = $minutes;
        }

        return $durations;
    }

    public function isValidDuration(int $minutes): bool
    {
        return in_array($minutes, $this->allowedDurations(), true);
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        return $this->status === SpotStatus::Active;
    }

    /** Bookable only if the Spot AND its Business are both live. */
    public function isBookable(): bool
    {
        return $this->isActive() && $this->business->acceptsBookings();
    }

    /**
     * SRS 9.9: a hard delete is permitted only when the Spot has never held a
     * reservation. Anything with history is soft-deleted so the record survives.
     */
    public function canBeHardDeleted(): bool
    {
        return ! $this->reservations()->exists();
    }

    /** Future bookings that a deactivation or block would disrupt. */
    public function futureOpenReservations(): HasMany
    {
        return $this->reservations()
            ->whereIn('status', ReservationStatus::openValues())
            ->where('start_datetime', '>', now());
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SpotStatus::Active);
    }

    public function scopePriceBetween(Builder $query, ?float $min, ?float $max): Builder
    {
        return $query
            ->when($min !== null, fn (Builder $q) => $q->where('price_amount', '>=', $min))
            ->when($max !== null, fn (Builder $q) => $q->where('price_amount', '<=', $max));
    }
}
