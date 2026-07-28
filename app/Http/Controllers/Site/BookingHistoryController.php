<?php

namespace App\Http\Controllers\Site;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\Booking\ReservationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FR-4.8 -- the customer's bookings, split into upcoming and past.
 */
class BookingHistoryController extends Controller
{
    public function __construct(private readonly ReservationService $reservations) {}

    public function index(Request $request): View
    {
        $base = fn () => $request->user()
            ->reservations()
            ->with(['spot.businessGame.game', 'business']);

        return view('site.bookings', [
            // Awaiting an answer or already confirmed, and still ahead of them.
            'upcoming' => $base()
                ->whereIn('status', ReservationStatus::openValues())
                ->where('end_datetime', '>=', now())
                ->orderBy('start_datetime')
                ->get(),

            'past' => $base()
                ->where(fn ($q) => $q
                    ->whereNotIn('status', ReservationStatus::openValues())
                    ->orWhere('end_datetime', '<', now()))
                ->orderByDesc('start_datetime')
                ->paginate(15),
        ]);
    }

    /**
     * FR-4.9 -- the customer cancels.
     *
     * Always permitted while the booking is live; there is no payment to
     * forfeit in V1. A cancellation inside the cutoff is still allowed but gets
     * flagged, which is the only deterrent available. The flag is computed in
     * the engine, not passed from here.
     */
    public function cancel(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->authorize('cancel', $reservation);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $cancelled = $this->reservations->cancel(
            $reservation,
            $request->user(),
            $validated['reason'] ?? null,
        );

        return redirect()
            ->route('bookings.index')
            ->with(
                $cancelled->is_late_cancellation ? 'warning' : 'success',
                $cancelled->is_late_cancellation
                    ? 'Booking cancelled. Because it was close to the start time, the venue has been told it was a late cancellation.'
                    : 'Booking cancelled and the venue notified.'
            );
    }
}
