<?php

namespace Tests\Feature\Booking;

use App\Exceptions\BookingException;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\SpotBlock;
use App\Models\User;
use App\Services\Booking\ReservationService;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every rule from SRS section 9 that governs whether a request may be made.
 */
class BookingValidationTest extends TestCase
{
    use RefreshDatabase;

    private ReservationService $service;

    private Spot $spot;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time so "tomorrow at 8pm" means the same thing throughout.
        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));

        $this->service = app(ReservationService::class);
        $this->customer = User::factory()->create();
        $this->spot = $this->makeSpot();
    }

    private function makeSpot(array $spotAttributes = [], string $open = '10:00', string $close = '23:00'): Spot
    {
        $business = Business::factory()->active()->create([
            'operating_hours' => OperatingHours::everyDay($open, $close),
        ]);

        $businessGame = BusinessGame::factory()->create(['business_id' => $business->id]);

        return Spot::factory()->create(array_merge([
            'business_game_id' => $businessGame->id,
            'business_id' => $business->id,
            'price_amount' => 100,
            'price_unit_minutes' => 10,
            'min_duration_minutes' => 30,
            'max_duration_minutes' => 240,
        ], $spotAttributes));
    }

    private function tomorrowAt(int $hour, int $minute = 0): Carbon
    {
        return Carbon::parse('2026-08-02')->setTime($hour, $minute);
    }

    /*
    |--------------------------------------------------------------------------
    | Happy path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_customer_can_request_a_valid_slot(): void
    {
        $reservation = $this->service->request($this->spot, $this->customer, $this->tomorrowAt(19), 60);

        $this->assertTrue($reservation->isPending());
        $this->assertSame(60, $reservation->duration_minutes);
        $this->assertEqualsWithDelta(600.0, (float) $reservation->total_price, 0.001);
        $this->assertMatchesRegularExpression('/^V365-/', $reservation->reference);
    }

    #[Test]
    public function the_price_is_snapshotted_at_request_time(): void
    {
        // SRS 9.10 -- the rate and unit travel with the reservation so the
        // booking can still be explained after the spot's pricing moves on.
        $reservation = $this->service->request($this->spot, $this->customer, $this->tomorrowAt(19), 60);

        $this->assertEqualsWithDelta(100.0, (float) $reservation->price_amount_snapshot, 0.001);
        $this->assertSame(10, $reservation->price_unit_minutes_snapshot);
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.15 -- timing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_start_time_in_the_past_is_rejected(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('already passed');

        $this->service->request($this->spot, $this->customer, Carbon::parse('2026-07-31 19:00'), 60);
    }

    #[Test]
    public function a_booking_too_close_to_the_start_is_rejected(): void
    {
        // The owner needs a usable window to respond, and it keeps the deadline
        // calculation well-defined.
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('minutes\' notice');

        $this->service->request($this->spot, $this->customer, Carbon::parse('2026-08-01 10:20'), 60);
    }

    #[Test]
    public function a_booking_beyond_the_advance_window_is_rejected(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('days in advance');

        $this->service->request($this->spot, $this->customer, Carbon::parse('2026-09-30 19:00'), 60);
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.7 -- duration and billing units
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_duration_that_is_not_a_whole_billing_unit_is_rejected(): void
    {
        // The SRS's own example: 7 minutes on a table billed in 10-minute blocks.
        try {
            $this->service->request($this->spot, $this->customer, $this->tomorrowAt(19), 37);
            $this->fail('Expected a BookingException.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('10 min blocks', $e->getMessage());
            // The message must offer a way forward, not just say "no".
            $this->assertStringContainsString('Try 40 min', $e->getMessage());
            $this->assertContains(40, $e->suggestions);
        }
    }

    #[Test]
    public function a_duration_below_the_minimum_is_rejected(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('shortest booking');

        $this->service->request($this->spot, $this->customer, $this->tomorrowAt(19), 20);
    }

    #[Test]
    public function a_duration_above_the_maximum_is_rejected(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('longest booking');

        $this->service->request($this->spot, $this->customer, $this->tomorrowAt(12), 300);
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.5 -- operating hours
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_booking_before_opening_is_rejected(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('outside opening hours');

        $this->service->request($this->spot, $this->customer, $this->tomorrowAt(8), 60);
    }

    #[Test]
    public function a_booking_that_would_run_past_closing_is_rejected(): void
    {
        // Starts inside hours (22:30) but ends at 23:30, after the 23:00 close.
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('outside opening hours');

        $this->service->request($this->spot, $this->customer, $this->tomorrowAt(22, 30), 60);
    }

    #[Test]
    public function an_overnight_venue_accepts_a_booking_after_midnight(): void
    {
        // A venue open 16:00–02:00 must accept a 1 AM booking. Treating the
        // close time as "earlier than open, therefore invalid" would break
        // most gaming venues on the platform.
        $spot = $this->makeSpot(open: '16:00', close: '02:00');

        $reservation = $this->service->request(
            $spot,
            $this->customer,
            Carbon::parse('2026-08-02 01:00'),
            60,
        );

        $this->assertTrue($reservation->isPending());
    }

    #[Test]
    public function a_spot_override_beats_the_venue_hours(): void
    {
        // The venue is open until 23:00 but this table closes at 22:00.
        $spot = $this->makeSpot(['operating_hours_override' => OperatingHours::everyDay('10:00', '22:00')]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('outside opening hours');

        $this->service->request($spot, $this->customer, $this->tomorrowAt(22), 60);
    }

    #[Test]
    public function a_booking_on_a_closed_day_is_rejected(): void
    {
        $hours = OperatingHours::fromArray([
            'mon' => [['open' => '10:00', 'close' => '23:00']],
            'sun' => [],
        ]);
        $spot = $this->makeSpot(['operating_hours_override' => $hours]);

        // 2 Aug 2026 is a Sunday.
        $this->expectException(BookingException::class);

        $this->service->request($spot, $this->customer, $this->tomorrowAt(19), 60);
    }

    /*
    |--------------------------------------------------------------------------
    | Availability
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_confirmed_booking_blocks_the_slot(): void
    {
        Reservation::factory()->forSpot($this->spot)->at($this->tomorrowAt(19), 60)->confirmed()->create();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('just been booked');

        $this->service->request($this->spot, $this->customer, $this->tomorrowAt(19), 60);
    }

    #[Test]
    public function a_pending_request_by_someone_else_does_not_block_the_slot(): void
    {
        // The FR-4.4 amendment: several customers may queue on one slot and the
        // first approval wins. If pending blocked, the auto-reject rule could
        // never fire.
        Reservation::factory()->forSpot($this->spot)->at($this->tomorrowAt(19), 60)->create();

        $reservation = $this->service->request($this->spot, $this->customer, $this->tomorrowAt(19), 60);

        $this->assertTrue($reservation->isPending());
        $this->assertSame(2, Reservation::pending()->count());
    }

    #[Test]
    public function back_to_back_bookings_are_allowed(): void
    {
        Reservation::factory()->forSpot($this->spot)->at($this->tomorrowAt(19), 60)->confirmed()->create();

        // Starts exactly when the other ends -- half-open intervals make
        // consecutive slots sellable.
        $reservation = $this->service->request($this->spot, $this->customer, $this->tomorrowAt(20), 60);

        $this->assertTrue($reservation->isPending());
    }

    #[Test]
    public function an_owner_block_prevents_booking(): void
    {
        SpotBlock::factory()->forSpot($this->spot)->at($this->tomorrowAt(18), 180)->create();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('made that time unavailable');

        $this->service->request($this->spot, $this->customer, $this->tomorrowAt(19), 60);
    }

    #[Test]
    public function a_cancelled_booking_releases_its_slot(): void
    {
        Reservation::factory()->forSpot($this->spot)->at($this->tomorrowAt(19), 60)->cancelled()->create();

        $reservation = $this->service->request($this->spot, $this->customer, $this->tomorrowAt(19), 60);

        $this->assertTrue($reservation->isPending());
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.14 / 9.17 -- who and what may be booked
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_owner_cannot_book_at_all(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Owner accounts can\'t make bookings');

        $this->service->request($this->spot, User::factory()->owner()->create(), $this->tomorrowAt(19), 60);
    }

    #[Test]
    public function an_owner_cannot_book_their_own_spot(): void
    {
        // The same rule, but this is the case SRS 9.14 actually names -- the
        // supported alternative is the spot-block feature.
        $this->expectException(BookingException::class);

        $this->service->request($this->spot, $this->spot->business->loadMissing('owner')->owner, $this->tomorrowAt(19), 60);
    }

    #[Test]
    public function a_suspended_customer_cannot_book(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('suspended');

        $this->service->request($this->spot, User::factory()->suspended()->create(), $this->tomorrowAt(19), 60);
    }

    #[Test]
    public function an_inactive_spot_cannot_be_booked(): void
    {
        $this->spot->update(['status' => \App\Enums\SpotStatus::Inactive]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('isn\'t taking bookings');

        $this->service->request($this->spot->fresh(), $this->customer, $this->tomorrowAt(19), 60);
    }

    #[Test]
    public function a_spot_at_an_unapproved_venue_cannot_be_booked(): void
    {
        $business = $this->spot->business;
        $business->status = \App\Enums\BusinessStatus::PendingReview;
        $business->save();

        $this->expectException(BookingException::class);

        $this->service->request($this->spot->fresh(), $this->customer, $this->tomorrowAt(19), 60);
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.18 -- the pending cap
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_customer_is_capped_at_three_pending_requests(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->service->request($this->makeSpot(), $this->customer, $this->tomorrowAt(19), 60);
        }

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('already have 3 requests');

        $this->service->request($this->makeSpot(), $this->customer, $this->tomorrowAt(19), 60);
    }

    #[Test]
    public function answered_requests_do_not_count_towards_the_cap(): void
    {
        // Only genuinely open requests tie up an owner's queue.
        Reservation::factory()->count(5)->confirmed()->create(['user_id' => $this->customer->id]);
        Reservation::factory()->count(5)->rejected()->create(['user_id' => $this->customer->id]);

        $reservation = $this->service->request($this->spot, $this->customer, $this->tomorrowAt(19), 60);

        $this->assertTrue($reservation->isPending());
    }

    #[Test]
    public function the_same_customer_cannot_request_the_same_slot_twice(): void
    {
        $this->service->request($this->spot, $this->customer, $this->tomorrowAt(19), 60);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('already have a request');

        $this->service->request($this->spot, $this->customer, $this->tomorrowAt(19), 60);
    }
}
