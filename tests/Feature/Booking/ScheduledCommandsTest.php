<?php

namespace Tests\Feature\Booking;

use App\Enums\ReservationStatus;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Notifications\ReservationExpired;
use App\Notifications\ReservationReminder;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three scheduled sweeps: expiry (FR-4.7), completion (FR-4.10) and
 * reminders (FR-5.1).
 */
class ScheduledCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Spot $spot;

    private User $customer;

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

    private function reservation(array $state = []): Reservation
    {
        return Reservation::factory()
            ->forSpot($this->spot)
            ->create(array_merge(['user_id' => $this->customer->id], $state));
    }

    /*
    |--------------------------------------------------------------------------
    | reservations:expire
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_pending_request_past_its_deadline_expires(): void
    {
        Notification::fake();

        $reservation = $this->reservation(['response_deadline' => now()->subMinute()]);

        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        Notification::assertSentTo($this->customer, ReservationExpired::class);
        Notification::assertSentTo($this->owner, ReservationExpired::class);
    }

    #[Test]
    public function a_pending_request_still_inside_its_window_survives(): void
    {
        $reservation = $this->reservation(['response_deadline' => now()->addHours(3)]);

        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status);
    }

    #[Test]
    public function a_pending_request_whose_slot_has_started_expires_regardless_of_its_deadline(): void
    {
        // The safety net: a deadline in the future is meaningless once the slot
        // itself has begun.
        $reservation = Reservation::factory()->forSpot($this->spot)
            ->at(now()->subHour(), 60)
            ->create([
                'user_id' => $this->customer->id,
                'response_deadline' => now()->addDay(),
            ]);

        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
    }

    #[Test]
    public function a_confirmed_booking_is_never_expired(): void
    {
        $reservation = $this->reservation([
            'status' => ReservationStatus::Confirmed,
            'response_deadline' => now()->subDay(),
        ]);

        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    #[Test]
    public function expiry_is_idempotent(): void
    {
        Notification::fake();

        $this->reservation(['response_deadline' => now()->subMinute()]);

        $this->artisan('reservations:expire');
        $this->artisan('reservations:expire');
        $this->artisan('reservations:expire');

        // Runs every minute in production -- a second pass must not re-notify.
        Notification::assertSentToTimes($this->customer, ReservationExpired::class, 1);
    }

    /*
    |--------------------------------------------------------------------------
    | reservations:complete
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_finished_confirmed_booking_completes_after_the_grace_period(): void
    {
        $reservation = Reservation::factory()->forSpot($this->spot)
            ->at(now()->subHours(4), 60)
            ->confirmed()
            ->create(['user_id' => $this->customer->id]);

        $this->artisan('reservations:complete')->assertSuccessful();

        $this->assertSame(ReservationStatus::Completed, $reservation->fresh()->status);
    }

    #[Test]
    public function a_booking_that_just_ended_is_left_alone_for_the_owner_to_judge(): void
    {
        // The grace window exists so the owner can still record a no-show.
        // Without it this sweep would race them and silently overwrite it.
        $reservation = Reservation::factory()->forSpot($this->spot)
            ->at(now()->subMinutes(90), 60)
            ->confirmed()
            ->create(['user_id' => $this->customer->id]);

        $this->artisan('reservations:complete')->assertSuccessful();

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    #[Test]
    public function a_booking_still_running_does_not_complete(): void
    {
        $reservation = Reservation::factory()->forSpot($this->spot)
            ->at(now()->subMinutes(30), 60)
            ->confirmed()
            ->create(['user_id' => $this->customer->id]);

        $this->artisan('reservations:complete')->assertSuccessful();

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    #[Test]
    public function a_no_show_is_never_overwritten_by_completion(): void
    {
        $reservation = Reservation::factory()->forSpot($this->spot)
            ->at(now()->subDay(), 60)
            ->noShow()
            ->create(['user_id' => $this->customer->id]);

        $this->artisan('reservations:complete')->assertSuccessful();

        $this->assertSame(ReservationStatus::NoShow, $reservation->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | reservations:remind
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_booking_inside_the_reminder_window_is_reminded(): void
    {
        Notification::fake();

        $reservation = Reservation::factory()->forSpot($this->spot)
            ->at(now()->addMinutes(90), 60)
            ->confirmed()
            ->create(['user_id' => $this->customer->id]);

        $this->artisan('reservations:remind')->assertSuccessful();

        Notification::assertSentTo($this->customer, ReservationReminder::class);
        Notification::assertSentTo($this->owner, ReservationReminder::class);
        $this->assertNotNull($reservation->fresh()->reminder_sent_at);
    }

    #[Test]
    public function a_booking_further_out_is_not_reminded_yet(): void
    {
        Notification::fake();

        Reservation::factory()->forSpot($this->spot)
            ->at(now()->addHours(6), 60)
            ->confirmed()
            ->create(['user_id' => $this->customer->id]);

        $this->artisan('reservations:remind')->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function reminders_are_sent_only_once(): void
    {
        Notification::fake();

        Reservation::factory()->forSpot($this->spot)
            ->at(now()->addMinutes(90), 60)
            ->confirmed()
            ->create(['user_id' => $this->customer->id]);

        // The command runs every minute; without the reminder_sent_at guard a
        // booking two hours out would be reminded about sixty times.
        $this->artisan('reservations:remind');
        $this->artisan('reservations:remind');
        $this->artisan('reservations:remind');

        Notification::assertSentToTimes($this->customer, ReservationReminder::class, 1);
    }

    #[Test]
    public function pending_requests_are_not_reminded_about(): void
    {
        Notification::fake();

        Reservation::factory()->forSpot($this->spot)
            ->at(now()->addMinutes(90), 60)
            ->create(['user_id' => $this->customer->id]);

        $this->artisan('reservations:remind')->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_booking_already_underway_is_not_reminded_about(): void
    {
        Notification::fake();

        Reservation::factory()->forSpot($this->spot)
            ->at(now()->subMinutes(10), 60)
            ->confirmed()
            ->create(['user_id' => $this->customer->id]);

        $this->artisan('reservations:remind')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
