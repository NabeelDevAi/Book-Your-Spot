<?php

namespace Tests\Feature\DataLayer;

use App\Enums\BusinessStatus;
use App\Enums\SpotStatus;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BusinessVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function businessWithSpot(callable $configure = null): Business
    {
        $business = Business::factory()->active()->create();
        $businessGame = BusinessGame::factory()->create(['business_id' => $business->id]);

        $spot = Spot::factory()->create([
            'business_game_id' => $businessGame->id,
            'business_id' => $business->id,
        ]);

        if ($configure) {
            $configure($business, $spot);
        }

        return $business->fresh();
    }

    #[Test]
    public function an_active_business_with_an_active_spot_is_bookable(): void
    {
        $this->businessWithSpot();

        $this->assertSame(1, Business::bookable()->count());
    }

    #[Test]
    public function an_active_business_with_no_spots_is_hidden_from_search(): void
    {
        // SRS 9.17: there is nothing for a user to act on, so showing it is
        // just a dead end.
        Business::factory()->active()->create();

        $this->assertSame(1, Business::active()->count());
        $this->assertSame(0, Business::bookable()->count());
    }

    #[Test]
    public function an_active_business_whose_only_spot_is_inactive_is_hidden(): void
    {
        $this->businessWithSpot(fn ($business, Spot $spot) => $spot->update(['status' => SpotStatus::Inactive]));

        $this->assertSame(0, Business::bookable()->count());
    }

    #[Test]
    public function a_pending_business_is_never_publicly_visible(): void
    {
        // FR-2.2: new submissions must not reach users before Admin approves.
        $business = Business::factory()->pendingReview()->create();
        $businessGame = BusinessGame::factory()->create(['business_id' => $business->id]);
        Spot::factory()->create([
            'business_game_id' => $businessGame->id,
            'business_id' => $business->id,
        ]);

        $this->assertSame(0, Business::bookable()->count());
    }

    #[Test]
    public function a_suspended_business_disappears_from_search_immediately(): void
    {
        $business = $this->businessWithSpot();

        $this->assertSame(1, Business::bookable()->count());

        // Status is deliberately NOT mass-assignable -- every transition runs
        // through an admin action that writes an audit log entry, so it is set
        // explicitly here rather than through update().
        $business->status = BusinessStatus::Suspended;
        $business->save();

        $this->assertSame(0, Business::bookable()->count());
    }

    #[Test]
    public function only_an_active_business_accepts_bookings(): void
    {
        $this->assertTrue(Business::factory()->active()->create()->acceptsBookings());
        $this->assertFalse(Business::factory()->pendingReview()->create()->acceptsBookings());
        $this->assertFalse(Business::factory()->suspended()->create()->acceptsBookings());
        $this->assertFalse(Business::factory()->rejected()->create()->acceptsBookings());
    }

    #[Test]
    public function a_spot_is_only_bookable_when_its_business_is_too(): void
    {
        $business = Business::factory()->pendingReview()->create();
        $businessGame = BusinessGame::factory()->create(['business_id' => $business->id]);
        $spot = Spot::factory()->create([
            'business_game_id' => $businessGame->id,
            'business_id' => $business->id,
        ]);

        $this->assertTrue($spot->isActive(), 'The spot itself is active...');
        $this->assertFalse($spot->isBookable(), '...but its business is not approved yet.');
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.9 -- deletion rules
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_spot_with_no_reservation_history_may_be_hard_deleted(): void
    {
        $spot = Spot::factory()->create();

        $this->assertTrue($spot->canBeHardDeleted());
    }

    #[Test]
    public function a_spot_with_any_reservation_history_may_not_be_hard_deleted(): void
    {
        $spot = Spot::factory()->create();
        // Even a cancelled booking is history worth keeping.
        Reservation::factory()->forSpot($spot)->cancelled()->create();

        $this->assertFalse($spot->fresh()->canBeHardDeleted());
    }

    #[Test]
    public function a_soft_deleted_business_keeps_its_slug_reserved(): void
    {
        // The unique index does not know about deleted_at, so a new venue with
        // the same name must not collide with a soft-deleted one.
        $first = Business::factory()->create(['name' => 'Cue Masters']);
        $first->delete();

        $second = Business::factory()->create(['name' => 'Cue Masters']);

        $this->assertSame('cue-masters', $first->slug);
        $this->assertSame('cue-masters-2', $second->slug);
    }

    #[Test]
    public function an_owner_owns_only_their_own_businesses(): void
    {
        $owner = User::factory()->owner()->create();
        $other = User::factory()->owner()->create();

        $business = Business::factory()->create(['owner_id' => $owner->id]);

        $this->assertTrue($owner->ownsBusiness($business));
        $this->assertFalse($other->ownsBusiness($business));
    }

    #[Test]
    public function owners_and_admins_cannot_book(): void
    {
        // SRS 9.14, resolved by blocking outright: owners use the spot-block
        // feature for personal use of their own venue.
        $this->assertTrue(User::factory()->create()->canBook());
        $this->assertFalse(User::factory()->owner()->create()->canBook());
        $this->assertFalse(User::factory()->admin()->create()->canBook());
    }

    #[Test]
    public function a_suspended_customer_cannot_book(): void
    {
        $this->assertFalse(User::factory()->suspended()->create()->canBook());
    }

    #[Test]
    public function a_repeat_no_show_customer_is_flagged_at_the_threshold(): void
    {
        // SRS 9.12: without payments, visibility is the only deterrent.
        $this->assertFalse(User::factory()->create(['no_show_count' => 1])->isRepeatNoShow());
        $this->assertTrue(User::factory()->create(['no_show_count' => 2])->isRepeatNoShow());
    }
}
