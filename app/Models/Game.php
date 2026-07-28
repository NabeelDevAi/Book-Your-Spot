<?php

namespace App\Models;

use App\Enums\GameStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Admin-managed master category (SRS FR-3.3). Owners select from this list and
 * cannot create their own, which keeps the taxonomy from fragmenting.
 */
#[Fillable(['name', 'slug', 'icon', 'description', 'status', 'sort_order'])]
class Game extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Game $game) {
            $game->slug ??= Str::slug($game->name);
        });
    }

    public function businessGames(): HasMany
    {
        return $this->hasMany(BusinessGame::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isActive(): bool
    {
        return $this->status === GameStatus::Active;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', GameStatus::Active);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
