<?php

namespace App\Http\Controllers\Site;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Spot;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\PricingCalculator;
use App\Services\Booking\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly ReservationService $reservations,
        private readonly PricingCalculator $pricing,
    ) {}

    /**
     * FR-4.3 -- the booking form.
     *
     * Reachable by guests so they can see the real prices and times before
     * being asked to sign up. Only submission requires an account (SRS 9.13) --
     * hiding this behind a login wall would make the platform look like every
     * venue it is trying to replace.
     */
    public function create(Request $request, Spot $spot): View
    {
        $spot->load('business', 'businessGame.game', 'images');

        abort_unless($spot->isBookable(), 404);

        $date = $this->resolveDate($request->query('date'));
        $duration = $this->resolveDuration($spot, $date, $request->query('duration'));

        return view('site.book', [
            'spot' => $spot,
            'date' => $date,
            'duration' => $duration,
            'startTimes' => $this->availability->startTimesFor($spot, $date, $duration),
            'freeWindows' => $this->availability->freeWindows($spot, $date),
            'durations' => $spot->allowedDurations($date),
            'priceExplanation' => $this->pricing->explain($spot, $date, $duration),
            'total' => $this->pricing->total($spot, $date, $duration),
            'dateOptions' => $this->dateOptions(),
        ]);
    }

    /**
     * Live availability for the form, so changing the date or duration doesn't
     * cost a page load. Public: it exposes nothing a guest can't already see on
     * the venue page.
     */
    public function slots(Request $request, Spot $spot): JsonResponse
    {
        $spot->loadMissing('business');

        abort_unless($spot->isBookable(), 404);

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'duration' => ['required', 'integer'],
        ]);

        $date = $this->resolveDate($validated['date']);
        $duration = $this->resolveDuration($spot, $date, $validated['duration']);

        return response()->json([
            'duration' => $duration,
            'total' => $this->pricing->total($spot, $date, $duration),
            'total_label' => \App\Support\Money::pkr($this->pricing->total($spot, $date, $duration)),
            'explanation' => $this->pricing->explain($spot, $date, $duration),
            'slots' => array_map(
                fn (Carbon $start) => [
                    'value' => $start->format('Y-m-d H:i'),
                    'label' => $start->format('g:i A'),
                    'ends' => $start->copy()->addMinutes($duration)->format('g:i A'),
                ],
                $this->availability->startTimesFor($spot, $date, $duration),
            ),
        ]);
    }

    /**
     * FR-4.5 -- submit the request.
     *
     * Every rule lives in the engine, not here: BookingException carries a
     * customer-facing message and the field it belongs to, so the same
     * validation protects a future API or an admin acting on someone's behalf.
     */
    public function store(Request $request, Spot $spot): RedirectResponse
    {
        // The engine checks the venue's status as well as the spot's, so give it
        // the venue rather than let every rule that touches it fetch its own.
        $spot->loadMissing('business');

        $validated = $request->validate([
            'start_datetime' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $reservation = $this->reservations->request(
                $spot,
                $request->user(),
                Carbon::parse($validated['start_datetime']),
                (int) $validated['duration_minutes'],
                $validated['note'] ?? null,
            );
        } catch (BookingException $e) {
            return back()->withInput()->withErrors([$e->field => $e->getMessage()]);
        }

        return redirect()
            ->route('bookings.show', $reservation)
            ->with('success', 'Request sent. The venue will confirm shortly.');
    }

    /** The confirmation page a customer lands on, and can return to later. */
    public function show(Request $request, Reservation $reservation): View
    {
        $this->authorize('view', $reservation);

        return view('site.booking', [
            'reservation' => $reservation->load('spot.business', 'spot.businessGame.game', 'business'),
        ]);
    }

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

    /** Snap an arbitrary query value onto a duration this spot actually sells. */
    private function resolveDuration(Spot $spot, Carbon $date, mixed $input): int
    {
        $allowed = $spot->allowedDurations($date);
        $requested = (int) $input;

        if (in_array($requested, $allowed, true)) {
            return $requested;
        }

        // Default to an hour where the spot allows it, rather than the shortest
        // bookable slot. On a snooker table billed in 10-minute blocks the
        // minimum is 30 minutes, and opening the form pre-set to the shortest
        // possible booking is not what most people came to do.
        if (in_array(60, $allowed, true)) {
            return 60;
        }

        return $allowed[0] ?? $spot->min_duration_minutes;
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
