<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Owner-declared downtime on a Spot (FR-2.9): maintenance, a private event, or
 * the Owner's own use of their table. Renders as unavailable to Users without
 * masquerading as a customer booking.
 */
#[Fillable(['spot_id', 'start_datetime', 'end_datetime', 'reason'])]
class SpotBlock extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
        ];
    }

    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function durationMinutes(): int
    {
        return (int) $this->start_datetime->diffInMinutes($this->end_datetime);
    }

    /**
     * Half-open interval overlap: [start, end).
     *
     * A booking ending exactly when a block begins does NOT overlap, which is
     * what makes back-to-back slots work at all.
     */
    public function scopeOverlapping(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->where('start_datetime', '<', $end)
            ->where('end_datetime', '>', $start);
    }

    public function scopeForSpot(Builder $query, int $spotId): Builder
    {
        return $query->where('spot_id', $spotId);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('end_datetime', '>', now());
    }
}
