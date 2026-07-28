<?php

namespace Tests\Feature\Booking;

use App\Enums\RejectionReason;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Notifications\ReservationCancelled;
use App\Notifications\ReservationConfirmed;
use App\Notifications\ReservationExpired;
use App\Notifications\ReservationNoShow;
use App\Notifications\ReservationRejected;
use App\Notifications\ReservationRequested;
use App\Services\AuditLogger;
use App\Services\Booking\ReservationService;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The status lifecycle from SRS section 5, plus the auto-reject cascade.
 */
class ReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ReservationService $service;

    private Spot $spot;

    private User $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));

        $this->service = app(ReservationService::class);
        $this->customer = User::factory()->create();

        $business = Business::factory()->active()->create([
            'operating_hours' => OperatingHours::everyDay('10:00', '23:00'),
        ]);
        $this->owner = $business->owner;

        $this->spot = Spot::factory()->create([
            'business_game_id' => BusinessGame::factory()->create(['business_id' => $business->id])->id,
            'business_id' => $business->id,
            'price_amount' => 100,
            'price_unit_minutes' => 10,
            'min_duration_minutes' => 30,
            'max_duration_minutes' => 240,
        ]);
    }

    private function at(int $hour, int $minute = 0): Carbon
    {
        return Carbon::parse('2026-08-02')->setTime($hour, $minute);
    }

    private function pendingRequest(?User $customer = null, ?Carbon $start = null): Reservation
    {
        return $this->service->request(
            $this->spot,
            $customer ?? $this->customer,
            $start ?? $this->at(19),
            60,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Request -> notify owner
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function requesting_notifies_the_owner(): void
    {
        Notification::fake();

        $this->pendingRequest();

        Notification::assertSentTo($this->owner, ReservationRequested::class);
    }

    #[Test]
    public function the_owner_notification_carries_the_customers_no_show_history(): void
    {
        // SRS 9.12 -- without payments, showing the owner this record at the
        // moment they decide is the only deterrent available.
        Notification::fake();

        $repeatOffender = User::factory()->repeatNoShow(3)->create();
        $this->pendingRequest($repeatOffender);

        Notification::assertSentTo($this->owner, ReservationRequested::class,
            function (ReservationRequested $notification) {
                return $notification->toArray($this->owner)['customer_no_show_count'] === 3;
            });
    }

    #[Test]
    public function the_response_deadline_is_set_on_request(): void
    {
        $reservation = $this->pendingRequest();

        // Booked more than 12h ahead, so the fixed-lead branch applies.
        $this->assertSame('2026-08-02 07:00:00', $reservation->response_deadline->format('Y-m-d H:i:s'));
    }

    /*
    |--------------------------------------------------------------------------
    | Approval, and the auto-reject cascade
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function approving_confirms_the_booking_and_notifies_the_customer(): void
    {
        Notification::fake();

        $confirmed = $this->service->approve($this->pendingRequest(), $this->owner);

        $this->assertSame(ReservationStatus::Confirmed, $confirmed->status);
        $this->assertSame($this->owner->id, $confirmed->responded_by);
        Notification::assertSentTo($this->customer, ReservationConfirmed::class);
    }

    #[Test]
    public function approving_one_request_auto_rejects_every_overlapping_rival(): void
    {
        // The flip side of letting several customers queue on one slot: if the
        // losers stayed pending they would block the slot forever and fill the
        // owner's queue with requests that can never be honoured.
        $winner = $this->pendingRequest(User::factory()->create());
        $loserA = $this->pendingRequest(User::factory()->create());
        $loserB = $this->pendingRequest(User::factory()->create());

        $this->service->approve($winner, $this->owner);

        $this->assertSame(ReservationStatus::Confirmed, $winner->fresh()->status);

        foreach ([$loserA, $loserB] as $loser) {
            $loser->refresh();
            $this->assertSame(ReservationStatus::Rejected, $loser->status);
            $this->assertSame(RejectionReason::SlotTaken, $loser->rejection_reason_code);
        }
    }

    #[Test]
    public function displaced_customers_are_told_they_lost_a_race_not_that_they_were_declined(): void
    {
        Notification::fake();

        $winner = $this->pendingRequest(User::factory()->create());
        $loser = $this->pendingRequest(User::factory()->create());

        $this->service->approve($winner, $this->owner);

        Notification::assertSentTo($loser->user, ReservationRejected::class,
            function (ReservationRejected $notification) use ($loser) {
                $payload = $notification->toArray($loser->user);

                return $payload['auto_rejected'] === true
                    && str_contains($payload['title'], 'Slot taken');
            });
    }

    #[Test]
    public function non_overlapping_requests_on_the_same_spot_are_untouched(): void
    {
        $winner = $this->pendingRequest(User::factory()->create(), $this->at(19));
        $later = $this->pendingRequest(User::factory()->create(), $this->at(21));

        $this->service->approve($winner, $this->owner);

        $this->assertSame(ReservationStatus::Pending, $later->fresh()->status);
    }

    #[Test]
    public function requests_on_a_different_spot_are_untouched(): void
    {
        $otherSpot = Spot::factory()->create([
            'business_game_id' => $this->spot->business_game_id,
            'business_id' => $this->spot->business_id,
        ]);

        $winner = $this->pendingRequest(User::factory()->create());
        $elsewhere = $this->service->request($otherSpot, User::factory()->create(), $this->at(19), 60);

        $this->service->approve($winner, $this->owner);

        $this->assertSame(ReservationStatus::Pending, $elsewhere->fresh()->status);
    }

    #[Test]
    public function a_request_cannot_be_approved_twice(): void
    {
        $reservation = $this->pendingRequest();
        $this->service->approve($reservation, $this->owner);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('already been answered');

        $this->service->approve($reservation->fresh(), $this->owner);
    }

    #[Test]
    public function a_request_whose_slot_was_confirmed_elsewhere_cannot_be_approved(): void
    {
        // Re-validated under the lock: the owner may have opened this page
        // before a rival was confirmed.
        $first = $this->pendingRequest(User::factory()->create());
        $second = Reservation::factory()->forSpot($this->spot)->at($this->at(19), 60)->create([
            'user_id' => User::factory()->create()->id,
        ]);

        $this->service->approve($first, $this->owner);

        // Approving the first auto-rejected the second. Force it back to pending
        // via a direct query to simulate a stale approval page -- an Eloquent
        // save() here would be a no-op, since the in-memory model never saw the
        // rejection and so has no dirty attributes.
        Reservation::whereKey($second->id)->update(['status' => ReservationStatus::Pending->value]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('just been booked');

        $this->service->approve($second->fresh(), $this->owner);
    }

    #[Test]
    public function a_request_whose_slot_has_already_passed_cannot_be_approved(): void
    {
        $reservation = $this->pendingRequest();

        $this->travelTo(Carbon::parse('2026-08-03 10:00:00'));

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('already passed');

        $this->service->approve($reservation->fresh(), $this->owner);
    }

    #[Test]
    public function approval_is_recorded_in_the_audit_log(): void
    {
        $this->service->approve($this->pendingRequest(), $this->owner);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogger::RESERVATION_APPROVED,
            'actor_id' => $this->owner->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Rejection
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_owner_can_reject_with_a_reason(): void
    {
        Notification::fake();

        $rejected = $this->service->reject(
            $this->pendingRequest(),
            $this->owner,
            RejectionReason::FullyBooked,
            'Private tournament that evening.',
        );

        $this->assertSame(ReservationStatus::Rejected, $rejected->status);
        $this->assertSame(RejectionReason::FullyBooked, $rejected->rejection_reason_code);
        $this->assertSame('Private tournament that evening.', $rejected->rejection_reason_text);
        Notification::assertSentTo($this->customer, ReservationRejected::class);
    }

    #[Test]
    public function rejecting_releases_the_slot_for_someone_else(): void
    {
        $reservation = $this->pendingRequest();
        $this->service->reject($reservation, $this->owner, RejectionReason::FullyBooked);

        $next = $this->service->request($this->spot, User::factory()->create(), $this->at(19), 60);

        $this->assertTrue($next->isPending());
    }

    /*
    |--------------------------------------------------------------------------
    | Cancellation (SRS 9.3 / 9.4)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_customer_can_cancel_and_the_owner_is_told(): void
    {
        Notification::fake();

        $confirmed = $this->service->approve($this->pendingRequest(), $this->owner);
        $cancelled = $this->service->cancel($confirmed, $this->customer);

        $this->assertSame(ReservationStatus::Cancelled, $cancelled->status);
        $this->assertFalse($cancelled->is_late_cancellation);
        Notification::assertSentTo($this->owner, ReservationCancelled::class);
    }

    #[Test]
    public function a_cancellation_inside_the_cutoff_is_flagged_as_late(): void
    {
        // No financial penalty is possible in V1, so this flag is the entire
        // remedy -- it has to be recorded accurately.
        $reservation = Reservation::factory()->forSpot($this->spot)
            ->at(Carbon::parse('2026-08-01 10:45'), 60)
            ->confirmed()
            ->create(['user_id' => $this->customer->id]);

        $cancelled = $this->service->cancel($reservation, $this->customer);

        $this->assertTrue($cancelled->is_late_cancellation);
    }

    #[Test]
    public function an_owner_cancellation_notifies_the_customer_instead(): void
    {
        Notification::fake();

        $confirmed = $this->service->approve($this->pendingRequest(), $this->owner);
        $this->service->cancel($confirmed, $this->owner, 'Equipment failure');

        // The notification must go to the other party, never to whoever clicked.
        Notification::assertSentTo($this->customer, ReservationCancelled::class);
        Notification::assertNotSentTo($this->owner, ReservationCancelled::class);
    }

    #[Test]
    public function cancelling_frees_the_slot(): void
    {
        $confirmed = $this->service->approve($this->pendingRequest(), $this->owner);
        $this->service->cancel($confirmed, $this->customer);

        $next = $this->service->request($this->spot, User::factory()->create(), $this->at(19), 60);

        $this->assertTrue($next->isPending());
    }

    #[Test]
    public function cancelling_an_already_terminal_booking_is_a_no_op(): void
    {
        $completed = Reservation::factory()->forSpot($this->spot)->completed()->create([
            'user_id' => $this->customer->id,
        ]);

        $result = $this->service->cancel($completed, $this->customer);

        $this->assertSame(ReservationStatus::Completed, $result->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Expiry (FR-4.7)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_unanswered_request_expires_and_both_parties_are_told(): void
    {
        Notification::fake();

        $reservation = $this->pendingRequest();
        $this->travelTo($reservation->response_deadline->copy()->addMinute());

        $expired = $this->service->expire($reservation->fresh());

        $this->assertSame(ReservationStatus::Expired, $expired->status);
        Notification::assertSentTo($this->customer, ReservationExpired::class);
        // The owner should see they lost business by not answering.
        Notification::assertSentTo($this->owner, ReservationExpired::class);
    }

    #[Test]
    public function expiry_does_not_touch_an_already_answered_request(): void
    {
        $confirmed = $this->service->approve($this->pendingRequest(), $this->owner);

        $result = $this->service->expire($confirmed->fresh());

        $this->assertSame(ReservationStatus::Confirmed, $result->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Completion & no-show
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_finished_confirmed_booking_completes(): void
    {
        $confirmed = $this->service->approve($this->pendingRequest(), $this->owner);

        $this->travelTo(Carbon::parse('2026-08-02 21:00'));

        $this->assertSame(ReservationStatus::Completed, $this->service->complete($confirmed->fresh())->status);
    }

    #[Test]
    public function a_booking_still_in_progress_does_not_complete(): void
    {
        $confirmed = $this->service->approve($this->pendingRequest(), $this->owner);

        $this->travelTo(Carbon::parse('2026-08-02 19:30'));

        $this->assertSame(ReservationStatus::Confirmed, $this->service->complete($confirmed->fresh())->status);
    }

    #[Test]
    public function flagging_a_no_show_increments_the_customers_counter(): void
    {
        Notification::fake();

        $confirmed = $this->service->approve($this->pendingRequest(), $this->owner);
        $this->travelTo(Carbon::parse('2026-08-02 21:00'));

        $flagged = $this->service->flagNoShow($confirmed->fresh(), $this->owner);

        $this->assertSame(ReservationStatus::NoShow, $flagged->status);
        $this->assertSame(1, $this->customer->fresh()->no_show_count);
        Notification::assertSentTo($this->customer, ReservationNoShow::class);
    }

    #[Test]
    public function a_no_show_cannot_be_flagged_before_the_booking_ends(): void
    {
        // Flagging a booking that has not happened yet would unfairly mark a
        // customer who still has time to turn up.
        $confirmed = $this->service->approve($this->pendingRequest(), $this->owner);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('after a confirmed booking has ended');

        $this->service->flagNoShow($confirmed->fresh(), $this->owner);
    }

    #[Test]
    public function a_no_show_cannot_be_flagged_on_a_cancelled_booking(): void
    {
        $confirmed = $this->service->approve($this->pendingRequest(), $this->owner);
        $this->service->cancel($confirmed, $this->customer);
        $this->travelTo(Carbon::parse('2026-08-02 21:00'));

        $this->expectException(BookingException::class);

        $this->service->flagNoShow($confirmed->fresh(), $this->owner);
    }
}
