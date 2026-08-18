<?php

namespace Tests\Feature;

use App\Enums\BusinessStatus;
use App\Enums\ConflictStatus;
use App\Enums\RejectionReason;
use App\Enums\ReservationStatus;
use App\Enums\SpotStatus;
use App\Exceptions\BookingException;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\SpotBlock;
use App\Models\User;
use App\Services\Admin\BusinessModerationService;
use App\Services\Booking\ConflictService;
use App\Services\Booking\ReservationService;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SRS section 9 -- all twenty edge cases, one named test each.
 *
 * This file exists as an audit artifact for Success Criterion #5: "All 20 edge
 * cases in Section 9 are explicitly handled (not just 'won't crash' -- each has
 * a defined, intentional behaviour)." A green run here is the evidence for that
 * claim, and each test states the intended behaviour rather than merely
 * asserting the absence of an exception.
 *
 * Several cases are covered in more depth by the dedicated suites
 * (ConcurrentApprovalTest, BookingValidationTest, SpotBlockTest,
 * BusinessModerationTest). The point of this file is completeness and
 * traceability, not to replace them.
 */
class EdgeCaseSweepTest extends TestCase
{
    use RefreshDatabase;

    private ReservationService $service;

    private User $customer;

    private User $owner;

    private Business $business;

