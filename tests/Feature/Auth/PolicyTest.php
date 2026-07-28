<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Authorisation policies, including the Gate::before admin override (FR-3.6).
 */
class PolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_owner_can_manage_only_their_own_business(): void
    {
        $owner = User::factory()->owner()->create();
        $stranger = User::factory()->owner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        $this->assertTrue($owner->can('manage', $business));
        $this->assertFalse($stranger->can('manage', $business));
    }

    #[Test]
    public function a_customer_cannot_manage_any_business(): void
    {
        $customer = User::factory()->create();
        $business = Business::factory()->create();

        $this->assertFalse($customer->can('manage', $business));
        $this->assertFalse($customer->can('update', $business));
        $this->assertFalse($customer->can('create', Business::class));
    }

    #[Test]
    public function an_admin_overrides_every_policy(): void
    {
        // Gate::before returns true for admins so no individual policy has to
        // remember an isAdmin() branch. A single forgotten check would silently
        // deny Admin a moderation action they are required to have (FR-3.6).
        $admin = User::factory()->admin()->create();
        $business = Business::factory()->create();
        $spot = Spot::factory()->create();
        $reservation = Reservation::factory()->create();

        $this->assertTrue($admin->can('manage', $business));
        $this->assertTrue($admin->can('moderate', $business));
        $this->assertTrue($admin->can('update', $spot));
        $this->assertTrue($admin->can('view', $reservation));
        $this->assertTrue($admin->can('cancel', $reservation));
    }

    #[Test]
    public function only_an_admin_can_moderate_a_business(): void
    {
        $owner = User::factory()->owner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        // An owner approving their own venue would defeat the whole point of
        // the review queue (FR-3.1).
        $this->assertFalse($owner->can('moderate', $business));
    }

    /*
    |--------------------------------------------------------------------------
    | Spots
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_spot_with_reservation_history_cannot_be_hard_deleted_even_by_its_owner(): void
    {
        // SRS 9.9 -- history is never destroyed, only deactivated.
        $spot = Spot::factory()->create();
        $owner = $spot->business->loadMissing('owner')->owner;

        $this->assertTrue($owner->can('delete', $spot));

        Reservation::factory()->forSpot($spot)->completed()->create();

        $this->assertFalse($owner->can('delete', $spot->fresh()));
        $this->assertTrue($owner->can('deactivate', $spot->fresh()));
    }

    /*
    |--------------------------------------------------------------------------
    | Reservations
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function both_the_customer_and_the_venue_owner_can_view_a_reservation(): void
    {
        $reservation = Reservation::factory()->create();
        $customer = $reservation->user;
        $owner = $reservation->business->loadMissing('owner')->owner;
        $stranger = User::factory()->create();

        $this->assertTrue($customer->can('view', $reservation));
        $this->assertTrue($owner->can('view', $reservation));
        $this->assertFalse($stranger->can('view', $reservation));
    }

    #[Test]
    public function only_the_venue_owner_may_respond_and_only_while_pending(): void
    {
        $reservation = Reservation::factory()->create();
        $owner = $reservation->business->loadMissing('owner')->owner;
        $customer = $reservation->user;

        $this->assertTrue($owner->can('respond', $reservation));
        $this->assertFalse($customer->can('respond', $reservation));

        // Re-approving a cancelled booking would resurrect a slot the customer
        // has already walked away from.
        $cancelled = Reservation::factory()->cancelled()->create();
        $this->assertFalse($cancelled->business->loadMissing('owner')->owner->can('respond', $cancelled));
    }

    #[Test]
    public function a_no_show_can_only_be_flagged_after_the_booking_has_ended(): void
    {
        // FR-2.8. Flagging a booking that has not happened yet would unfairly
        // mark the customer for something they still have time to do.
        $future = Reservation::factory()->at(Carbon::tomorrow()->setTime(19, 0))->confirmed()->create();
        $this->assertFalse($future->business->loadMissing('owner')->owner->can('flagNoShow', $future));

        $past = Reservation::factory()->past()->confirmed()->create();
        $this->assertTrue($past->business->loadMissing('owner')->owner->can('flagNoShow', $past));
    }

    #[Test]
    public function a_no_show_cannot_be_flagged_on_a_booking_that_was_never_confirmed(): void
    {
        $expired = Reservation::factory()->past()->expired()->create();

        $this->assertFalse($expired->business->loadMissing('owner')->owner->can('flagNoShow', $expired));
    }

    #[Test]
    public function a_customer_and_the_owner_can_both_cancel_but_a_stranger_cannot(): void
    {
        // SRS 9.3 and 9.4 -- either party may cancel; the owner needs a reason.
        $reservation = Reservation::factory()->confirmed()->create();

        $this->assertTrue($reservation->user->can('cancel', $reservation));
        $this->assertTrue($reservation->business->loadMissing('owner')->owner->can('cancel', $reservation));
        $this->assertFalse(User::factory()->create()->can('cancel', $reservation));
    }

    #[Test]
    public function a_terminal_reservation_cannot_be_cancelled_by_anyone(): void
    {
        $completed = Reservation::factory()->completed()->create();

        $this->assertFalse($completed->user->can('cancel', $completed));
        $this->assertFalse($completed->business->loadMissing('owner')->owner->can('cancel', $completed));
    }
}
