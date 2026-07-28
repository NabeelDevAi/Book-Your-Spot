<?php

namespace App\Models;

use App\Casts\OperatingHoursCast;
use App\Enums\BusinessStatus;
use App\Enums\SpotStatus;
use App\Support\OperatingHours;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'description', 'address', 'city', 'area', 'contact_number', 'operating_hours',
])]
class Business extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => BusinessStatus::class,
            'operating_hours' => OperatingHoursCast::class,
            'reviewed_at' => 'datetime',
            'duplicate_flagged' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Business $business) {
            $business->slug ??= static::uniqueSlug($business->name);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function businessGames(): HasMany
    {
        return $this->hasMany(BusinessGame::class);
    }

    public function spots(): HasMany
    {
        return $this->hasMany(Spot::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(BusinessImage::class)->orderBy('sort_order');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        return $this->status === BusinessStatus::Active;
    }

    public function isPendingReview(): bool
    {
        return $this->status === BusinessStatus::PendingReview;
    }

    public function isSuspended(): bool
    {
        return $this->status === BusinessStatus::Suspended;
    }

    public function acceptsBookings(): bool
    {
        return $this->status->acceptsBookings();
    }

    public function hours(): OperatingHours
    {
        return $this->operating_hours ?? OperatingHours::empty();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', BusinessStatus::Active);
    }

    /**
     * SRS 9.17: an active Business with nothing bookable must not appear in
     * search, because there is no way for a User to act on it.
     */
    public function scopeBookable(Builder $query): Builder
    {
        return $query->active()->whereHas('spots', function (Builder $spots) {
            $spots->where('status', SpotStatus::Active);
        });
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('owner_id', $user->id);
    }

    public function scopeInArea(Builder $query, ?string $area): Builder
    {
        return $area ? $query->where('area', $area) : $query;
    }

    /**
     * Slugs must stay unique across soft-deleted rows too, since the unique
     * index does not know about deleted_at.
     */
    protected static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'venue';
        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
