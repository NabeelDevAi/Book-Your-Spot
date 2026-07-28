<?php

namespace Tests\Feature\Owner;

use App\Enums\ConflictResolution;
use App\Enums\ConflictSource;
use App\Enums\ConflictStatus;
use App\Enums\RejectionReason;
use App\Enums\ReservationStatus;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\ReservationConflict;
use App\Models\Spot;
use App\Models\User;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-2.7 / FR-4.6 -- the owner's queue and the decisions they make from it.
 */
class ReservationQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $customer;

    private Spot $spot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));

        $this->owner = User::factory()->owner()->create();
        $this->customer = User::factory()->create();

        $business = Business::factory()->active()->create([
            'owner_id' => $this->owner->id,
            'operating_hours' => OperatingHours::everyDay('00:00', '23:59'),
        ]);

        $this->spot = Spot::factory()->create([
            'business_game_id' => BusinessGame::factory()->create(['business_id' => $business->id])->id,
            'business_id' => $business->id,
        ]);
    }

    private function pending(array $state = []): Reservation
    {
        return Reservation::factory()
            ->forSpot($this->spot)
            ->at(Carbon::parse('2026-08-02 19:00'), 60)
            ->create(array_merge(['user_id' => $this->customer->id], $state));
    }

    /*
    |--------------------------------------------------------------------------
    | The queue
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_queue_lists_the_owners_bookings(): void
    {
        $reservation = $this->pending();

        $this->actingAs($this->owner)
            ->get(route('owner.reservations.index'))
            ->assertOk()
            ->assertSee($reservation->reference)
            ->assertSee($this->customer->name);
    }

    #[Test]
    public function an_owner_never_sees_another_owners_bookings(): void
    {
        $mine = $this->pending();
        $theirs = Reservation::factory()->create();

        $this->actingAs($this->owner)
            ->get(route('owner.reservations.index'))
            ->assertSee($mine->reference)
            ->assertDontSee($theirs->reference);
    }

    #[Test]
    public function an_owner_cannot_open_another_owners_booking(): void
    {
        $this->actingAs($this->owner)
            ->get(route('owner.reservations.show', Reservation::factory()->create()))
            ->assertForbidden();
    }

    #[Test]
    public function the_queue_can_be_filtered_by_status(): void
    {
        $pending = $this->pending();
        $confirmed = $this->pending(['status' => ReservationStatus::Confirmed]);

        $this->actingAs($this->owner)
            ->get(route('owner.reservations.index', ['status' => 'pending']))
            ->assertSee($pending->reference)
            ->assertDontSee($confirmed->reference);
    }

    #[Test]
    public function a_repeat_no_show_customer_is_flagged_in_the_queue(): void
    {
        // SRS 9.12: the deterrent only works if the owner sees it while deciding.
        $offender = User::factory()->repeatNoShow(3)->create();
        $this->pending(['user_id' => $offender->id]);

        $this->actingAs($this->owner)
            ->get(route('owner.reservations.index'))
            ->assertSee('3 no-shows');
    }

    #[Test]
    public function the_detail_page_warns_about_a_repeat_no_show(): void
    {
        $offender = User::factory()->repeatNoShow(3)->create();
        $reservation = $this->pending(['user_id' => $offender->id]);

        $this->actingAs($this->owner)
            ->get(route('owner.reservations.show', $reservation))
            ->assertSee('3 recorded no-shows')
            ->assertSee('Consider calling to confirm');
    }

    /*
    |--------------------------------------------------------------------------
    | Approving & declining
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_owner_can_approve_from_the_queue(): void
    {
        $reservation = $this->pending();

        $this->actingAs($this->owner)
            ->post(route('owner.reservations.approve', $reservation))
            ->assertRedirect();

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    #[Test]
    public function approving_reports_how_many_rivals_were_declined(): void
    {
        $winner = $this->pending(['user_id' => User::factory()->create()->id]);
        $this->pending(['user_id' => User::factory()->create()->id]);
        $this->pending(['user_id' => User::factory()->create()->id]);

        $this->actingAs($this->owner)
            ->post(route('owner.reservations.approve', $winner))
            ->assertSessionHas('success', fn ($message) => str_contains($message, '2 other requests'));
    }

    #[Test]
    public function an_owner_can_decline_with_a_reason(): void
    {
        $reservation = $this->pending();

        $this->actingAs($this->owner)
            ->post(route('owner.reservations.reject', $reservation), [
                'reason_code' => RejectionReason::FullyBooked->value,
                'reason_text' => 'Private tournament.',
            ])
            ->assertRedirect();

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Rejected, $reservation->status);
        $this->assertSame(RejectionReason::FullyBooked, $reservation->rejection_reason_code);
    }

    #[Test]
    public function an_owner_cannot_claim_the_system_only_slot_taken_reason(): void
    {
        // SlotTaken is set by the engine when an approval displaces rivals.
        // Letting an owner pick it by hand would mislead the customer and muddy
        // the audit trail.
        $reservation = $this->pending();

        $this->actingAs($this->owner)
            ->post(route('owner.reservations.reject', $reservation), [
                'reason_code' => RejectionReason::SlotTaken->value,
            ])
            ->assertSessionHasErrors('reason_code');

        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status);
    }

    #[Test]
    public function a_reason_is_required_to_decline(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.reservations.reject', $this->pending()), ['reason_code' => ''])
            ->assertSessionHasErrors('reason_code');
    }

    #[Test]
    public function an_owner_cannot_respond_to_another_owners_request(): void
    {
        $theirs = Reservation::factory()->create();

        $this->actingAs($this->owner)
            ->post(route('owner.reservations.approve', $theirs))
            ->assertForbidden();

        $this->assertSame(ReservationStatus::Pending, $theirs->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Owner cancellation (SRS 9.4) and no-shows (FR-2.8)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_owner_cancellation_requires_a_reason(): void
    {
        // Owner-side cancellations reflect on venue reliability, so "why" is
        // not optional.
        $reservation = $this->pending(['status' => ReservationStatus::Confirmed]);

        $this->actingAs($this->owner)
            ->post(route('owner.reservations.cancel', $reservation), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    #[Test]
    public function an_owner_can_cancel_with_a_reason(): void
    {
        $reservation = $this->pending(['status' => ReservationStatus::Confirmed]);

        $this->actingAs($this->owner)
            ->post(route('owner.reservations.cancel', $reservation), ['reason' => 'Equipment failure'])
            ->assertRedirect();

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Cancelled, $reservation->status);
        $this->assertSame('Equipment failure', $reservation->cancellation_reason);
    }

    #[Test]
    public function a_no_show_cannot_be_recorded_before_the_booking_ends(): void
    {
        $reservation = $this->pending(['status' => ReservationStatus::Confirmed]);

        $this->actingAs($this->owner)
            ->post(route('owner.reservations.no-show', $reservation))
            ->assertForbidden();
    }

    #[Test]
    public function a_no_show_can_be_recorded_afterwards_and_counts_against_the_customer(): void
    {
        $reservation = Reservation::factory()->forSpot($this->spot)
            ->at(Carbon::parse('2026-07-30 19:00'), 60)
            ->confirmed()
            ->create(['user_id' => $this->customer->id]);

        $this->actingAs($this->owner)
            ->post(route('owner.reservations.no-show', $reservation))
            ->assertRedirect();

        $this->assertSame(ReservationStatus::NoShow, $reservation->fresh()->status);
        $this->assertSame(1, $this->customer->fresh()->no_show_count);
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.6 -- the clash queue
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function open_clashes_appear_in_the_queue(): void
    {
        $reservation = $this->pending(['status' => ReservationStatus::Confirmed]);
        ReservationConflict::create([
            'reservation_id' => $reservation->id,
            'source_type' => ConflictSource::SpotBlock,
            'source_id' => 1,
        ]);

        $this->actingAs($this->owner)
            ->get(route('owner.conflicts.index'))
            ->assertOk()
            ->assertSee($reservation->reference)
            ->assertSee('have <strong>not</strong> been cancelled', false);
    }

    #[Test]
    public function resolving_as_cancelled_actually_cancels_the_booking(): void
    {
        $reservation = $this->pending(['status' => ReservationStatus::Confirmed]);
        $conflict = ReservationConflict::create([
            'reservation_id' => $reservation->id,
            'source_type' => ConflictSource::SpotBlock,
            'source_id' => 1,
        ]);

        $this->actingAs($this->owner)
            ->post(route('owner.conflicts.resolve', $conflict), [
                'resolution' => ConflictResolution::Cancelled->value,
                'note' => 'Customer agreed to cancel.',
            ])
            ->assertRedirect();

        // Routed through the state machine, so the cancellation gets its
        // notification and audit entry like any other.
        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
        $this->assertSame(ConflictStatus::Resolved, $conflict->fresh()->status);
    }

    #[Test]
    public function resolving_as_honoured_leaves_the_booking_confirmed(): void
    {
        $reservation = $this->pending(['status' => ReservationStatus::Confirmed]);
        $conflict = ReservationConflict::create([
            'reservation_id' => $reservation->id,
            'source_type' => ConflictSource::SpotBlock,
            'source_id' => 1,
        ]);

        $this->actingAs($this->owner)->post(route('owner.conflicts.resolve', $conflict), [
            'resolution' => ConflictResolution::Kept->value,
        ]);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame(ConflictResolution::Kept, $conflict->fresh()->resolution);
    }

    #[Test]
    public function a_clash_cannot_be_resolved_twice(): void
    {
        $reservation = $this->pending(['status' => ReservationStatus::Confirmed]);
        $conflict = ReservationConflict::create([
            'reservation_id' => $reservation->id,
            'source_type' => ConflictSource::SpotBlock,
            'source_id' => 1,
        ]);

        $this->actingAs($this->owner)->post(route('owner.conflicts.resolve', $conflict), [
            'resolution' => ConflictResolution::Kept->value,
        ]);

        $this->actingAs($this->owner)
            ->post(route('owner.conflicts.resolve', $conflict), [
                'resolution' => ConflictResolution::Cancelled->value,
            ])
            ->assertSessionHas('error');

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    #[Test]
    public function an_owner_cannot_resolve_another_owners_clash(): void
    {
        $theirs = Reservation::factory()->confirmed()->create();
        $conflict = ReservationConflict::create([
            'reservation_id' => $theirs->id,
            'source_type' => ConflictSource::SpotBlock,
            'source_id' => 1,
        ]);

        $this->actingAs($this->owner)
            ->post(route('owner.conflicts.resolve', $conflict), [
                'resolution' => ConflictResolution::Cancelled->value,
            ])
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_dashboard_leads_with_decisions_owed(): void
    {
        $this->pending();
        $this->pending(['user_id' => User::factory()->create()->id]);

        $this->actingAs($this->owner)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee('Awaiting your decision')
            ->assertSee('Needs a decision');
    }

    #[Test]
    public function the_dashboard_shows_todays_bookings(): void
    {
        $today = Reservation::factory()->forSpot($this->spot)
            ->at(Carbon::parse('2026-08-01 20:00'), 60)
            ->confirmed()
            ->create(['user_id' => $this->customer->id]);

        $this->actingAs($this->owner)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee("Today's bookings")
            ->assertSee($today->timeRangeLabel());
    }

    #[Test]
    public function the_dashboard_renders_for_an_owner_with_no_venues(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee('Add your first venue');
    }
}
