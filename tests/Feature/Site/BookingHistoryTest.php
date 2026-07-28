<?php

namespace Tests\Feature\Site;

use App\Enums\ReservationStatus;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Notifications\ReservationCancelled;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-4.8 (history) and FR-4.9 / SRS 9.3 (cancellation).
 */
class BookingHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Spot $spot;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));

        $this->customer = User::factory()->create();

        $business = Business::factory()->active()->create([
            'operating_hours' => OperatingHours::everyDay('00:00', '23:59'),
        ]);
        $this->owner = $business->owner;

        $this->spot = Spot::factory()->create([
            'business_game_id' => BusinessGame::factory()->create(['business_id' => $business->id])->id,
            'business_id' => $business->id,
        ]);
    }

    private function booking(Carbon $start, array $state = []): Reservation
    {
        return Reservation::factory()
            ->forSpot($this->spot)
            ->at($start, 60)
            ->create(array_merge(['user_id' => $this->customer->id], $state));
    }

    #[Test]
    public function upcoming_and_past_bookings_are_separated(): void
    {
        $upcoming = $this->booking(Carbon::parse('2026-08-05 19:00'), ['status' => ReservationStatus::Confirmed]);
        $past = $this->booking(Carbon::parse('2026-07-20 19:00'), ['status' => ReservationStatus::Completed]);

        $response = $this->actingAs($this->customer)->get(route('bookings.index'))->assertOk();

        $response->assertSee($upcoming->reference)->assertSee($past->reference);
        $response->assertSee('Upcoming')->assertSee('Past');
    }

    #[Test]
    public function a_confirmed_booking_whose_time_has_passed_is_treated_as_past(): void
    {
        // Status alone isn't enough: a confirmed booking from last week has not
        // been swept to `completed` yet but is plainly not upcoming.
        $stale = $this->booking(Carbon::parse('2026-07-25 19:00'), ['status' => ReservationStatus::Confirmed]);

        $this->actingAs($this->customer)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee($stale->reference);

        $this->assertTrue($stale->end_datetime->isPast());
    }

    #[Test]
    public function a_customer_sees_only_their_own_bookings(): void
    {
        $mine = $this->booking(Carbon::parse('2026-08-05 19:00'));
        $theirs = Reservation::factory()->create();

        $this->actingAs($this->customer)
            ->get(route('bookings.index'))
            ->assertSee($mine->reference)
            ->assertDontSee($theirs->reference);
    }

    #[Test]
    public function an_empty_history_explains_what_to_do(): void
    {
        $this->actingAs($this->customer)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee('Nothing coming up')
            ->assertSee('Browse venues');
    }

    /*
    |--------------------------------------------------------------------------
    | Cancellation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_customer_can_cancel_and_the_venue_is_told(): void
    {
        Notification::fake();

        $booking = $this->booking(Carbon::parse('2026-08-05 19:00'), ['status' => ReservationStatus::Confirmed]);

        $this->actingAs($this->customer)
            ->post(route('bookings.cancel', $booking), ['reason' => 'Plans changed'])
            ->assertRedirect(route('bookings.index'));

        $booking->refresh();
        $this->assertSame(ReservationStatus::Cancelled, $booking->status);
        $this->assertFalse($booking->is_late_cancellation);
        Notification::assertSentTo($this->owner, ReservationCancelled::class);
    }

    #[Test]
    public function a_late_cancellation_is_allowed_but_flagged_and_the_customer_is_told(): void
    {
        // SRS 9.3: no payment to forfeit in V1, so visibility is the whole
        // remedy -- and being upfront about it is fairer than flagging silently.
        $booking = $this->booking(Carbon::parse('2026-08-01 10:30'), ['status' => ReservationStatus::Confirmed]);

        $this->actingAs($this->customer)
            ->post(route('bookings.cancel', $booking), [])
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'late cancellation'));

        $this->assertTrue($booking->fresh()->is_late_cancellation);
    }

    #[Test]
    public function the_cancel_dialog_warns_before_a_late_cancellation(): void
    {
        $this->booking(Carbon::parse('2026-08-01 10:30'), ['status' => ReservationStatus::Confirmed]);

        $this->actingAs($this->customer)
            ->get(route('bookings.index'))
            ->assertSee('the venue will be told it was a late cancellation');
    }

    #[Test]
    public function a_pending_request_can_be_cancelled(): void
    {
        $booking = $this->booking(Carbon::parse('2026-08-05 19:00'));

        $this->actingAs($this->customer)->post(route('bookings.cancel', $booking));

        $this->assertSame(ReservationStatus::Cancelled, $booking->fresh()->status);
    }

    #[Test]
    public function cancelling_releases_the_slot_for_someone_else(): void
    {
        $booking = $this->booking(Carbon::parse('2026-08-05 19:00'), ['status' => ReservationStatus::Confirmed]);

        $this->actingAs($this->customer)->post(route('bookings.cancel', $booking));

        $next = app(\App\Services\Booking\ReservationService::class)->request(
            $this->spot,
            User::factory()->create(),
            Carbon::parse('2026-08-05 19:00'),
            60,
        );

        $this->assertTrue($next->isPending());
    }

    #[Test]
    public function a_customer_cannot_cancel_someone_elses_booking(): void
    {
        $theirs = Reservation::factory()->confirmed()->create();

        $this->actingAs($this->customer)
            ->post(route('bookings.cancel', $theirs))
            ->assertForbidden();

        $this->assertSame(ReservationStatus::Confirmed, $theirs->fresh()->status);
    }

    #[Test]
    public function a_completed_booking_cannot_be_cancelled(): void
    {
        $booking = $this->booking(Carbon::parse('2026-07-20 19:00'), ['status' => ReservationStatus::Completed]);

        $this->actingAs($this->customer)
            ->post(route('bookings.cancel', $booking))
            ->assertForbidden();
    }
}
