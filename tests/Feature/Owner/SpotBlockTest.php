<?php

namespace Tests\Feature\Owner;

use App\Enums\ConflictSource;
use App\Enums\ConflictStatus;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\ReservationConflict;
use App\Models\Spot;
use App\Models\SpotBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-2.9 (ad hoc downtime) and SRS 9.6 (what happens to bookings underneath it).
 */
class SpotBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Business $business;

    private Spot $spot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
        $this->business = Business::factory()->active()->create(['owner_id' => $this->owner->id]);
        $businessGame = BusinessGame::factory()->create(['business_id' => $this->business->id]);
        $this->spot = Spot::factory()->create([
            'business_game_id' => $businessGame->id,
            'business_id' => $this->business->id,
        ]);
    }

    private function blockPayload(Carbon $start, int $hours = 2, array $extra = []): array
    {
        return array_merge([
            'start_datetime' => $start->format('Y-m-d\TH:i'),
            'end_datetime' => $start->copy()->addHours($hours)->format('Y-m-d\TH:i'),
            'reason' => 'Maintenance',
        ], $extra);
    }

    #[Test]
    public function an_owner_can_block_time_on_their_spot(): void
    {
        $start = Carbon::tomorrow()->setTime(12, 0);

        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.blocks.store', [$this->business, $this->spot]),
                $this->blockPayload($start))
            ->assertRedirect();

        $this->assertDatabaseCount('spot_blocks', 1);
        $this->assertSame($this->owner->id, SpotBlock::sole()->created_by);
    }

    #[Test]
    public function a_block_is_not_recorded_as_a_reservation(): void
    {
        // A fake booking would distort the venue's own statistics and look to
        // the owner like a customer who never turned up.
        $this->actingAs($this->owner)->post(
            route('owner.businesses.spots.blocks.store', [$this->business, $this->spot]),
            $this->blockPayload(Carbon::tomorrow()->setTime(12, 0))
        );

        $this->assertDatabaseCount('reservations', 0);
    }

    #[Test]
    public function an_owner_cannot_block_a_spot_they_do_not_own(): void
    {
        $intruder = User::factory()->owner()->create();

        $this->actingAs($intruder)
            ->post(route('owner.businesses.spots.blocks.store', [$this->business, $this->spot]),
                $this->blockPayload(Carbon::tomorrow()->setTime(12, 0)))
            ->assertForbidden();
    }

    #[Test]
    public function a_block_entirely_in_the_past_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.blocks.store', [$this->business, $this->spot]),
                $this->blockPayload(Carbon::yesterday()->setTime(12, 0)))
            ->assertSessionHasErrors('end_datetime');

        $this->assertDatabaseCount('spot_blocks', 0);
    }

    #[Test]
    public function the_end_must_be_after_the_start(): void
    {
        $start = Carbon::tomorrow()->setTime(12, 0);

        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.blocks.store', [$this->business, $this->spot]), [
                'start_datetime' => $start->format('Y-m-d\TH:i'),
                'end_datetime' => $start->copy()->subHour()->format('Y-m-d\TH:i'),
            ])
            ->assertSessionHasErrors('end_datetime');
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.6 -- blocking over live bookings
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function blocking_over_a_live_booking_warns_first_and_creates_nothing(): void
    {
        // The owner blocking a table for maintenance usually has no idea someone
        // is booked into it. Finding out afterwards means the block is already
        // live on a booking they may have wanted to keep.
        $start = Carbon::tomorrow()->setTime(19, 0);
        Reservation::factory()->forSpot($this->spot)->at($start, 60)->confirmed()->create();

        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.blocks.store', [$this->business, $this->spot]),
                $this->blockPayload($start->copy()->subMinutes(30), 3))
            ->assertRedirect();

        $this->assertDatabaseCount('spot_blocks', 0);
        $this->assertNotNull(session('pending_block_conflicts'));
    }

    #[Test]
    public function acknowledging_the_warning_creates_the_block_and_raises_conflicts(): void
    {
        $start = Carbon::tomorrow()->setTime(19, 0);
        $booking = Reservation::factory()->forSpot($this->spot)->at($start, 60)->confirmed()->create();

        $this->actingAs($this->owner)->post(
            route('owner.businesses.spots.blocks.store', [$this->business, $this->spot]),
            $this->blockPayload($start->copy()->subMinutes(30), 3, ['acknowledge_conflicts' => 1])
        );

        $this->assertDatabaseCount('spot_blocks', 1);

        // The booking survives -- SRS 9.6 is explicit that silently cancelling a
        // confirmed customer is the failure mode to avoid.
        $this->assertTrue($booking->fresh()->isConfirmed());

        $conflict = ReservationConflict::sole();
        $this->assertSame(ConflictStatus::Open, $conflict->status);
        $this->assertSame(ConflictSource::SpotBlock, $conflict->source_type);
        $this->assertSame($booking->id, $conflict->reservation_id);
    }

    #[Test]
    public function pending_requests_under_a_block_are_flagged_too(): void
    {
        $start = Carbon::tomorrow()->setTime(19, 0);
        Reservation::factory()->forSpot($this->spot)->at($start, 60)->create();

        $this->actingAs($this->owner)->post(
            route('owner.businesses.spots.blocks.store', [$this->business, $this->spot]),
            $this->blockPayload($start, 2, ['acknowledge_conflicts' => 1])
        );

        $this->assertSame(1, ReservationConflict::open()->count());
    }

    #[Test]
    public function a_block_that_does_not_touch_any_booking_raises_nothing(): void
    {
        $start = Carbon::tomorrow()->setTime(19, 0);
        Reservation::factory()->forSpot($this->spot)->at($start, 60)->confirmed()->create();

        // Starts exactly when the booking ends -- half-open intervals mean this
        // is not an overlap.
        $this->actingAs($this->owner)->post(
            route('owner.businesses.spots.blocks.store', [$this->business, $this->spot]),
            $this->blockPayload($start->copy()->addHour(), 2)
        );

        $this->assertDatabaseCount('spot_blocks', 1);
        $this->assertDatabaseCount('reservation_conflicts', 0);
    }

    #[Test]
    public function cancelled_bookings_under_a_block_are_not_flagged(): void
    {
        $start = Carbon::tomorrow()->setTime(19, 0);
        Reservation::factory()->forSpot($this->spot)->at($start, 60)->cancelled()->create();

        $this->actingAs($this->owner)->post(
            route('owner.businesses.spots.blocks.store', [$this->business, $this->spot]),
            $this->blockPayload($start, 2)
        );

        $this->assertDatabaseCount('spot_blocks', 1);
        $this->assertDatabaseCount('reservation_conflicts', 0);
    }

    #[Test]
    public function removing_a_block_retires_the_conflicts_it_raised(): void
    {
        $start = Carbon::tomorrow()->setTime(19, 0);
        Reservation::factory()->forSpot($this->spot)->at($start, 60)->confirmed()->create();

        $this->actingAs($this->owner)->post(
            route('owner.businesses.spots.blocks.store', [$this->business, $this->spot]),
            $this->blockPayload($start, 2, ['acknowledge_conflicts' => 1])
        );

        $block = SpotBlock::sole();
        $this->assertSame(1, ReservationConflict::open()->count());

        $this->actingAs($this->owner)->delete(
            route('owner.businesses.spots.blocks.destroy', [$this->business, $this->spot, $block])
        );

        // The clash is moot once the block is gone.
        $this->assertSame(0, ReservationConflict::open()->count());
        $this->assertDatabaseCount('spot_blocks', 0);
    }

    #[Test]
    public function re_blocking_the_same_window_does_not_stack_duplicate_conflicts(): void
    {
        $start = Carbon::tomorrow()->setTime(19, 0);
        $booking = Reservation::factory()->forSpot($this->spot)->at($start, 60)->confirmed()->create();

        $block = SpotBlock::factory()->forSpot($this->spot)->at($start, 120)->create([
            'created_by' => $this->owner->id,
        ]);

        $service = app(\App\Services\Booking\ConflictService::class);
        $service->raiseForBlock($block, $this->owner);
        $service->raiseForBlock($block, $this->owner);
        $service->raiseForBlock($block, $this->owner);

        $this->assertSame(1, $booking->conflicts()->count());
    }
}
