<?php

namespace App\Http\Controllers\Owner;

use App\Enums\RejectionReason;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Reservation;
use App\Services\Booking\ReservationService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * FR-2.7 -- the Owner's reservation queue, and FR-4.6 -- responding to requests.
 *
 * This is the screen owners use every day, so the pending queue leads and
 * approve/reject are one click from it (NFR-5).
 */
class ReservationController extends Controller
{
    public function __construct(private readonly ReservationService $reservations) {}

    public function index(Request $request): View
    {
        $businesses = $request->user()->businesses()->orderBy('name')->get();

        $filters = $request->validate([
            'business' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'spot' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = Reservation::query()
            ->whereIn('business_id', $businesses->pluck('id'))
            ->with(['user', 'spot.businessGame.game', 'business'])
            ->withCount(['conflicts as open_conflicts_count' => fn ($q) => $q->where('status', 'open')])
            ->when(filled($filters['business'] ?? null), fn (Builder $q) => $q->where('business_id', $filters['business']))
            ->when(filled($filters['spot'] ?? null), fn (Builder $q) => $q->where('spot_id', $filters['spot']))
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->where('start_datetime', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when(filled($filters['to'] ?? null), fn (Builder $q) => $q->where('start_datetime', '<=', Carbon::parse($filters['to'])->endOfDay()));

        // Pending first, then soonest: the owner's job is to clear decisions,
        // and a request whose slot is tomorrow is more urgent than one next month.
        $query->orderByRaw("FIELD(status, 'pending') DESC")->orderBy('start_datetime');

        return view('owner.reservations.index', [
            'reservations' => $query->paginate(25)->withQueryString(),
            'businesses' => $businesses,
            'spots' => $this->spotsFor($businesses, $filters['business'] ?? null),
            'statuses' => ReservationStatus::cases(),
            'filters' => $filters,
            'pendingCount' => Reservation::whereIn('business_id', $businesses->pluck('id'))
                ->where('status', ReservationStatus::Pending)->count(),
        ]);
    }

    public function show(Reservation $reservation): View
    {
        $this->authorize('view', $reservation);

        return view('owner.reservations.show', [
            'reservation' => $reservation->load([
                'user', 'spot.businessGame.game', 'business',
                'conflicts.raiser', 'responder', 'canceller',
            ]),
            'rejectionReasons' => RejectionReason::ownerSelectable(),
            // SRS 9.12: the customer's no-show record is shown at the moment of
            // decision -- without payments, this is the only deterrent there is.
            'customerNoShows' => $reservation->user->no_show_count,
        ]);
    }

    public function approve(Reservation $reservation): RedirectResponse
    {
        $this->authorize('respond', $reservation);

        try {
            $confirmed = $this->reservations->approve($reservation, request()->user());
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        $displaced = Reservation::where('spot_id', $confirmed->spot_id)
            ->where('status', ReservationStatus::Rejected)
            ->where('rejection_reason_code', RejectionReason::SlotTaken)
            ->where('responded_at', '>=', now()->subMinute())
            ->count();

        return back()->with('success', $displaced > 0
            ? "Confirmed. {$displaced} other ".\Illuminate\Support\Str::plural('request', $displaced)
                .' for that slot '.($displaced === 1 ? 'was' : 'were').' declined automatically.'
            : 'Booking confirmed. The customer has been notified.');
    }

    public function reject(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->authorize('respond', $reservation);

        $validated = $request->validate([
            'reason_code' => ['required', 'string'],
            'reason_text' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = RejectionReason::tryFrom($validated['reason_code']);

        // SRS 9.19 offers one-click reasons, but SlotTaken and VenueUnavailable
        // are set by the system. Letting an owner pick them by hand would muddy
        // the audit trail and mislead the customer about what happened.
        if (! $reason || ! in_array($reason, RejectionReason::ownerSelectable(), true)) {
            return back()->withErrors(['reason_code' => 'Choose a valid reason.']);
        }

        try {
            $this->reservations->reject($reservation, $request->user(), $reason, $validated['reason_text'] ?? null);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Request declined. The customer has been notified.');
    }

    /** SRS 9.4 -- owner-side cancellation, with a mandatory reason. */
    public function cancel(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->authorize('cancel', $reservation);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->reservations->cancel($reservation, $request->user(), $validated['reason']);

        return back()->with('success', 'Booking cancelled. The customer has been notified.');
    }

    /** FR-2.8 -- self-reported, since V1 has no check-in or payment. */
    public function noShow(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->authorize('flagNoShow', $reservation);

        try {
            $this->reservations->flagNoShow($reservation, $request->user());
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Recorded as a no-show.');
    }

    private function spotsFor($businesses, ?int $businessId)
    {
        return \App\Models\Spot::query()
            ->whereIn('business_id', $businessId ? [$businessId] : $businesses->pluck('id'))
            ->orderBy('name')
            ->get(['id', 'name', 'business_id']);
    }
}
