<?php

namespace Tests\Feature\Owner;

use App\Enums\SpotStatus;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Game;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpotManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Business $business;

    private BusinessGame $businessGame;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
        $this->business = Business::factory()->active()->create(['owner_id' => $this->owner->id]);
        $this->businessGame = BusinessGame::factory()->create([
            'business_id' => $this->business->id,
            'game_id' => Game::factory()->create()->id,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_game_id' => $this->businessGame->id,
            'name' => 'Snooker Table 1',
            'price_amount' => 100,
            'price_unit_minutes' => 10,
            'min_duration_minutes' => 30,
            'max_duration_minutes' => 240,
            'override_hours' => 0,
        ], $overrides);
    }

    #[Test]
    public function an_owner_can_add_a_spot(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.store', $this->business), $this->payload())
            ->assertRedirect();

        $spot = Spot::sole();
        $this->assertSame('Snooker Table 1', $spot->name);
        $this->assertSame(SpotStatus::Active, $spot->status);
        // Denormalised business_id must match the parent, not be taken from input.
        $this->assertSame($this->business->id, $spot->business_id);
    }

    #[Test]
    public function a_spot_cannot_be_attached_to_another_venues_category(): void
    {
        // Without an ownership check on business_game_id, an owner could post
        // someone else's category id and hang a spot off their venue.
        $otherBusiness = Business::factory()->create();
        $foreignGame = BusinessGame::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($this->owner)
            ->post(
                route('owner.businesses.spots.store', $this->business),
                $this->payload(['business_game_id' => $foreignGame->id])
            )
            ->assertNotFound();

        $this->assertDatabaseCount('spots', 0);
    }

    #[Test]
    public function an_owner_cannot_add_a_spot_to_another_owners_venue(): void
    {
        $intruder = User::factory()->owner()->create();

        $this->actingAs($intruder)
            ->post(route('owner.businesses.spots.store', $this->business), $this->payload())
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.7 -- duration and billing-unit coherence
    |--------------------------------------------------------------------------
    | Enforced when the spot is DEFINED. If a spot may exist with a 25-minute
    | minimum on a 10-minute billing unit, no validation at booking time can
    | give the customer a sensible answer.
    */

    #[Test]
    public function the_minimum_duration_must_be_a_multiple_of_the_billing_unit(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.store', $this->business), $this->payload([
                'price_unit_minutes' => 10,
                'min_duration_minutes' => 25,
            ]))
            ->assertSessionHasErrors('min_duration_minutes');

        $this->assertDatabaseCount('spots', 0);
    }

    #[Test]
    public function the_error_suggests_a_valid_duration(): void
    {
        $response = $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.store', $this->business), $this->payload([
                'price_unit_minutes' => 10,
                'min_duration_minutes' => 25,
            ]));

        $response->assertSessionHasErrors('min_duration_minutes');
        $this->assertStringContainsString('30', session('errors')->first('min_duration_minutes'));
    }

    #[Test]
    public function the_maximum_duration_must_be_a_multiple_of_the_billing_unit(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.store', $this->business), $this->payload([
                'price_unit_minutes' => 60,
                'min_duration_minutes' => 60,
                'max_duration_minutes' => 150,
            ]))
            ->assertSessionHasErrors('max_duration_minutes');
    }

    #[Test]
    public function the_maximum_cannot_be_below_the_minimum(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.store', $this->business), $this->payload([
                'min_duration_minutes' => 120,
                'max_duration_minutes' => 60,
            ]))
            ->assertSessionHasErrors('max_duration_minutes');
    }

    #[Test]
    public function an_arbitrary_billing_unit_is_rejected(): void
    {
        // 7-minute billing would make every duration hint nonsense.
        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.store', $this->business), $this->payload([
                'price_unit_minutes' => 7,
            ]))
            ->assertSessionHasErrors('price_unit_minutes');
    }

    /*
    |--------------------------------------------------------------------------
    | Hours override
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_spot_without_an_override_inherits_venue_hours(): void
    {
        $this->actingAs($this->owner)->post(
            route('owner.businesses.spots.store', $this->business),
            $this->payload(['override_hours' => 0])
        );

        // Null override means "inherit", which is deliberately different from
        // an override that closes the spot all week.
        $this->assertNull(Spot::sole()->operating_hours_override);
    }

    #[Test]
    public function a_spot_can_close_earlier_than_its_venue(): void
    {
        $hours = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $hours[$day] = ['ranges' => [['open' => '14:00', 'close' => '22:00']]];
        }

        $this->actingAs($this->owner)->post(
            route('owner.businesses.spots.store', $this->business),
            $this->payload(['override_hours' => 1, 'operating_hours' => $hours])
        );

        $spot = Spot::sole();
        $this->assertTrue($spot->hasHoursOverride());
        $this->assertSame([['open' => '14:00', 'close' => '22:00']], $spot->effectiveHours()->forDay('mon'));
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.9 -- deactivation vs deletion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_spot_with_no_history_can_be_hard_deleted(): void
    {
        $spot = Spot::factory()->create([
            'business_game_id' => $this->businessGame->id,
            'business_id' => $this->business->id,
        ]);

        $this->actingAs($this->owner)
            ->delete(route('owner.businesses.spots.destroy', [$this->business, $spot]))
            ->assertRedirect();

        $this->assertDatabaseCount('spots', 0);
    }

    #[Test]
    public function a_spot_with_history_cannot_be_deleted_at_all(): void
    {
        $spot = Spot::factory()->create([
            'business_game_id' => $this->businessGame->id,
            'business_id' => $this->business->id,
        ]);
        Reservation::factory()->forSpot($spot)->completed()->create();

        $this->actingAs($this->owner)
            ->delete(route('owner.businesses.spots.destroy', [$this->business, $spot]))
            ->assertRedirect();

        // Not even soft-deleted -- the records belong to the customer too.
        $this->assertDatabaseHas('spots', ['id' => $spot->id, 'deleted_at' => null]);
    }

    #[Test]
    public function deactivating_a_spot_hides_it_without_touching_history(): void
    {
        $spot = Spot::factory()->create([
            'business_game_id' => $this->businessGame->id,
            'business_id' => $this->business->id,
        ]);
        Reservation::factory()->forSpot($spot)->completed()->create();

        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.deactivate', [$this->business, $spot]))
            ->assertRedirect();

        $this->assertSame(SpotStatus::Inactive, $spot->fresh()->status);
        $this->assertDatabaseCount('reservations', 1);
    }

    #[Test]
    public function deactivating_a_spot_flags_future_bookings_instead_of_cancelling_them(): void
    {
        // SRS 9.9 following 9.6's principle: silently cancelling a confirmed
        // customer is the trust-breaking failure the SRS explicitly names.
        $spot = Spot::factory()->create([
            'business_game_id' => $this->businessGame->id,
            'business_id' => $this->business->id,
        ]);

        $booking = Reservation::factory()->forSpot($spot)
            ->at(Carbon::tomorrow()->setTime(19, 0))
            ->confirmed()
            ->create();

        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.deactivate', [$this->business, $spot]));

        $booking->refresh();
        $this->assertTrue($booking->isConfirmed(), 'The booking must survive deactivation.');
        $this->assertTrue($booking->hasOpenConflict(), 'A conflict must be raised for the owner.');
    }

    #[Test]
    public function deactivation_does_not_flag_bookings_already_in_the_past(): void
    {
        $spot = Spot::factory()->create([
            'business_game_id' => $this->businessGame->id,
            'business_id' => $this->business->id,
        ]);
        $past = Reservation::factory()->forSpot($spot)->past()->completed()->create();

        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.deactivate', [$this->business, $spot]));

        $this->assertFalse($past->fresh()->hasOpenConflict());
    }

    #[Test]
    public function a_deactivated_spot_can_be_brought_back(): void
    {
        $spot = Spot::factory()->inactive()->create([
            'business_game_id' => $this->businessGame->id,
            'business_id' => $this->business->id,
        ]);

        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.activate', [$this->business, $spot]))
            ->assertRedirect();

        $this->assertSame(SpotStatus::Active, $spot->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.10 -- price changes are forward-only
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function changing_a_price_does_not_alter_existing_bookings(): void
    {
        $spot = Spot::factory()->futsal()->create([
            'business_game_id' => $this->businessGame->id,
            'business_id' => $this->business->id,
        ]);

        $booking = Reservation::factory()->forSpot($spot)->confirmed()->create([
            'total_price' => $spot->priceFor(60),
        ]);

        $this->actingAs($this->owner)->put(
            route('owner.businesses.spots.update', [$this->business, $spot]),
            $this->payload([
                'name' => $spot->name,
                'price_amount' => 5000,
                'price_unit_minutes' => 60,
                'min_duration_minutes' => 60,
                'max_duration_minutes' => 180,
            ])
        );

        $this->assertEqualsWithDelta(5000.0, (float) $spot->fresh()->price_amount, 0.001);
        $this->assertEqualsWithDelta(2500.0, (float) $booking->fresh()->total_price, 0.001);
    }
}
