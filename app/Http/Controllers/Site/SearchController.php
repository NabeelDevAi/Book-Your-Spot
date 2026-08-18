<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Booking\AvailabilityService;
use App\Services\Search\BusinessSearch;
use App\Support\Ribbon;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * FR-4.1 -- browse and filter venues. Open to guests (SRS 9.13): only the
 * booking action requires an account, and prices are always visible because
 * pricing transparency is a stated goal of the platform.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly BusinessSearch $search,
        private readonly AvailabilityService $availability,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'game' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'date' => ['nullable', 'date', 'after_or_equal:today'],
            'time' => ['nullable', 'date_format:H:i'],
            'duration' => ['nullable', 'integer', 'min:10', 'max:'.config('booking.duration_input_ceiling_minutes')],
        ]);

        $businesses = $this->search->results($filters);

        $date = filled($filters['date'] ?? null)
            ? Carbon::parse($filters['date'])->startOfDay()
            : Carbon::today();

        return view('site.search', [
            'businesses' => $businesses,
            'availability' => $this->availabilityByBusiness($businesses->getCollection(), $date),
            'date' => $date,
            'games' => $this->search->availableGames(),
            'areas' => $this->search->availableAreas(),
            'priceRange' => $this->search->priceRange(),
            'filters' => $filters,
            'hasFilters' => collect($filters)->filter(fn ($v) => filled($v))->isNotEmpty(),
        ]);
    }

    /**
     * Venue-level free windows for the listed page, keyed by business id.
     *
     * Each card carries an availability ribbon, so it needs to know when the
     * venue as a whole is bookable. Two things keep that affordable:
     *
     *   Every spot on the page goes into ONE freeWindowsForMany() call, so the
     *   cost is a constant couple of queries per page rather than per card.
     *   Doing it per business would be 12x the queries for the same answer.
     *
     *   Each spot is handed its parent explicitly. Opening hours fall back to
     *   the venue's, and without this that fallback would lazy-load a business
     *   already in memory -- which preventLazyLoading() turns into a hard
     *   failure in local and testing, by design.
     *
     * @param  Collection<int, Business>  $businesses
     * @return array<int, list<array{start: Carbon, end: Carbon}>>
     */
    private function availabilityByBusiness(Collection $businesses, Carbon $date): array
    {
        $spots = $businesses->flatMap(
            fn (Business $business) => $business->spots
                ->each(fn ($spot) => $spot->setRelation('business', $business))
        );

        if ($spots->isEmpty()) {
            return [];
        }

        $freeBySpot = $this->availability->freeWindowsForMany($spots, $date);

        return $businesses
            ->mapWithKeys(fn (Business $business) => [
                $business->id => Ribbon::merge(
                    $business->spots
                        ->map(fn ($spot) => $freeBySpot[$spot->id] ?? [])
                        ->all()
                ),
            ])
            ->all();
    }
}
