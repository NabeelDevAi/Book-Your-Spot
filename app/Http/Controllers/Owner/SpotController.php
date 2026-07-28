<?php

namespace App\Http\Controllers\Owner;

use App\Enums\SpotStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\SpotRequest;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Spot;
use App\Services\AuditLogger;
use App\Services\Booking\ConflictService;
use App\Services\ImageManager;
use App\Support\OperatingHours;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SpotController extends Controller
{
    public function __construct(
        private readonly ImageManager $images,
        private readonly ConflictService $conflicts,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Business $business): View
    {
        $this->authorize('manage', $business);

        return view('owner.spots.index', [
            'business' => $business,
            'businessGames' => $business->businessGames()
                ->with(['game', 'spots' => fn ($q) => $q->withCount('reservations')->orderBy('sort_order')])
                ->get(),
        ]);
    }

    public function create(Business $business, Request $request): View
    {
        $this->authorize('manage', $business);

        $businessGames = $business->businessGames()->with('game')->get();

        abort_if($businessGames->isEmpty(), 404);

        return view('owner.spots.create', [
            'business' => $business,
            'businessGames' => $businessGames,
            'selectedGameId' => (int) $request->query('game', $businessGames->first()->id),
            'hours' => $business->hours(),
        ]);
    }

    public function store(SpotRequest $request, Business $business): RedirectResponse
    {
        $this->authorize('manage', $business);

        $businessGameId = (int) $request->input('business_game_id');

        // Verify the category belongs to THIS venue. Without this an owner could
        // post another venue's business_game_id and attach a spot to it.
        $businessGame = BusinessGame::where('business_id', $business->id)
            ->whereKey($businessGameId)
            ->firstOrFail();

        DB::transaction(function () use ($request, $business, $businessGame) {
            $spot = new Spot($request->safe()->except(['operating_hours', 'images', 'override_hours']));
            $spot->business_game_id = $businessGame->id;
            $spot->business_id = $business->id;
            $spot->status = SpotStatus::Active;
            $spot->operating_hours_override = $this->resolveOverride($request);
            $spot->sort_order = (int) $businessGame->spots()->max('sort_order') + 1;
            $spot->save();

            if ($request->hasFile('images')) {
                $this->images->store(
                    $spot->images(),
                    $request->file('images'),
                    "spots/{$spot->id}",
                    (int) config('booking.max_spot_images'),
                );
            }
        });

        return redirect()
            ->route('owner.businesses.spots.index', $business)
            ->with('success', 'Spot added.');
    }

    public function edit(Business $business, Spot $spot): View
    {
        $this->authorize('update', $spot);
        abort_unless($spot->business_id === $business->id, 404);

        return view('owner.spots.edit', [
            'business' => $business,
            'spot' => $spot->load('images', 'businessGame.game'),
            'businessGames' => $business->businessGames()->with('game')->get(),
            'hours' => $spot->operating_hours_override ?? $business->hours(),
            'futureBookings' => $spot->futureOpenReservations()->count(),
        ]);
    }

    /**
     * SRS 9.10 in practice: a price change here affects only NEW bookings.
     * Existing reservations carry their own price snapshot, so nothing this
     * method does can rewrite what a confirmed customer agreed to pay.
     */
    public function update(SpotRequest $request, Business $business, Spot $spot): RedirectResponse
    {
        $this->authorize('update', $spot);
        abort_unless($spot->business_id === $business->id, 404);

        DB::transaction(function () use ($request, $spot) {
            $spot->fill($request->safe()->except(['operating_hours', 'images', 'override_hours']));
            $spot->operating_hours_override = $this->resolveOverride($request);
            $spot->save();

            if ($request->hasFile('images')) {
                $this->images->store(
                    $spot->images(),
                    $request->file('images'),
                    "spots/{$spot->id}",
                    (int) config('booking.max_spot_images'),
                );
            }
        });

        return back()->with('success', 'Spot updated. New pricing applies to future bookings only.');
    }

    /**
     * SRS 9.9: deactivation is the normal path. Future bookings are NOT
     * cancelled -- each raises a conflict for the Owner to settle with the
     * customer, because silently dropping a confirmed booking is exactly the
     * trust failure the SRS warns about.
     */
    public function deactivate(Business $business, Spot $spot): RedirectResponse
    {
        $this->authorize('deactivate', $spot);
        abort_unless($spot->business_id === $business->id, 404);

        $raised = DB::transaction(function () use ($spot) {
            $spot->status = SpotStatus::Inactive;
            $spot->save();

            $raised = $this->conflicts->raiseForSpotDeactivation($spot, request()->user());

            $this->audit->log(
                AuditLogger::SPOT_DEACTIVATED,
                $spot,
                meta: ['conflicts_raised' => $raised],
            );

            return $raised;
        });

        if ($raised > 0) {
            return back()->with('warning', sprintf(
                '%s is now inactive, but %d upcoming %s still booked. '
                .'Contact those customers and resolve each clash — they have not been cancelled.',
                $spot->name,
                $raised,
                $raised === 1 ? 'booking is' : 'bookings are',
            ));
        }

        return back()->with('success', "{$spot->name} is now inactive and hidden from customers.");
    }

    public function activate(Business $business, Spot $spot): RedirectResponse
    {
        $this->authorize('deactivate', $spot);
        abort_unless($spot->business_id === $business->id, 404);

        $spot->status = SpotStatus::Active;
        $spot->save();

        return back()->with('success', "{$spot->name} is bookable again.");
    }

    /**
     * SRS 9.9: a hard delete is only permitted when the Spot has never held a
     * reservation. Anything with history is deactivated instead.
     */
    public function destroy(Business $business, Spot $spot): RedirectResponse
    {
        abort_unless($spot->business_id === $business->id, 404);

        if (! $spot->canBeHardDeleted()) {
            return back()->with(
                'error',
                "{$spot->name} has booking history, so it can't be deleted. Deactivate it instead — "
                .'it will disappear from customer search but the records are kept.'
            );
        }

        $this->authorize('delete', $spot);

        $spot->forceDelete();

        return back()->with('success', "{$spot->name} deleted.");
    }

    /**
     * Null means "inherit the venue's hours" -- distinct from an override that
     * closes the spot all week, which is a legitimate thing to configure.
     */
    private function resolveOverride(SpotRequest $request): ?OperatingHours
    {
        if (! $request->boolean('override_hours')) {
            return null;
        }

        return OperatingHours::fromFormInput($request->input('operating_hours'));
    }
}
