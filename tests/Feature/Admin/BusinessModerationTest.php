<?php

namespace Tests\Feature\Admin;

use App\Enums\BusinessStatus;
use App\Enums\ConflictSource;
use App\Enums\ConflictStatus;
use App\Enums\RejectionReason;
use App\Enums\ReservationStatus;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Notifications\BusinessApproved;
use App\Notifications\BusinessRejected;
use App\Notifications\BusinessSuspended;
use App\Notifications\ReservationCancelled;
use App\Notifications\ReservationRejected;
use App\Services\AuditLogger;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-3.1 / FR-3.2, and the SRS 9.8 suspension cascade.
 */
class BusinessModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Business $business;

    private Spot $spot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));

        $this->admin = User::factory()->admin()->create();

        $this->business = Business::factory()->pendingReview()->create([
            'operating_hours' => OperatingHours::everyDay('00:00', '23:59'),
        ]);

        $this->spot = Spot::factory()->create([
            'business_game_id' => BusinessGame::factory()->create(['business_id' => $this->business->id])->id,
            'business_id' => $this->business->id,
        ]);
    }

    private function activate(): void
    {
        $this->business->forceFill(['status' => BusinessStatus::Active])->save();
    }

    private function booking(Carbon $start, ReservationStatus $status = ReservationStatus::Confirmed): Reservation
    {
        return Reservation::factory()
            ->forSpot($this->spot)
            ->at($start, 60)
            ->create(['status' => $status, 'user_id' => User::factory()->create()->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Approval & rejection
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_admin_can_approve_a_venue(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.businesses.approve', $this->business))
            ->assertRedirect();

        $this->business->refresh();
        $this->assertSame(BusinessStatus::Active, $this->business->status);
        $this->assertSame($this->admin->id, $this->business->reviewed_by);
        Notification::assertSentTo($this->business->owner, BusinessApproved::class);
    }

    #[Test]
    public function approving_clears_a_duplicate_flag(): void
    {
        // Approving IS the judgement that this is not a duplicate, so leaving
        // the flag up would nag about a decision already made.
        $this->business->forceFill([
            'duplicate_flagged' => true,
            'duplicate_note' => 'Phone matches another listing.',
        ])->save();

        $this->actingAs($this->admin)->post(route('admin.businesses.approve', $this->business));

        $this->assertFalse($this->business->fresh()->duplicate_flagged);
    }

    #[Test]
    public function rejecting_requires_a_reason_and_sends_it_to_the_owner(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.businesses.reject', $this->business), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->admin)
            ->post(route('admin.businesses.reject', $this->business), ['reason' => 'Address unverifiable.'])
            ->assertRedirect();

        $this->business->refresh();
        $this->assertSame(BusinessStatus::Rejected, $this->business->status);
        $this->assertSame('Address unverifiable.', $this->business->rejection_reason);

        Notification::assertSentTo($this->business->owner, BusinessRejected::class,
            fn (BusinessRejected $n) => str_contains($n->toArray($this->business->owner)['body'], 'Address unverifiable.'));
    }

    #[Test]
    public function a_venue_becomes_visible_in_search_only_after_approval(): void
    {
        $this->get('/')->assertDontSee($this->business->name);

        $this->actingAs($this->admin)->post(route('admin.businesses.approve', $this->business));

        $this->get('/')->assertSee($this->business->name);
    }

    #[Test]
    public function only_an_admin_can_moderate(): void
    {
        foreach ([User::factory()->create(), $this->business->owner] as $user) {
            $this->actingAs($user)
                ->post(route('admin.businesses.approve', $this->business))
                ->assertForbidden();
        }

        $this->assertSame(BusinessStatus::PendingReview, $this->business->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.8 -- the suspension cascade
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function suspending_hides_the_venue_immediately(): void
    {
        $this->activate();
        $this->get('/')->assertSee($this->business->name);

        $this->actingAs($this->admin)->post(route('admin.businesses.suspend', $this->business), [
            'reason' => 'Multiple complaints.',
        ]);

        $this->assertSame(BusinessStatus::Suspended, $this->business->fresh()->status);

        // Assert the listing itself is gone rather than the bare name: the
        // success flash from the redirect quotes the venue name back, so a
        // naive assertDontSee($name) matches the confirmation message and fails
        // even though the search page is correct.
        $this->get('/')
            ->assertOk()
            ->assertDontSee(route('businesses.show', $this->business))
            ->assertSee('No venues match those filters');
    }

    #[Test]
    public function suspending_cancels_future_confirmed_bookings_and_tells_the_customers(): void
    {
        // The core of SRS 9.8: bookings must never be left silently orphaned.
        Notification::fake();
        $this->activate();

        $booking = $this->booking(Carbon::parse('2026-08-05 19:00'));

        $this->actingAs($this->admin)->post(route('admin.businesses.suspend', $this->business), [
            'reason' => 'Multiple complaints.',
        ]);

        $booking->refresh();
        $this->assertSame(ReservationStatus::Cancelled, $booking->status);
        $this->assertStringContainsString('temporarily unavailable', $booking->cancellation_reason);
        Notification::assertSentTo($booking->user, ReservationCancelled::class);
    }

    #[Test]
    public function a_cascaded_cancellation_does_not_blame_the_customer(): void
    {
        // Regression: the notification originally only distinguished owner from
        // customer, so an admin cascade told people "The customer cancelled"
        // about their own booking.
        $this->activate();
        $booking = $this->booking(Carbon::parse('2026-08-05 19:00'));

        $this->actingAs($this->admin)->post(route('admin.businesses.suspend', $this->business), [
            'reason' => 'Complaints.',
        ]);

        $payload = $booking->user->fresh()->notifications()
            ->where('type', ReservationCancelled::class)
            ->sole()
            ->data;

        $this->assertStringNotContainsString('The customer cancelled', $payload['body']);
        $this->assertStringContainsString('temporarily unavailable', $payload['body']);
        $this->assertStringContainsString('venue unavailable', $payload['title']);
    }

    #[Test]
    public function suspending_rejects_future_pending_requests_with_a_venue_reason(): void
    {
        // A pending request was never promised, so it is declined rather than
        // cancelled -- and the customer is freed to look elsewhere at once.
        Notification::fake();
        $this->activate();

        $pending = $this->booking(Carbon::parse('2026-08-05 19:00'), ReservationStatus::Pending);

        $this->actingAs($this->admin)->post(route('admin.businesses.suspend', $this->business), [
            'reason' => 'Multiple complaints.',
        ]);

        $pending->refresh();
        $this->assertSame(ReservationStatus::Rejected, $pending->status);
        $this->assertSame(RejectionReason::VenueUnavailable, $pending->rejection_reason_code);
        Notification::assertSentTo($pending->user, ReservationRejected::class);
    }

    #[Test]
    public function suspending_leaves_past_bookings_untouched(): void
    {
        // Those evenings happened. Rewriting completed history to explain a
        // suspension today would be dishonest bookkeeping.
        $this->activate();

        $past = $this->booking(Carbon::parse('2026-07-20 19:00'), ReservationStatus::Completed);
        $alsoPast = $this->booking(Carbon::parse('2026-07-25 19:00'), ReservationStatus::NoShow);

        $this->actingAs($this->admin)->post(route('admin.businesses.suspend', $this->business), [
            'reason' => 'Multiple complaints.',
        ]);

        $this->assertSame(ReservationStatus::Completed, $past->fresh()->status);
        $this->assertSame(ReservationStatus::NoShow, $alsoPast->fresh()->status);
    }

    #[Test]
    public function every_cascaded_booking_leaves_a_resolved_conflict_behind(): void
    {
        $this->activate();
        $booking = $this->booking(Carbon::parse('2026-08-05 19:00'));

        $this->actingAs($this->admin)->post(route('admin.businesses.suspend', $this->business), [
            'reason' => 'Fraud investigation.',
        ]);

        $conflict = $booking->conflicts()->sole();
        $this->assertSame(ConflictSource::BusinessSuspension, $conflict->source_type);
        // Resolved, not open: unlike an owner blocking their own spot, there is
        // nothing for anyone to decide here.
        $this->assertSame(ConflictStatus::Resolved, $conflict->status);
    }

    #[Test]
    public function the_owner_is_told_how_many_bookings_were_cancelled(): void
    {
        Notification::fake();
        $this->activate();

        $this->booking(Carbon::parse('2026-08-05 19:00'));
        $this->booking(Carbon::parse('2026-08-06 19:00'));

        $this->actingAs($this->admin)->post(route('admin.businesses.suspend', $this->business), [
            'reason' => 'Complaints.',
        ]);

        Notification::assertSentTo($this->business->owner, BusinessSuspended::class,
            fn (BusinessSuspended $n) => $n->cancelledBookings === 2);
    }

    #[Test]
    public function suspension_requires_a_reason(): void
    {
        $this->activate();

        $this->actingAs($this->admin)
            ->post(route('admin.businesses.suspend', $this->business), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(BusinessStatus::Active, $this->business->fresh()->status);
    }

    #[Test]
    public function reinstating_does_not_resurrect_cancelled_bookings(): void
    {
        // Those customers were told it was off and have made other plans.
        // Silently reinstating is worse than the cancellation was.
        $this->activate();
        $booking = $this->booking(Carbon::parse('2026-08-05 19:00'));

        $this->actingAs($this->admin)->post(route('admin.businesses.suspend', $this->business), [
            'reason' => 'Complaints.',
        ]);
        $this->actingAs($this->admin)->post(route('admin.businesses.reinstate', $this->business));

        $this->assertSame(BusinessStatus::Active, $this->business->fresh()->status);
        $this->assertSame(ReservationStatus::Cancelled, $booking->fresh()->status);
    }

    #[Test]
    public function moderation_actions_are_audited(): void
    {
        $this->activate();

        $this->actingAs($this->admin)->post(route('admin.businesses.suspend', $this->business), [
            'reason' => 'Fraud investigation.',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogger::BUSINESS_SUSPENDED,
            'actor_id' => $this->admin->id,
            'reason' => 'Fraud investigation.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | FR-3.6 -- override edit (SRS 9.20)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_override_edit_requires_a_reason_and_is_logged(): void
    {
        $this->activate();

        $payload = [
            'name' => 'Corrected Venue Name',
            'address' => $this->business->address,
            'area' => $this->business->area,
            'city' => $this->business->city,
            'contact_number' => '+922135551234',
            'operating_hours' => collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
                ->mapWithKeys(fn ($d) => [$d => ['ranges' => [['open' => '10:00', 'close' => '22:00']]]])
                ->all(),
        ];

        // Without a reason the change is refused outright -- this is the exact
        // scenario SRS 9.20 exists for.
        $this->actingAs($this->admin)
            ->put(route('admin.businesses.update', $this->business), $payload)
            ->assertSessionHasErrors('override_reason');

        $this->assertNotSame('Corrected Venue Name', $this->business->fresh()->name);

        $this->actingAs($this->admin)->put(
            route('admin.businesses.update', $this->business),
            $payload + ['override_reason' => 'Owner reported a typo over the phone.']
        )->assertRedirect();

        $this->assertSame('Corrected Venue Name', $this->business->fresh()->name);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogger::BUSINESS_OVERRIDDEN,
            'actor_id' => $this->admin->id,
            'reason' => 'Owner reported a typo over the phone.',
        ]);
    }

    #[Test]
    public function the_override_log_records_what_actually_changed(): void
    {
        $this->activate();
        $originalName = $this->business->name;

        $this->actingAs($this->admin)->put(route('admin.businesses.update', $this->business), [
            'name' => 'New Name',
            'address' => $this->business->address,
            'area' => $this->business->area,
            'city' => $this->business->city,
            'contact_number' => $this->business->contact_number,
            'operating_hours' => collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
                ->mapWithKeys(fn ($d) => [$d => ['ranges' => [['open' => '10:00', 'close' => '22:00']]]])
                ->all(),
            'override_reason' => 'Rebrand confirmed with the owner.',
        ]);

        $log = \App\Models\AuditLog::where('action', AuditLogger::BUSINESS_OVERRIDDEN)->sole();

        $this->assertSame($originalName, $log->meta['changes']['before']['name']);
        $this->assertSame('New Name', $log->meta['changes']['after']['name']);
    }

    /*
    |--------------------------------------------------------------------------
    | FR-3.8 -- create on behalf
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_admin_can_create_a_venue_for_an_owner_and_it_goes_live(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($this->admin)->post(route('admin.businesses.store'), [
            'owner_id' => $owner->id,
            'name' => 'Phoned-In Venue',
            'address' => '1 Test Road',
            'area' => 'Clifton',
            'city' => 'Karachi',
            'contact_number' => '+922135559999',
            'operating_hours' => collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
                ->mapWithKeys(fn ($d) => [$d => ['ranges' => [['open' => '10:00', 'close' => '22:00']]]])
                ->all(),
        ])->assertRedirect();

        $created = Business::where('name', 'Phoned-In Venue')->sole();

        $this->assertSame($owner->id, $created->owner_id);
        // The admin has already vetted it in person, so it skips its own queue.
        $this->assertSame(BusinessStatus::Active, $created->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.created_on_behalf']);
    }

    #[Test]
    public function a_venue_cannot_be_created_for_a_customer_account(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.businesses.store'), [
            'owner_id' => $customer->id,
            'name' => 'Wrong Owner',
            'address' => '1 Test Road',
            'area' => 'Clifton',
            'city' => 'Karachi',
            'contact_number' => '+922135559999',
            'operating_hours' => collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
                ->mapWithKeys(fn ($d) => [$d => ['ranges' => [['open' => '10:00', 'close' => '22:00']]]])
                ->all(),
        ])->assertNotFound();

        $this->assertDatabaseMissing('businesses', ['name' => 'Wrong Owner']);
    }
}
