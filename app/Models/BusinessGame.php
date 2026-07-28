<?php

namespace App\Models;

use App\Enums\SpotStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Business's offering of one Game category. Spots hang off this rather than
 * off the Business directly, mirroring the SRS hierarchy:
 *
 *   Business -> Game -> Spot -> Reservation
 */
#[Fillable(['business_id', 'game_id', 'sort_order'])]
class BusinessGame extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function spots(): HasMany
    {
        return $this->hasMany(Spot::class)->orderBy('sort_order');
    }

    public function activeSpots(): HasMany
    {
        return $this->spots()->where('status', SpotStatus::Active);
    }

    public function scopeForGame(Builder $query, Game $game): Builder
    {
        return $query->where('game_id', $game->id);
    }
}
