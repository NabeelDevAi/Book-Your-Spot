<?php

namespace Tests\Feature\Owner;

use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BusinessManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cue & Console Gaming Zone',
            'description' => 'Snooker and PS5.',
            'address' => '12-C Khayaban-e-Bukhari',
            'area' => 'DHA Phase 6',
            'city' => 'Karachi',
            'contact_number' => '+92 21 3584 0001',
            'operating_hours' => $this->hours(),
        ], $overrides);
    }

    private function hours(string $open = '14:00', string $close = '23:00'): array
    {
        $days = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $days[$day] = ['ranges' => [['open' => $open, 'close' => $close]]];
        }

        return $days;
    }

    #[Test]
    public function an_owner_can_create_a_venue_and_it_enters_the_review_queue(): void
    {
        // FR-2.2: never live on submission. Status is set by the controller and
        // is not mass-assignable, so it cannot be forced from input.
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(route('owner.businesses.store'), $this->payload())
            ->assertRedirect();

        $business = Business::sole();
        $this->assertSame(BusinessStatus::PendingReview, $business->status);
        $this->assertSame($owner->id, $business->owner_id);
        $this->assertSame('cue-console-gaming-zone', $business->slug);
    }

    #[Test]
    public function a_venue_cannot_be_created_already_approved(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(
            route('owner.businesses.store'),
            $this->payload(['status' => 'active'])
        );

        $this->assertSame(BusinessStatus::PendingReview, Business::sole()->status);
    }

    #[Test]
    public function a_customer_cannot_create_a_venue(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('owner.businesses.store'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('businesses', 0);
    }

    #[Test]
    public function an_owner_cannot_edit_another_owners_venue(): void
    {
        // The role check alone is not enough -- both users are owners.
        $intruder = User::factory()->owner()->create();
        $business = Business::factory()->create();

        $this->actingAs($intruder)->get(route('owner.businesses.edit', $business))->assertForbidden();

        $this->actingAs($intruder)
            ->put(route('owner.businesses.update', $business), $this->payload(['name' => 'Hijacked']))
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $business->fresh()->name);
    }

    #[Test]
    public function opening_hours_are_stored_and_overnight_ranges_survive(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(
            route('owner.businesses.store'),
            $this->payload(['operating_hours' => $this->hours('16:00', '02:00')])
        );

        // 16:00–02:00 is a ten-hour evening, not an invalid range. Rejecting it
        // would make the platform unusable for most gaming venues.
        $this->assertSame(
            [['open' => '16:00', 'close' => '02:00']],
            Business::sole()->hours()->forDay('mon')
        );
    }

    #[Test]
    public function a_day_marked_closed_is_stored_with_no_ranges(): void
    {
        $owner = User::factory()->owner()->create();

        $hours = $this->hours();
        $hours['sun'] = ['closed' => '1', 'ranges' => [['open' => '14:00', 'close' => '23:00']]];

        $this->actingAs($owner)->post(route('owner.businesses.store'), $this->payload(['operating_hours' => $hours]));

        // "Closed" wins over any leftover range values -- the editor keeps the
        // inputs in the DOM so times survive a mistaken toggle.
        $business = Business::sole();
        $this->assertTrue($business->hours()->isClosedOn('sun'));
        $this->assertFalse($business->hours()->isClosedOn('mon'));
    }

    #[Test]
    public function a_venue_closed_every_day_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();

        $hours = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $hours[$day] = ['closed' => '1'];
        }

        $this->actingAs($owner)
            ->post(route('owner.businesses.store'), $this->payload(['operating_hours' => $hours]))
            ->assertSessionHasErrors('operating_hours');

        $this->assertDatabaseCount('businesses', 0);
    }

    #[Test]
    public function overlapping_ranges_on_one_day_are_rejected(): void
    {
        $owner = User::factory()->owner()->create();

        $hours = $this->hours();
        $hours['mon'] = ['ranges' => [
            ['open' => '10:00', 'close' => '14:00'],
            ['open' => '13:00', 'close' => '18:00'],
        ]];

        $this->actingAs($owner)
            ->post(route('owner.businesses.store'), $this->payload(['operating_hours' => $hours]))
            ->assertSessionHasErrors('operating_hours');
    }

    #[Test]
    public function non_overlapping_split_shifts_are_accepted(): void
    {
        $owner = User::factory()->owner()->create();

        $hours = $this->hours();
        $hours['sat'] = ['ranges' => [
            ['open' => '10:00', 'close' => '13:00'],
            ['open' => '16:00', 'close' => '02:00'],
        ]];

        $this->actingAs($owner)
            ->post(route('owner.businesses.store'), $this->payload(['operating_hours' => $hours]))
            ->assertSessionHasNoErrors();

        $this->assertCount(2, Business::sole()->hours()->forDay('sat'));
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.11 -- duplicate detection
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_matching_contact_number_flags_the_venue_for_review(): void
    {
        Business::factory()->create([
            'name' => 'PlayZone',
            'contact_number' => '+922135820004',
        ]);

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(route('owner.businesses.store'), $this->payload([
            'name' => 'PlayZone Centre',
            'contact_number' => '+92 21 3582 0004',
        ]));

        $created = Business::where('name', 'PlayZone Centre')->sole();

        // Formatting differences must not defeat the check.
        $this->assertTrue($created->duplicate_flagged);
        $this->assertStringContainsString('PlayZone', $created->duplicate_note);
    }

    #[Test]
    public function a_matching_address_in_the_same_area_flags_the_venue(): void
    {
        Business::factory()->create([
            'name' => 'First Listing',
            'address' => 'Ground Floor, Dolmen Mall',
            'area' => 'Clifton',
            'contact_number' => '+922100000001',
        ]);

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(route('owner.businesses.store'), $this->payload([
            'name' => 'Second Listing',
            'address' => 'ground floor dolmen mall',
            'area' => 'Clifton',
            'contact_number' => '+922100000002',
        ]));

        $this->assertTrue(Business::where('name', 'Second Listing')->sole()->duplicate_flagged);
    }

    #[Test]
    public function the_same_address_in_a_different_area_is_not_flagged(): void
    {
        Business::factory()->create([
            'address' => 'Main Boulevard',
            'area' => 'Clifton',
            'contact_number' => '+922100000001',
        ]);

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(route('owner.businesses.store'), $this->payload([
            'name' => 'Elsewhere',
            'address' => 'Main Boulevard',
            'area' => 'Gulshan-e-Iqbal',
            'contact_number' => '+922100000002',
        ]));

        $this->assertFalse(Business::where('name', 'Elsewhere')->sole()->duplicate_flagged);
    }

    #[Test]
    public function a_duplicate_is_flagged_not_blocked(): void
    {
        // One owner may genuinely run two venues from adjacent units. Blocking
        // would turn a moderation signal into a support ticket.
        Business::factory()->create(['contact_number' => '+922135820004']);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('owner.businesses.store'), $this->payload(['contact_number' => '+922135820004']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('businesses', 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Resubmission & deletion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function editing_a_rejected_venue_returns_it_to_the_review_queue(): void
    {
        // Otherwise the owner fixes the problem and the corrected listing sits
        // invisible to Admin forever.
        $owner = User::factory()->owner()->create();
        $business = Business::factory()->rejected('Address unverified')->create(['owner_id' => $owner->id]);

        $this->actingAs($owner)->put(
            route('owner.businesses.update', $business),
            $this->payload(['address' => 'A verifiable address'])
        );

        $business->refresh();
        $this->assertSame(BusinessStatus::PendingReview, $business->status);
        $this->assertNull($business->rejection_reason);
    }

    #[Test]
    public function editing_an_active_venue_does_not_send_it_back_for_review(): void
    {
        $owner = User::factory()->owner()->create();
        $business = Business::factory()->active()->create(['owner_id' => $owner->id]);

        $this->actingAs($owner)->put(
            route('owner.businesses.update', $business),
            $this->payload(['description' => 'Updated blurb'])
        );

        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);
    }

    #[Test]
    public function a_venue_with_booking_history_cannot_be_deleted(): void
    {
        $owner = User::factory()->owner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);
        Reservation::factory()->create(['business_id' => $business->id]);

        $this->actingAs($owner)->delete(route('owner.businesses.destroy', $business))->assertRedirect();

        $this->assertNotSoftDeleted($business);
    }

    #[Test]
    public function a_venue_with_no_history_can_be_deleted(): void
    {
        $owner = User::factory()->owner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($owner)->delete(route('owner.businesses.destroy', $business))->assertRedirect();

        $this->assertSoftDeleted($business);
    }
}
