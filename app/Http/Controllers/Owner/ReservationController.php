<?php

namespace App\Http\Controllers\Owner;

use App\Enums\RejectionReason;
use App\Enums\ReservationChannel;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Reservation;
use App\Models\Spot;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\PricingCalculator;
use App\Services\Booking\ReservationService;
use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Enum;
use Illuminate\View\View;

/**
 * FR-2.7 -- the Owner's reservation queue, and FR-4.6 -- responding to requests.
 *
 * This is the screen owners use every day, so the pending queue leads and
 * approve/reject are one click from it (NFR-5).
 */
class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly AvailabilityService $availability,
        private readonly PricingCalculator $pricing,
    ) {}

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
            // No record is possible for a walk-in with no linked account.
            'customerNoShows' => $reservation->user->no_show_count ?? 0,
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

    /**
     * The form for recording a walk-in or phone booking on the customer's
     * behalf. Deliberately reuses the same board/segmented-control/slot-grid
     * UI and the same JSON slots contract as the customer-facing booking
     * form (Site\BookingController), just with a lead time of zero -- a
     * walk-in standing at the counter needs "right now" to be offered.
     */
    public function create(Business $business, Spot $spot): View
    {
        $this->authorize('manage', $business);
        abort_unless($spot->business_id === $business->id, 404);
        abort_unless($spot->isBookable(), 404);

        $date = Carbon::today();
        $duration = $this->resolveDuration($spot, null);

        return view('owner.reservations.create', [
            'business' => $business,
            'spot' => $spot,
            'date' => $date,
            'duration' => $duration,
            'startTimes' => $this->availability->startTimesFor($spot, $date, $duration, leadMinutes: 0),
            'freeWindows' => $this->availability->freeWindows($spot, $date),
            'durations' => $spot->allowedDurations(),
            'priceExplanation' => $this->pricing->explain($spot, $duration),
            'total' => $this->pricing->total($spot, $duration),
            'dateOptions' => $this->dateOptions(),
            'channels' => ReservationChannel::manual(),
        ]);
    }

    /** Live availability for the manual booking form, same contract as bookings.slots. */
    public function slots(Request $request, Business $business, Spot $spot): JsonResponse
    {
        $this->authorize('manage', $business);
        abort_unless($spot->business_id === $business->id, 404);
        abort_unless($spot->isBookable(), 404);

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'duration' => ['required', 'integer'],
        ]);

        $date = $this->resolveDate($validated['date']);
        $duration = $this->resolveDuration($spot, $validated['duration']);

        return response()->json([
            'duration' => $duration,
            'total' => $this->pricing->total($spot, $duration),
            'total_label' => Money::pkr($this->pricing->total($spot, $duration)),
            'explanation' => $this->pricing->explain($spot, $duration),
            'slots' => array_map(
                fn (Carbon $start) => [
                    'value' => $start->format('Y-m-d H:i'),
                    'label' => $start->format('g:i A'),
                    'ends' => $start->copy()->addMinutes($duration)->format('g:i A'),
                ],
                $this->availability->startTimesFor($spot, $date, $duration, leadMinutes: 0),
            ),
        ]);
    }

    public function store(Request $request, Business $business, Spot $spot): RedirectResponse
    {
        $this->authorize('manage', $business);
        abort_unless($spot->business_id === $business->id, 404);

        $validated = $request->validate([
            'start_datetime' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer'],
            'channel' => ['required', new Enum(ReservationChannel::class)],
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $channel = ReservationChannel::from($validated['channel']);

        if (! in_array($channel, ReservationChannel::manual(), true)) {
            return back()->withInput()->withErrors(['channel' => 'Choose how this booking was made.']);
        }

        try {
            $reservation = $this->reservations->createManual(
                $spot,
                $request->user(),
                Carbon::parse($validated['start_datetime']),
                (int) $validated['duration_minutes'],
                $channel,
                $validated['customer_name'],
                $validated['customer_phone'],
                $validated['note'] ?? null,
            );
        } catch (BookingException $e) {
            return back()->withInput()->withErrors([$e->field => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.reservations.show', $reservation)
            ->with('success', 'Booking recorded and confirmed.');
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

    /** Snap an arbitrary input onto a duration this spot actually sells. */
    private function resolveDuration(Spot $spot, mixed $input): int
    {
        $allowed = $spot->allowedDurations();
        $requested = (int) $input;

        if (in_array($requested, $allowed, true)) {
            return $requested;
        }

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

    private function spotsFor($businesses, ?int $businessId)
    {
        return \App\Models\Spot::query()
            ->whereIn('business_id', $businessId ? [$businessId] : $businesses->pluck('id'))
            ->orderBy('name')
            ->get(['id', 'name', 'business_id']);
    }
}
