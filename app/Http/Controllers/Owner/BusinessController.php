<?php

namespace App\Http\Controllers\Owner;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\BusinessRequest;
use App\Models\Business;
use App\Services\AuditLogger;
use App\Services\Business\DuplicateDetector;
use App\Services\ImageManager;
use App\Support\OperatingHours;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BusinessController extends Controller
{
    public function __construct(
        private readonly DuplicateDetector $duplicates,
        private readonly ImageManager $images,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        return view('owner.businesses.index', [
            'businesses' => $request->user()
                ->businesses()
                ->withCount(['spots', 'businessGames'])
                ->with('images')
                ->latest()
                ->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Business::class);

        return view('owner.businesses.create', [
            'hours' => OperatingHours::everyDay('14:00', '23:00'),
            'areas' => $this->knownAreas(),
        ]);
    }

    /**
     * FR-2.2: a new venue always enters `pending_review` and stays invisible to
     * customers until an Admin approves it. Status is never taken from input.
     */
    public function store(BusinessRequest $request): RedirectResponse
    {
        $this->authorize('create', Business::class);

        $business = DB::transaction(function () use ($request) {
            $business = new Business($request->safe()->except(['operating_hours', 'images', 'videos']));
            $business->owner_id = $request->user()->id;
            $business->operating_hours = OperatingHours::fromFormInput($request->input('operating_hours'));
            $business->status = BusinessStatus::PendingReview;
            $business->save();

            if ($request->hasFile('images')) {
                $this->images->store(
                    $business->images(),
                    $request->file('images'),
                    "businesses/{$business->id}",
                    (int) config('booking.max_business_images'),
                );
            }

            if ($request->hasFile('videos')) {
                $this->images->storeVideos(
                    $business->images(),
                    $request->file('videos'),
                    "businesses/{$business->id}",
                    (int) config('booking.max_business_videos'),
                );
            }

            // SRS 9.11 -- flag, never block. Two venues in adjacent units may
            // legitimately share a landline; an Admin decides.
            $this->duplicates->flag($business);

            return $business;
        });

        return redirect()
            ->route('owner.businesses.games.edit', $business)
            ->with('success', 'Venue saved and sent for approval. It\'s hidden from customers until then '
                .'— go ahead and finish setting it up below, nothing here is blocked.');
    }

    public function edit(Business $business): View
    {
        $this->authorize('update', $business);

        return view('owner.businesses.edit', [
            'business' => $business->load('images'),
            'hours' => $business->hours(),
            'areas' => $this->knownAreas(),
        ]);
    }

    public function update(BusinessRequest $request, Business $business): RedirectResponse
    {
        $this->authorize('update', $business);

        DB::transaction(function () use ($request, $business) {
            $business->fill($request->safe()->except(['operating_hours', 'images', 'videos']));
            $business->operating_hours = OperatingHours::fromFormInput($request->input('operating_hours'));

            // Editing an already-rejected venue is how an Owner resubmits it,
            // so it returns to the review queue rather than staying rejected
            // with the corrections invisible to Admin.
            if ($business->status === BusinessStatus::Rejected) {
                $business->status = BusinessStatus::PendingReview;
                $business->rejection_reason = null;
            }

            $business->save();

            if ($request->hasFile('images')) {
                $this->images->store(
                    $business->images(),
                    $request->file('images'),
                    "businesses/{$business->id}",
                    (int) config('booking.max_business_images'),
                );
            }

            if ($request->hasFile('videos')) {
                $this->images->storeVideos(
                    $business->images(),
                    $request->file('videos'),
                    "businesses/{$business->id}",
                    (int) config('booking.max_business_videos'),
                );
            }

            $this->duplicates->flag($business);
        });

        return back()->with('success', 'Venue updated.');
    }

    /**
     * SRS 9.9 applied at the venue level: a venue with any booking history is
     * never destroyed. The policy enforces it; this is the friendly explanation.
     */
    public function destroy(Business $business): RedirectResponse
    {
        if ($business->reservations()->exists()) {
            return back()->with(
                'error',
                'This venue has booking history and can\'t be deleted. Contact support to have it removed from search instead.'
            );
        }

        $this->authorize('delete', $business);

        $business->delete();

        $this->audit->log('business.deleted', $business, meta: ['name' => $business->name]);

        return redirect()
            ->route('owner.businesses.index')
            ->with('success', 'Venue deleted.');
    }

    /**
     * Areas already in use, offered as suggestions so owners converge on
     * consistent locality names instead of inventing "DHA 6" / "Phase VI".
     *
     * @return list<string>
     */
    private function knownAreas(): array
    {
        return Business::query()
            ->select('area')
            ->distinct()
            ->orderBy('area')
            ->pluck('area')
            ->all();
    }
}
