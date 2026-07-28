<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Booking\AvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * FR-4.2 -- venue detail: profile, hours, every category and every spot with
 * live pricing and availability.
 */
class BusinessController extends Controller
{
    public function __construct(private readonly AvailabilityService $availability) {}

    public function show(Request $request, Business $business): View
    {
        // A venue that isn't approved, or has nothing bookable, must 404 rather
        // than render (SRS 9.17). Otherwise a shared link would expose a listing
        // that never passed review.
        abort_unless(
            $business->isActive() && $business->spots()->active()->exists(),
            404,
        );

        $date = $this->resolveDate($request->query('date'));

        $business->load([
            'images',
            'businessGames.game',
            'businessGames.spots' => fn ($q) => $q->active()->with('images')->orderBy('sort_order'),
        ]);

        // Free windows per spot for the chosen day, so the customer can see
        // what's open before committing to a form.
        //
        // Every spot in one call: this page exists to list them all, so asking
        // per spot costs two queries each. Each spot is also handed its parent
        // explicitly -- a spot without an hours override falls back to
        // $this->business->hours(), which would otherwise lazy-load the venue we
        // are already holding.
        $spots = $business->businessGames
            ->flatMap->spots
            ->each(fn ($spot) => $spot->setRelation('business', $business));

        $availability = $this->availability->freeWindowsForMany($spots, $date);

        return view('site.business', [
            'business' => $business,
            'date' => $date,
            'availability' => $availability,
            'dateOptions' => $this->dateOptions(),
        ]);
    }

    /** Clamp to the bookable window; a stale or hand-edited date falls back to today. */
    private function resolveDate(?string $input): Carbon
    {
        $today = Carbon::today();

        if (! $input) {
            return $today;
        }

        try {
            $date = Carbon::parse($input)->startOfDay();
        } catch (\Throwable) {
            return $today;
        }

        $latest = $today->copy()->addDays((int) config('booking.max_advance_days'));

        return $date->betweenIncluded($today, $latest) ? $date : $today;
    }

    /** @return list<Carbon> */
    private function dateOptions(): array
    {
        $days = [];

        for ($i = 0; $i < 14; $i++) {
            $days[] = Carbon::today()->addDays($i);
        }

        return $days;
    }
}
