<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\SpotBlockRequest;
use App\Models\Business;
use App\Models\Spot;
use App\Models\SpotBlock;
use App\Services\Booking\ConflictService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * FR-2.9 -- ad hoc downtime on a Spot: maintenance, a private event, or the
 * Owner's own use of their table.
 *
 * Blocks are kept out of the reservations table on purpose. A fake booking
 * would distort the venue's own statistics and appear to the Owner as a
 * customer who never arrives.
 */
class SpotBlockController extends Controller
{
    public function __construct(private readonly ConflictService $conflicts) {}

    public function index(Business $business, Spot $spot): View
    {
        $this->authorize('block', $spot);
        abort_unless($spot->business_id === $business->id, 404);

        return view('owner.spots.blocks', [
            'business' => $business,
            'spot' => $spot,
            'blocks' => $spot->blocks()
                ->with('creator')
                ->orderByDesc('start_datetime')
                ->paginate(20),
        ]);
    }

    public function store(SpotBlockRequest $request, Business $business, Spot $spot): RedirectResponse
    {
        $this->authorize('block', $spot);
        abort_unless($spot->business_id === $business->id, 404);

        $start = Carbon::parse($request->validated('start_datetime'));
        $end = Carbon::parse($request->validated('end_datetime'));

        $clashing = $this->conflicts->reservationsClashingWithBlock($spot, $start, $end);

        // Warn before creating, not after. An Owner blocking a table for
        // maintenance usually has no idea a customer is booked into it, and
        // finding out afterwards means the block is already live on a booking
        // they may have wanted to keep.
        if ($clashing->isNotEmpty() && ! $request->boolean('acknowledge_conflicts')) {
            return back()
                ->withInput()
                ->with('warning', sprintf(
                    '%d existing %s fall inside that window. Confirm to continue — '
                    .'they will be flagged for you to resolve, not cancelled.',
                    $clashing->count(),
                    $clashing->count() === 1 ? 'booking falls' : 'bookings',
                ))
                ->with('pending_block_conflicts', $clashing->map(fn ($r) => [
                    'reference' => $r->reference,
                    'customer' => $r->user->name,
                    'when' => $r->dateLabel().' '.$r->timeRangeLabel(),
                ])->all());
        }

        $raised = DB::transaction(function () use ($request, $spot, $start, $end) {
            $block = new SpotBlock([
                'spot_id' => $spot->id,
                'start_datetime' => $start,
                'end_datetime' => $end,
                'reason' => $request->validated('reason'),
            ]);

            // Attribution is set from the session, never mass-assigned, so a
            // crafted request can't claim a block was created by someone else.
            $block->created_by = $request->user()->id;
            $block->created_by_role = $request->user()->role->value;
            $block->save();

            return $this->conflicts->raiseForBlock($block, $request->user());
        });

        if ($raised > 0) {
            return redirect()
                ->route('owner.businesses.spots.blocks.index', [$business, $spot])
                ->with('warning', sprintf(
                    'Block added. %d %s flagged for you to resolve with the customer.',
                    $raised,
                    $raised === 1 ? 'booking was' : 'bookings were',
                ));
        }

        return redirect()
            ->route('owner.businesses.spots.blocks.index', [$business, $spot])
            ->with('success', 'Block added. That time is no longer bookable.');
    }

    public function destroy(Request $request, Business $business, Spot $spot, SpotBlock $block): RedirectResponse
    {
        $this->authorize('block', $spot);
        abort_unless($spot->business_id === $business->id && $block->spot_id === $spot->id, 404);

        DB::transaction(function () use ($block, $request) {
            // Any clash this block raised is moot once it is gone -- but only
            // the still-open ones. An Owner who already cancelled a booking
            // should not have that decision quietly reversed.
            $this->conflicts->retireForBlock($block, $request->user());

            $block->delete();
        });

        return back()->with('success', 'Block removed. That time is bookable again.');
    }
}
