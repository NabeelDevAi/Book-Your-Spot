<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\BusinessRequest;
use App\Models\Business;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Admin\BusinessModerationService;
use App\Services\Business\DuplicateDetector;
use App\Support\OperatingHours;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * FR-3.1, FR-3.2, FR-3.6, FR-3.8 -- venue moderation and override.
 */
class BusinessController extends Controller
{
    public function __construct(
        private readonly BusinessModerationService $moderation,
        private readonly DuplicateDetector $duplicates,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $businesses = Business::query()
            ->with('owner')
            ->withCount(['spots', 'reservations'])
            ->when(filled($request->query('status')), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->boolean('duplicates'), fn (Builder $q) => $q->where('duplicate_flagged', true))
            ->when(filled($request->query('search')), function (Builder $q) use ($request) {
                $term = '%'.trim((string) $request->query('search')).'%';
                $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('area', 'like', $term)
                    ->orWhere('contact_number', 'like', $term));
            })
            // Pending review first: the queue is the point of this screen.
            ->orderByRaw("FIELD(status, 'pending_review') DESC")
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.businesses.index', [
            'businesses' => $businesses,
            'statuses' => BusinessStatus::cases(),
            'pendingCount' => Business::where('status', BusinessStatus::PendingReview)->count(),
            'duplicateCount' => Business::where('duplicate_flagged', true)->count(),
        ]);
    }

    public function show(Business $business): View
    {
        $business->load(['owner', 'images', 'businessGames.game', 'spots']);

        return view('admin.businesses.show', [
            'business' => $business,
            // SRS 9.11: show what it was flagged against, so the Admin can judge
            // rather than guess.
            'possibleDuplicates' => $business->duplicate_flagged
                ? Business::whereKeyNot($business->id)
                    ->where(fn (Builder $q) => $q
                        ->where('contact_number', $business->contact_number)
                        ->orWhere(fn (Builder $inner) => $inner
                            ->where('area', $business->area)
                            ->where('address', $business->address)))
                    ->get()
                : collect(),
            'auditTrail' => \App\Models\AuditLog::forTarget(Business::class, $business->id)
                ->with('actor')
                ->latest()
                ->limit(20)
                ->get(),
            'futureBookings' => $business->reservations()
                ->open()
                ->where('start_datetime', '>', now())
                ->count(),
        ]);
    }

    public function approve(Business $business): RedirectResponse
    {
        $this->moderation->approve($business, request()->user());

        return back()->with('success', "{$business->name} is now live and bookable.");
    }

    public function reject(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->moderation->reject($business, $request->user(), $validated['reason']);

        return back()->with('success', "{$business->name} was rejected and the owner notified.");
    }

    /** SRS 9.8 -- suspension cascades to every future booking. */
    public function suspend(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $affected = $this->moderation->suspend($business, $request->user(), $validated['reason']);

        return back()->with('success', $affected > 0
            ? "{$business->name} suspended. {$affected} upcoming "
                .\Illuminate\Support\Str::plural('booking', $affected)
                .' cancelled and those customers notified.'
            : "{$business->name} suspended.");
    }

    public function reinstate(Business $business): RedirectResponse
    {
        $this->moderation->reinstate($business, request()->user());

        return back()->with('success', "{$business->name} is live again.");
    }

    /** SRS 9.11 -- confirm a flagged listing is genuine, without approving it. */
    public function clearDuplicateFlag(Business $business): RedirectResponse
    {
        $business->forceFill(['duplicate_flagged' => false, 'duplicate_note' => null])->save();

        $this->audit->log('business.duplicate_flag_cleared', $business);

        return back()->with('success', 'Duplicate flag cleared.');
    }

    /*
    |--------------------------------------------------------------------------
    | FR-3.6 -- override edit
    |--------------------------------------------------------------------------
    */

    public function edit(Business $business): View
    {
        return view('admin.businesses.edit', [
            'business' => $business->load('images'),
            'hours' => $business->hours(),
            'areas' => Business::query()->select('area')->distinct()->orderBy('area')->pluck('area')->all(),
        ]);
    }

    /**
     * SRS 9.20 -- an Admin editing someone else's listing must say why, and the
     * before/after is recorded. This is the exact scenario the audit log exists
     * for: settling "who changed what" after a dispute.
     */
    public function update(BusinessRequest $request, Business $business): RedirectResponse
    {
        $request->validate([
            'override_reason' => ['required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $business) {
            $before = $business->only(['name', 'description', 'address', 'city', 'area', 'contact_number']);

            $business->fill($request->safe()->except(['operating_hours', 'images']));
            $business->operating_hours = OperatingHours::fromFormInput($request->input('operating_hours'));
            $business->save();

            $after = $business->only(['name', 'description', 'address', 'city', 'area', 'contact_number']);

            $this->audit->override(
                AuditLogger::BUSINESS_OVERRIDDEN,
                $business,
                $request->input('override_reason'),
                ['before' => array_diff_assoc($before, $after), 'after' => array_diff_assoc($after, $before)],
            );

            $this->duplicates->flag($business);
        });

        return redirect()
            ->route('admin.businesses.show', $business)
            ->with('success', 'Venue updated. The change has been logged.');
    }

    /*
    |--------------------------------------------------------------------------
    | FR-3.8 -- create on behalf of an owner
    |--------------------------------------------------------------------------
    | Early pilot onboarding happens in person or over the phone, so an Admin
    | needs to be able to list a venue for an owner who has not touched the
    | site yet.
    */

    public function create(): View
    {
        return view('admin.businesses.create', [
            'hours' => OperatingHours::everyDay('14:00', '23:00'),
            'owners' => User::owners()->active()->orderBy('name')->get(),
            'areas' => Business::query()->select('area')->distinct()->orderBy('area')->pluck('area')->all(),
        ]);
    }

    public function store(BusinessRequest $request): RedirectResponse
    {
        $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $owner = User::owners()->findOrFail($request->input('owner_id'));

        $business = DB::transaction(function () use ($request, $owner) {
            $business = new Business($request->safe()->except(['operating_hours', 'images', 'owner_id']));
            $business->owner_id = $owner->id;
            $business->operating_hours = OperatingHours::fromFormInput($request->input('operating_hours'));

            // Created by an Admin who has already vetted the venue in person,
            // so it goes live immediately rather than into its own queue.
            $business->status = BusinessStatus::Active;
            $business->reviewed_by = $request->user()->id;
            $business->reviewed_at = now();
            $business->save();

            $this->audit->log(
                'business.created_on_behalf',
                $business,
                meta: ['owner_id' => $owner->id, 'owner_name' => $owner->name],
            );

            return $business;
        });

        return redirect()
            ->route('admin.businesses.show', $business)
            ->with('success', "{$business->name} created for {$owner->name} and set live.");
    }
}
