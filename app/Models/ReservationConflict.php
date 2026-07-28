<?php

namespace App\Models;

use App\Enums\ConflictResolution;
use App\Enums\ConflictSource;
use App\Enums\ConflictStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A live booking disrupted by something the venue did (SRS 9.6 / 9.8 / 9.9).
 *
 * Owner-caused clashes stay Open until the Owner talks to the customer and
 * records an outcome; a business suspension resolves itself immediately by
 * cancelling. See ConflictSource for why the two differ.
 */
#[Fillable(['reservation_id', 'source_type', 'source_id', 'raised_by'])]
class ReservationConflict extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'source_type' => ConflictSource::class,
            'status' => ConflictStatus::class,
            'resolution' => ConflictResolution::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->status === ConflictStatus::Open;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ConflictStatus::Open);
    }

    /** The Owner's "needs your attention" queue for one venue. */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->whereHas('reservation', function (Builder $reservation) use ($businessId) {
            $reservation->where('business_id', $businessId);
        });
    }
}
