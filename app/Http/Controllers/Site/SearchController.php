<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Search\BusinessSearch;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FR-4.1 -- browse and filter venues. Open to guests (SRS 9.13): only the
 * booking action requires an account, and prices are always visible because
 * pricing transparency is a stated goal of the platform.
 */
class SearchController extends Controller
{
    public function __construct(private readonly BusinessSearch $search) {}

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
            'duration' => ['nullable', 'integer', 'min:10', 'max:'.config('booking.max_duration_minutes')],
        ]);

        return view('site.search', [
            'businesses' => $this->search->results($filters),
            'games' => $this->search->availableGames(),
            'areas' => $this->search->availableAreas(),
            'priceRange' => $this->search->priceRange(),
            'filters' => $filters,
            'hasFilters' => collect($filters)->filter(fn ($v) => filled($v))->isNotEmpty(),
        ]);
    }
}
