<?php

namespace App\Services\Search;

use App\Enums\SpotStatus;
use App\Models\Business;
use App\Models\Game;
use App\Models\Spot;
use App\Services\Booking\AvailabilityService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * FR-4.1 -- customer-facing venue search.
 *
 * Category, area and price narrow the query in SQL. The availability filter
 * cannot: opening hours live in a JSON column and involve overnight ranges, so
 * expressing "open at 8pm next Saturday" in SQL would mean either shadow
 * columns kept in sync by hand or a query nobody can maintain.
 *
 * Instead availability is resolved in PHP over the already-narrowed candidate
 * set. At the stated scale (NFR-3: a few hundred to low thousands of venues in
 * one city) that is a handful of milliseconds. It would need rethinking as a
 * materialised availability table an order of magnitude beyond that -- noted
 * here so the tradeoff is visible rather than discovered later.
 */
class BusinessSearch
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @param  array{
     *     game?: string|null, area?: string|null, q?: string|null,
     *     min_price?: numeric-string|null, max_price?: numeric-string|null,
     *     date?: string|null, time?: string|null, duration?: int|null,
     * }  $filters
     */
    public function results(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        $query = $this->baseQuery($filters);

        if (! $this->hasAvailabilityFilter($filters)) {
            return $query->paginate($perPage)->withQueryString();
        }

        return $this->paginateByAvailability($query, $filters, $perPage);
    }

    /**
     * SRS 9.17 is enforced here, not in the view: a venue is only visible if it
     * is active AND has at least one active spot. An approved venue with nothing
     * bookable is a dead end for the customer.
     */
    private function baseQuery(array $filters): Builder
    {
        return Business::query()
            ->bookable()
            ->with([
                'images',
                'businessGames.game',
                'spots' => fn ($q) => $q->active()->orderBy('price_amount'),
            ])
            ->when(
                filled($filters['game'] ?? null),
                fn (Builder $q) => $q->whereHas(
                    'businessGames.game',
                    fn (Builder $g) => $g->where('slug', $filters['game'])
                )
            )
            ->when(
                filled($filters['area'] ?? null),
                fn (Builder $q) => $q->where('area', $filters['area'])
            )
            ->when(filled($filters['q'] ?? null), function (Builder $q) use ($filters) {
                $term = '%'.trim((string) $filters['q']).'%';
                $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('area', 'like', $term)
                    ->orWhere('description', 'like', $term));
            })
            // Price filters compare against the venue's cheapest active spot, so
            // a venue with one affordable table isn't hidden by its premium one.
            ->when(
                filled($filters['min_price'] ?? null) || filled($filters['max_price'] ?? null),
                fn (Builder $q) => $q->whereHas('spots', fn (Builder $s) => $s
                    ->active()
                    ->priceBetween(
                        filled($filters['min_price'] ?? null) ? (float) $filters['min_price'] : null,
                        filled($filters['max_price'] ?? null) ? (float) $filters['max_price'] : null,
                    ))
            )
            ->orderBy('name');
    }

    private function hasAvailabilityFilter(array $filters): bool
    {
        return filled($filters['date'] ?? null);
    }

    /**
     * Resolve availability in PHP, then paginate the surviving venues by hand.
     *
     * Paginating in SQL first would be wrong: a page of 12 venues might contain
     * only 3 that are actually free, and the customer would see a near-empty
     * page followed by more pages of the same.
     */
    private function paginateByAvailability(Builder $query, array $filters, int $perPage): LengthAwarePaginator
    {
        $date = Carbon::parse($filters['date'])->startOfDay();
        $time = $filters['time'] ?? null;
        $duration = (int) ($filters['duration'] ?? 60);

        $matching = $query->get()->filter(
            fn (Business $business) => $this->hasAvailability($business, $date, $time, $duration)
        )->values();

        $page = Paginator::resolveCurrentPage();

        return new Paginator(
            $matching->forPage($page, $perPage)->values(),
            $matching->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /** Whether any active spot at this venue can take the requested booking. */
    private function hasAvailability(Business $business, Carbon $date, ?string $time, int $duration): bool
    {
        foreach ($business->spots as $spot) {
            // Hand the spot its parent explicitly. Without this,
            // Spot::effectiveHours() falls back to $this->business->hours() and
            // lazy-loads the venue we are already holding -- one extra query
            // per spot, on the hottest path in the application.
            $spot->setRelation('business', $business);

            if ($this->spotHasAvailability($spot, $date, $time, $duration)) {
                return true;
            }
        }

        return false;
    }

    private function spotHasAvailability(Spot $spot, Carbon $date, ?string $time, int $duration): bool
    {
        // Respect the spot's own minimum -- a 60-minute request against a court
        // with a 90-minute minimum is not availability, it is a dead end.
        // There is no maximum to check: a duration too long for the day simply
        // yields no start times below.
        $effectiveDuration = max($duration, $spot->min_duration_minutes);

        $starts = $this->availability->startTimesFor($spot, $date, $effectiveDuration);

        if ($starts === []) {
            return false;
        }

        if (! filled($time)) {
            return true;
        }

        // With a time given, require a start within the hour requested rather
        // than an exact match -- "around 8pm" is what the customer means.
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $wanted = $date->copy()->setTime($hour, $minute);

        foreach ($starts as $start) {
            if (abs($start->diffInMinutes($wanted, absolute: true)) <= 60) {
                return true;
            }
        }

        return false;
    }

    /** Categories that actually have a bookable venue behind them. */
    public function availableGames(): Collection
    {
        return Game::query()
            ->active()
            ->whereHas('businessGames.business', fn (Builder $b) => $b->bookable())
            ->ordered()
            ->get();
    }

    /** @return Collection<int, string> */
    public function availableAreas(): Collection
    {
        return Business::query()
            ->bookable()
            ->select('area')
            ->distinct()
            ->orderBy('area')
            ->pluck('area');
    }

    /** Bounds for the price filter, so the inputs suggest a sensible range. */
    public function priceRange(): array
    {
        $prices = Spot::query()
            ->active()
            ->whereHas('business', fn (Builder $b) => $b->bookable())
            ->selectRaw('MIN(price_amount) as min_price, MAX(price_amount) as max_price')
            ->first();

        return [
            'min' => (int) floor((float) ($prices->min_price ?? 0)),
            'max' => (int) ceil((float) ($prices->max_price ?? 0)),
        ];
    }
}
