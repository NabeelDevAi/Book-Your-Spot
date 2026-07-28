<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ConflictResolution;
use App\Enums\ConflictStatus;
use App\Http\Controllers\Controller;
use App\Models\ReservationConflict;
use App\Services\Booking\ConflictService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SRS 9.6 -- the queue of bookings disrupted by something the venue did.
 *
 * Blocking a spot or taking one out of service does NOT cancel the bookings
 * underneath it. Each one lands here for the Owner to contact the customer and
 * record what was agreed. That is the whole point: silently cancelling a
 * confirmed customer is the trust-breaking failure the SRS explicitly names.
 */
class ConflictController extends Controller
{
    public function __construct(private readonly ConflictService $conflicts) {}

    public function index(Request $request): View
    {
        $businessIds = $request->user()->businesses()->pluck('id');

        $query = ReservationConflict::query()
            ->whereHas('reservation', fn ($q) => $q->whereIn('business_id', $businessIds))
            ->with(['reservation.user', 'reservation.spot', 'reservation.business', 'raiser', 'resolver'])
            // Open first, then oldest: a clash left unresolved gets worse as the
            // booking approaches.
            ->orderByRaw("FIELD(status, 'open') DESC")
            ->orderBy('created_at');

        return view('owner.conflicts.index', [
            'conflicts' => $query->paginate(25),
            'openCount' => ReservationConflict::whereHas(
                'reservation',
                fn ($q) => $q->whereIn('business_id', $businessIds)
            )->where('status', ConflictStatus::Open)->count(),
            'resolutions' => ConflictResolution::cases(),
        ]);
    }

    public function resolve(Request $request, ReservationConflict $conflict): RedirectResponse
    {
        $conflict->loadMissing('reservation.business');

        $this->authorize('resolveConflict', $conflict->reservation);

        if (! $conflict->isOpen()) {
            return back()->with('error', 'That clash has already been resolved.');
        }

        $validated = $request->validate([
            'resolution' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $resolution = ConflictResolution::tryFrom($validated['resolution']);

        if (! $resolution) {
            return back()->withErrors(['resolution' => 'Choose how this was resolved.']);
        }

        $this->conflicts->resolve($conflict, $resolution, $request->user(), $validated['note'] ?? null);

        return back()->with('success', match ($resolution) {
            ConflictResolution::Cancelled => 'Booking cancelled and the customer notified.',
            ConflictResolution::Kept => 'Marked as honoured — remember to remove the block if it still clashes.',
            ConflictResolution::RescheduledOffline => 'Recorded as rescheduled with the customer.',
        });
    }
}
