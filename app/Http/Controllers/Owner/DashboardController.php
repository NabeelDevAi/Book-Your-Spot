<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ConflictStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationConflict;
use App\Models\Spot;
use App\Services\Booking\AvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * FR-2.6 -- the Owner's daily view: today's bookings, what needs a decision,
 * what's coming, occupancy per spot, and enough history to see a trend.
 *
 * Ordered by what an owner needs first thing in the morning: decisions owed,
 * then today, then everything else.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly AvailabilityService $availability) {}

    public function index(Request $request): View
    {
        $businesses = $request->user()
            ->businesses()
            ->withCount('spots')
            ->orderBy('name')
            ->get();

        $businessIds = $businesses->pluck('id');
        $today = Carbon::today();

        $reservations = fn () => Reservation::query()->whereIn('business_id', $businessIds);

        return view('owner.dashboard', [
            'businesses' => $businesses,

            'todaysBookings' => $reservations()
                ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::Pending])
                ->whereBetween('start_datetime', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
                ->with(['user', 'spot'])
                ->orderBy('start_datetime')
                ->get(),

            'pending' => $reservations()
                ->where('status', ReservationStatus::Pending)
                ->with(['user', 'spot', 'business'])
                // Soonest deadline first -- these are the ones about to expire.
                ->orderBy('response_deadline')
                ->limit(6)
                ->get(),

            'pendingCount' => $reservations()->where('status', ReservationStatus::Pending)->count(),

            'openConflicts' => ReservationConflict::query()
                ->whereHas('reservation', fn ($q) => $q->whereIn('business_id', $businessIds))
                ->where('status', ConflictStatus::Open)
                ->count(),

            'upcomingCount' => $reservations()
                ->where('status', ReservationStatus::Confirmed)
                ->where('start_datetime', '>', now())
                ->count(),

            'completedThisMonth' => $reservations()
                ->whereIn('status', [ReservationStatus::Completed, ReservationStatus::NoShow])
                ->where('start_datetime', '>=', $today->copy()->startOfMonth())
                ->count(),

            // Shown to the owner rather than buried in an admin report: these
            // are the numbers they can actually act on.
            'noShowsThisMonth' => $reservations()
                ->where('status', ReservationStatus::NoShow)
                ->where('start_datetime', '>=', $today->copy()->startOfMonth())
                ->count(),

            'occupancy' => $this->occupancyFor($businessIds, $today),
            'today' => $today,
        ]);
    }

    /**
     * Today's occupied intervals per active spot, so the owner can see at a
     * glance which tables are busy and which are sitting empty.
     */
    private function occupancyFor($businessIds, Carbon $date)
    {
        $spots = Spot::query()
            ->whereIn('business_id', $businessIds)
            ->active()
            // Eager-load the venue: effectiveHours() falls back to it, and
            // without this the overview fires one query per spot.
            ->with('business')
            ->orderBy('business_id')
            ->orderBy('sort_order')
            ->get();

        return $this->availability->dayOverview($spots, $date);
    }
}