    private Spot $spot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));

        $this->service = app(ReservationService::class);
        $this->customer = User::factory()->create();

        $this->business = Business::factory()->active()->create([
            'operating_hours' => OperatingHours::everyDay('10:00', '23:00'),
        ]);
        $this->owner = $this->business->owner;

        $this->spot = Spot::factory()->create([
            'business_game_id' => BusinessGame::factory()->create(['business_id' => $this->business->id])->id,
            'business_id' => $this->business->id,
            'price_amount' => 100,
            'price_unit_minutes' => 10,
            'min_duration_minutes' => 30,
        ]);
    }

    private function at(int $hour, int $minute = 0): Carbon
    {
        return Carbon::parse('2026-08-02')->setTime($hour, $minute);
    }

    private function request(?User $user = null, ?Carbon $start = null, int $duration = 60): Reservation
    {
        return $this->service->request($this->spot, $user ?? $this->customer, $start ?? $this->at(19), $duration);
    }

    /*
    |--------------------------------------------------------------------------
    | 9.1 -- Concurrent booking race condition
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_01_only_one_of_two_competing_requests_can_be_confirmed(): void
    {
        // The true concurrency proof lives in ConcurrentApprovalTest, which
        // races real OS processes against MySQL. This asserts the single-process
        // invariant: once one is confirmed, the other cannot be.
        $winner = $this->request(User::factory()->create());
        $loser = $this->request(User::factory()->create());

        $this->service->approve($winner, $this->owner);

        $this->assertSame(ReservationStatus::Confirmed, $winner->fresh()->status);
        $this->assertSame(ReservationStatus::Rejected, $loser->fresh()->status);
        $this->assertSame(RejectionReason::SlotTaken, $loser->fresh()->rejection_reason_code);

        $this->assertSame(
            1,
            Reservation::forSpot($this->spot->id)->confirmed()->count(),
            'Two confirmed reservations on one slot is a double-booking.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 9.2 -- Owner non-response window
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_02_an_unanswered_request_expires_and_releases_the_slot(): void
    {
        $reservation = $this->request();

        // Booked more than 12h ahead, so the fixed-lead branch applies.
        $this->assertSame('2026-08-02 07:00:00', $reservation->response_deadline->format('Y-m-d H:i:s'));

        $this->travelTo($reservation->response_deadline->copy()->addMinute());
        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);

        // The slot is genuinely free again, not merely marked expired.
        $this->assertTrue($this->request(User::factory()->create())->isPending());
    }

    /*
    |--------------------------------------------------------------------------
    | 9.3 -- User cancels after approval, on time vs late
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_03_a_late_cancellation_is_distinguished_from_an_on_time_one(): void
    {
        $onTime = $this->service->approve($this->request(), $this->owner);
        $this->service->cancel($onTime, $this->customer);
        $this->assertFalse($onTime->fresh()->is_late_cancellation);

        // Cutoff is 1 hour before start.
        $late = Reservation::factory()->forSpot($this->spot)
            ->at(Carbon::parse('2026-08-01 10:30'), 60)
            ->confirmed()
            ->create(['user_id' => $this->customer->id]);

        $this->service->cancel($late, $this->customer);
        $this->assertTrue($late->fresh()->is_late_cancellation);
    }

    /*
    |--------------------------------------------------------------------------
    | 9.4 -- Owner cancels a confirmed booking
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_04_an_owner_cancellation_requires_a_reason_and_is_tracked(): void
    {
        $confirmed = $this->service->approve($this->request(), $this->owner);

        // The UI enforces the mandatory reason...
        $this->actingAs($this->owner)
            ->post(route('owner.reservations.cancel', $confirmed), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->owner)
            ->post(route('owner.reservations.cancel', $confirmed), ['reason' => 'Equipment failure']);

        $confirmed->refresh();
        $this->assertSame(ReservationStatus::Cancelled, $confirmed->status);
        $this->assertSame('Equipment failure', $confirmed->cancellation_reason);

        // ...and the role is recorded, so owner-side cancellations can be
        // counted against venue reliability.
        $this->assertSame(\App\Enums\UserRole::Owner, $confirmed->cancelled_by_role);
    }

    /*
    |--------------------------------------------------------------------------
    | 9.5 -- Booking outside operating hours
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_05_a_booking_outside_operating_hours_is_refused(): void
    {
        // Before opening.
        $this->assertBookingRefused(fn () => $this->request(start: $this->at(8)), 'outside opening hours');

        // Starts inside hours but would run past closing.
        $this->assertBookingRefused(fn () => $this->request(start: $this->at(22, 30)), 'outside opening hours');
    }

    /*
    |--------------------------------------------------------------------------
    | 9.6 -- Owner blocks a spot over live bookings
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_06_blocking_over_a_booking_raises_a_conflict_and_never_auto_cancels(): void
    {
        $confirmed = $this->service->approve($this->request(), $this->owner);

        $block = SpotBlock::factory()->forSpot($this->spot)->at($this->at(18), 180)->create([
            'created_by' => $this->owner->id,
        ]);

        app(ConflictService::class)->raiseForBlock($block, $this->owner);

        // The booking survives. Silently cancelling a confirmed customer is the
        // trust-breaking failure the SRS explicitly names.
        $this->assertSame(ReservationStatus::Confirmed, $confirmed->fresh()->status);
        $this->assertTrue($confirmed->fresh()->hasOpenConflict());
    }

    /*
    |--------------------------------------------------------------------------
    | 9.7 -- Duration / pricing unit mismatch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_07_an_invalid_duration_is_refused_with_valid_alternatives(): void
    {
        try {
            $this->request(duration: 37);
            $this->fail('Expected a BookingException.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('10 min blocks', $e->getMessage());
            // "Reject with a clear message and suggested valid durations."
            $this->assertStringContainsString('Try 40 min', $e->getMessage());
            $this->assertContains(40, $e->suggestions);
        }

        $this->assertBookingRefused(fn () => $this->request(duration: 20), 'shortest booking');

        // SRS amendment: there is no owner-set maximum any more -- a duration
        // too long to fit in the day is refused for being outside operating
        // hours, not against some configured ceiling. The venue here is open
        // 10:00-23:00 (13 hours); 900 minutes (15 hours) from noon runs well
        // past close.
        $this->assertBookingRefused(fn () => $this->request(start: $this->at(12), duration: 900), 'outside opening hours');
    }

    /*
    |--------------------------------------------------------------------------
    | 9.8 -- Business suspended with future bookings
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_08_suspension_resolves_every_future_booking_and_leaves_history_alone(): void
    {
        $admin = User::factory()->admin()->create();

        $confirmed = $this->service->approve($this->request(User::factory()->create()), $this->owner);
        $pending = $this->request(User::factory()->create(), $this->at(21));
        $past = Reservation::factory()->forSpot($this->spot)
            ->at(Carbon::parse('2026-07-20 19:00'), 60)
            ->completed()
            ->create();

        app(BusinessModerationService::class)->suspend($this->business, $admin, 'Fraud investigation');

        $this->assertSame(ReservationStatus::Cancelled, $confirmed->fresh()->status);
        $this->assertSame(ReservationStatus::Rejected, $pending->fresh()->status);
        $this->assertSame(RejectionReason::VenueUnavailable, $pending->fresh()->rejection_reason_code);

        // Nothing is silently orphaned...
        $this->assertSame(0, Reservation::where('business_id', $this->business->id)->open()->count());
        // ...and completed history is not rewritten.
        $this->assertSame(ReservationStatus::Completed, $past->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | 9.9 -- Deleting or deactivating a spot with history
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_09_a_spot_with_history_is_deactivated_never_deleted(): void
    {
        $this->service->approve($this->request(), $this->owner);

        $this->assertFalse($this->spot->fresh()->canBeHardDeleted());

        $this->actingAs($this->owner)
            ->delete(route('owner.businesses.spots.destroy', [$this->business, $this->spot]))
            ->assertRedirect();

        $this->assertDatabaseHas('spots', ['id' => $this->spot->id, 'deleted_at' => null]);

        // A spot that has never been booked may still be removed outright.
        $unused = Spot::factory()->create([
            'business_game_id' => $this->spot->business_game_id,
            'business_id' => $this->business->id,
        ]);
        $this->actingAs($this->owner)
            ->delete(route('owner.businesses.spots.destroy', [$this->business, $unused]));
        $this->assertDatabaseMissing('spots', ['id' => $unused->id]);
    }

    #[Test]
    public function case_09b_deactivating_a_spot_flags_future_bookings_for_the_owner(): void
    {
        $confirmed = $this->service->approve($this->request(), $this->owner);

        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.deactivate', [$this->business, $this->spot]));

        $this->assertSame(SpotStatus::Inactive, $this->spot->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $confirmed->fresh()->status);
        $this->assertTrue($confirmed->fresh()->hasOpenConflict());
    }

    /*
    |--------------------------------------------------------------------------
    | 9.10 -- Price change on a spot
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_10_a_price_change_applies_only_to_new_bookings(): void
    {
        $existing = $this->service->approve($this->request(), $this->owner);
        $originalTotal = (float) $existing->total_price;

        $this->spot->update(['price_amount' => 500]);

        $this->assertEqualsWithDelta($originalTotal, (float) $existing->fresh()->total_price, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $existing->fresh()->price_amount_snapshot, 0.001);

        $new = $this->request(User::factory()->create(), $this->at(21));
        $this->assertEqualsWithDelta(3000.0, (float) $new->total_price, 0.001);
    }

    /*
    |--------------------------------------------------------------------------
    | 9.11 -- Duplicate / spam business registration
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_11_a_duplicate_registration_is_flagged_for_review_not_blocked(): void
    {
        $owner = User::factory()->owner()->create();
        $hours = collect(OperatingHours::DAYS)
            ->mapWithKeys(fn ($d) => [$d => ['ranges' => [['open' => '10:00', 'close' => '22:00']]]])
            ->all();

        $this->actingAs($owner)->post(route('owner.businesses.store'), [
            'name' => 'Copycat Venue',
            'address' => $this->business->address,
            'area' => $this->business->area,
            'city' => 'Karachi',
            'contact_number' => $this->business->contact_number,
            'operating_hours' => $hours,
        ])->assertSessionHasNoErrors();

        $created = Business::where('name', 'Copycat Venue')->sole();

        // Flagged, not rejected: two venues in adjacent units may genuinely
        // share a landline, so an Admin decides rather than the system.
        $this->assertTrue($created->duplicate_flagged);
        $this->assertSame(BusinessStatus::PendingReview, $created->status);
    }

    /*
    |--------------------------------------------------------------------------
    | 9.12 -- Repeat no-show users
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_12_a_repeat_no_show_is_visible_to_the_owner_at_approval_time(): void
    {
        $offender = User::factory()->repeatNoShow(2)->create();
        $reservation = $this->request($offender);

        $this->assertTrue($offender->isRepeatNoShow());

        // Surfaced in the queue and on the decision page, which is the entire
        // deterrent available without payments.
        $this->actingAs($this->owner)
            ->get(route('owner.reservations.index'))
            ->assertSee('2 no-shows');

        $this->actingAs($this->owner)
            ->get(route('owner.reservations.show', $reservation))
            ->assertSee('2 recorded no-shows');

        // And it is visible to Admin (FR-3.4).
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.users.show', $offender))
            ->assertSee('No-show flags');
    }

    /*
    |--------------------------------------------------------------------------
    | 9.13 -- Guest browsing vs booking
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_13_guests_see_full_pricing_but_are_asked_to_sign_in_to_book(): void
    {
        $this->get('/')->assertOk()->assertSee('Rs. 100 / 10 min');

        $this->get(route('businesses.show', $this->business))
            ->assertOk()
            ->assertSee($this->spot->name)
            ->assertSee('Rs. 100 / 10 min');

        // The booking form is fully visible -- prices are never hidden.
        $this->get(route('bookings.create', $this->spot))
            ->assertOk()
            ->assertSee('Sign in to request')
            ->assertDontSee('Send request');

        // Only submission is gated, and it never silently fails.
        $this->post(route('bookings.store', $this->spot), [
            'start_datetime' => $this->at(19)->format('Y-m-d H:i'),
            'duration_minutes' => 60,
        ])->assertRedirect('/login');
    }

    /*
    |--------------------------------------------------------------------------
    | 9.14 -- Owner booking their own spot
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_14_owners_cannot_book_and_are_pointed_at_spot_blocks(): void
    {
        // Blocked in the engine...
        $this->assertBookingRefused(
            fn () => $this->request($this->owner),
            'Owner accounts can\'t make bookings'
        );

        // ...and at the routing layer.
        $this->actingAs($this->owner)
            ->post(route('bookings.store', $this->spot), [
                'start_datetime' => $this->at(19)->format('Y-m-d H:i'),
                'duration_minutes' => 60,
            ])
            ->assertForbidden();

        // The supported alternative is offered rather than a bare refusal.
        $this->actingAs($this->owner)
            ->get(route('bookings.create', $this->spot))
            ->assertSee('Blocked times');
    }

    /*
    |--------------------------------------------------------------------------
    | 9.15 -- Invalid / past date-time selection
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_15_a_past_or_too_soon_start_is_refused_server_side(): void
    {
        // "Never trust client-side date pickers alone" -- these go straight to
        // the engine, bypassing any UI validation.
        $this->assertBookingRefused(
            fn () => $this->request(start: Carbon::parse('2026-07-31 19:00')),
            'already passed'
        );

        $this->assertBookingRefused(
            fn () => $this->request(start: Carbon::parse('2026-08-01 10:20')),
            'notice'
        );

        $this->assertBookingRefused(
            fn () => $this->request(start: Carbon::parse('2026-10-01 19:00')),
            'days in advance'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 9.16 -- Empty search results
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_16_empty_results_explain_themselves_and_offer_a_way_out(): void
    {
        $this->get('/?area=Nowhere')
            ->assertOk()
            ->assertSee('No venues match those filters')
            ->assertSee('Show all venues');

        // The availability variant gives advice specific to the time filter.
        Reservation::factory()->forSpot($this->spot)
            ->at($this->at(10), 780)
            ->confirmed()
            ->create();

        $this->get('/?date=2026-08-02')
            ->assertOk()
            ->assertSee('Nothing is free at that time');
    }

    /*
    |--------------------------------------------------------------------------
    | 9.17 -- Business with zero active spots
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_17_an_active_business_with_nothing_bookable_is_hidden(): void
    {
        $empty = Business::factory()->active()->create(['name' => 'Nothing Bookable Here']);

        $this->get('/')->assertOk()->assertDontSee('Nothing Bookable Here');
        $this->get(route('businesses.show', $empty))->assertNotFound();

        // Deactivating the only spot has the same effect on a live venue.
        $this->spot->update(['status' => SpotStatus::Inactive]);
        $this->assertSame(0, Business::bookable()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | 9.18 -- Multiple pending reservations by one user
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_18_a_customer_is_capped_at_three_open_requests(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $spot = Spot::factory()->create([
                'business_game_id' => $this->spot->business_game_id,
                'business_id' => $this->business->id,
            ]);
            $this->service->request($spot, $this->customer, $this->at(19), 60);
        }

        $this->assertBookingRefused(
            fn () => $this->request(start: $this->at(21)),
            'already have 3 requests'
        );

        // Answered requests do not count -- only genuinely open ones tie up an
        // owner's queue.
        Reservation::where('user_id', $this->customer->id)
            ->limit(1)
            ->update(['status' => ReservationStatus::Rejected->value]);

        $this->assertTrue($this->request(start: $this->at(21))->isPending());
    }

    /*
    |--------------------------------------------------------------------------
    | 9.19 -- Rejection reason handling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_19_rejection_offers_preset_reasons_plus_optional_free_text(): void
    {
        $reservation = $this->request();

        // A preset set exists, and system-only reasons are excluded from it.
        $selectable = RejectionReason::ownerSelectable();
        $this->assertNotEmpty($selectable);
        $this->assertNotContains(RejectionReason::SlotTaken, $selectable);
        $this->assertNotContains(RejectionReason::VenueUnavailable, $selectable);

        $this->actingAs($this->owner)->post(route('owner.reservations.reject', $reservation), [
            'reason_code' => RejectionReason::FullyBooked->value,
            'reason_text' => 'Private tournament that evening.',
        ]);

        $reservation->refresh();
        $this->assertSame(RejectionReason::FullyBooked, $reservation->rejection_reason_code);
        $this->assertSame('Private tournament that evening.', $reservation->rejection_reason_text);

        // Free text is genuinely optional.
        $second = $this->request(User::factory()->create());
        $this->actingAs($this->owner)->post(route('owner.reservations.reject', $second), [
            'reason_code' => RejectionReason::Maintenance->value,
        ])->assertSessionHasNoErrors();
    }

    /*
    |--------------------------------------------------------------------------
    | 9.20 -- Admin override edits are logged
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function case_20_an_admin_override_is_refused_without_a_reason_and_logged_with_one(): void
    {
        $admin = User::factory()->admin()->create();
        $hours = collect(OperatingHours::DAYS)
            ->mapWithKeys(fn ($d) => [$d => ['ranges' => [['open' => '10:00', 'close' => '22:00']]]])
            ->all();

        $payload = [
            'name' => 'Admin Corrected Name',
            'address' => $this->business->address,
            'area' => $this->business->area,
            'city' => $this->business->city,
            'contact_number' => $this->business->contact_number,
            'operating_hours' => $hours,
        ];

        $this->actingAs($admin)
            ->put(route('admin.businesses.update', $this->business), $payload)
            ->assertSessionHasErrors('override_reason');

        $this->actingAs($admin)->put(
            route('admin.businesses.update', $this->business),
            $payload + ['override_reason' => 'Corrected after a customer dispute.']
        );

        $log = \App\Models\AuditLog::where('action', \App\Services\AuditLogger::BUSINESS_OVERRIDDEN)->sole();

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('Corrected after a customer dispute.', $log->reason);
        $this->assertSame('Admin Corrected Name', $log->meta['changes']['after']['name']);
        $this->assertNotNull($log->created_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    private function assertBookingRefused(callable $attempt, string $expectedMessage): void
    {
        try {
            $attempt();
            $this->fail("Expected the booking to be refused with a message containing \"{$expectedMessage}\".");
        } catch (BookingException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
        }
    }
}
