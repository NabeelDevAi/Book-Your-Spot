<?php

namespace Tests\Feature\Site;

use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Game;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-4.1 -- browse and filter, open to guests (SRS 9.13).
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));
    }

    private function venue(array $attributes = [], ?string $gameSlug = null, array $spotAttributes = []): Business
    {
        $business = Business::factory()->active()->create(array_merge([
            'operating_hours' => OperatingHours::everyDay('10:00', '23:00'),
        ], $attributes));

        $game = $gameSlug
            ? Game::firstOrCreate(['slug' => $gameSlug], ['name' => ucfirst($gameSlug)])
            : Game::factory()->create();

        $businessGame = BusinessGame::factory()->create([
            'business_id' => $business->id,
            'game_id' => $game->id,
        ]);

        Spot::factory()->create(array_merge([
            'business_game_id' => $businessGame->id,
            'business_id' => $business->id,
        ], $spotAttributes));

        return $business->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Guest access
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_guest_can_browse_venues(): void
    {
        $business = $this->venue(['name' => 'Cue & Console']);

        $this->get('/')->assertOk()->assertSee('Cue &amp; Console', false);
    }

    #[Test]
    public function prices_are_visible_to_guests(): void
    {
        // Pricing transparency is a stated goal (SRS 9.13); hiding rates behind
        // a login wall would make the platform no better than calling around.
        $this->venue(spotAttributes: ['price_amount' => 2500, 'price_unit_minutes' => 60]);

        $this->get('/')->assertOk()->assertSee('Rs. 2,500');
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.17 -- visibility
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_pending_venue_is_not_listed(): void
    {
        $this->venue(['name' => 'Approved Venue']);

        $pending = Business::factory()->pendingReview()->create(['name' => 'Unapproved Venue']);
        $businessGame = BusinessGame::factory()->create(['business_id' => $pending->id]);
        Spot::factory()->create(['business_game_id' => $businessGame->id, 'business_id' => $pending->id]);

        $this->get('/')->assertOk()->assertSee('Approved Venue')->assertDontSee('Unapproved Venue');
    }

    #[Test]
    public function an_active_venue_with_no_active_spots_is_not_listed(): void
    {
        // Nothing to act on, so listing it is a dead end for the customer.
        Business::factory()->active()->create(['name' => 'Empty Venue']);

        $this->get('/')->assertOk()->assertDontSee('Empty Venue');
    }

    #[Test]
    public function a_suspended_venue_disappears_from_search(): void
    {
        $business = $this->venue(['name' => 'Suspended Venue']);

        $this->get('/')->assertSee('Suspended Venue');

        $business->status = BusinessStatus::Suspended;
        $business->save();

        $this->get('/')->assertDontSee('Suspended Venue');
    }

    #[Test]
    public function an_unapproved_venue_detail_page_returns_404(): void
    {
        // A shared link must not expose a listing that never passed review.
        $pending = Business::factory()->pendingReview()->create();

        $this->get(route('businesses.show', $pending))->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function results_can_be_filtered_by_game(): void
    {
        $this->venue(['name' => 'Snooker Hall'], 'snooker');
        $this->venue(['name' => 'Futsal Ground'], 'futsal');

        $this->get('/?game=snooker')
            ->assertOk()
            ->assertSee('Snooker Hall')
            ->assertDontSee('Futsal Ground');
    }

    #[Test]
    public function results_can_be_filtered_by_area(): void
    {
        $this->venue(['name' => 'Clifton Venue', 'area' => 'Clifton']);
        $this->venue(['name' => 'DHA Venue', 'area' => 'DHA Phase 6']);

        $this->get('/?area='.urlencode('Clifton'))
            ->assertOk()
            ->assertSee('Clifton Venue')
            ->assertDontSee('DHA Venue');
    }

    #[Test]
    public function results_can_be_filtered_by_price(): void
    {
        $this->venue(['name' => 'Cheap Venue'], spotAttributes: ['price_amount' => 100]);
        $this->venue(['name' => 'Premium Venue'], spotAttributes: ['price_amount' => 5000]);

        $this->get('/?max_price=1000')
            ->assertOk()
            ->assertSee('Cheap Venue')
            ->assertDontSee('Premium Venue');
    }

    #[Test]
    public function a_venue_is_matched_on_its_cheapest_spot_not_its_dearest(): void
    {
        // A venue with one affordable table and one premium one must survive a
        // low max_price filter -- the customer can still book the cheap one.
        $business = $this->venue(['name' => 'Mixed Venue'], spotAttributes: ['price_amount' => 100]);

        Spot::factory()->create([
            'business_game_id' => $business->businessGames()->first()->id,
            'business_id' => $business->id,
            'name' => 'Premium Table',
            'price_amount' => 9000,
        ]);

        $this->get('/?max_price=500')->assertOk()->assertSee('Mixed Venue');
    }

    #[Test]
    public function a_text_search_matches_name_and_area(): void
    {
        $this->venue(['name' => 'Cue Masters', 'area' => 'Clifton']);
        $this->venue(['name' => 'Turf Arena', 'area' => 'Gulshan-e-Iqbal']);

        $this->get('/?q=Cue')->assertSee('Cue Masters')->assertDontSee('Turf Arena');
        $this->get('/?q=Gulshan')->assertSee('Turf Arena')->assertDontSee('Cue Masters');
    }

    /*
    |--------------------------------------------------------------------------
    | Availability filter
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_fully_booked_venue_is_excluded_when_filtering_by_date(): void
    {
        $open = $this->venue(['name' => 'Has Space']);
        $booked = $this->venue(['name' => 'Fully Booked']);

        // Block out the whole of the booked venue's opening hours.
        Reservation::factory()
            ->forSpot($booked->spots()->first())
            ->at(Carbon::parse('2026-08-02 10:00'), 780)
            ->confirmed()
            ->create();

        $this->get('/?date=2026-08-02')
            ->assertOk()
            ->assertSee('Has Space')
            ->assertDontSee('Fully Booked');
    }

    #[Test]
    public function a_venue_closed_on_the_requested_day_is_excluded(): void
    {
        $this->venue(['name' => 'Open Daily']);

        $closed = $this->venue([
            'name' => 'Sunday Closed',
            'operating_hours' => OperatingHours::fromArray([
                'mon' => [['open' => '10:00', 'close' => '23:00']],
                'sun' => [],
            ]),
        ]);

        // 2 Aug 2026 is a Sunday.
        $this->get('/?date=2026-08-02')
            ->assertOk()
            ->assertSee('Open Daily')
            ->assertDontSee('Sunday Closed');
    }

    #[Test]
    public function a_past_date_is_rejected(): void
    {
        $this->get('/?date=2026-07-01')->assertSessionHasErrors('date');
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.16 -- empty states
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_empty_result_set_explains_itself(): void
    {
        $this->venue(['area' => 'Clifton']);

        $this->get('/?area=Nowhere')
            ->assertOk()
            ->assertSee('No venues match those filters')
            ->assertSee('Show all venues');
    }

    #[Test]
    public function an_empty_availability_result_suggests_widening_the_time(): void
    {
        $booked = $this->venue();
        Reservation::factory()
            ->forSpot($booked->spots()->first())
            ->at(Carbon::parse('2026-08-02 10:00'), 780)
            ->confirmed()
            ->create();

        $this->get('/?date=2026-08-02')
            ->assertOk()
            ->assertSee('Nothing is free at that time');
    }

    #[Test]
    public function a_platform_with_no_venues_at_all_still_renders(): void
    {
        $this->get('/')->assertOk()->assertSee('No venues match those filters');
    }

    /*
    |--------------------------------------------------------------------------
    | Detail page
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_detail_page_shows_spots_hours_and_prices(): void
    {
        $business = $this->venue(
            ['name' => 'Cue Masters'],
            'snooker',
            ['name' => 'Table 1', 'price_amount' => 100, 'price_unit_minutes' => 10],
        );

        $this->get(route('businesses.show', $business))
            ->assertOk()
            ->assertSee('Cue Masters')
            ->assertSee('Table 1')
            ->assertSee('Rs. 100 / 10 min')
            ->assertSee('Opening hours');
    }

    #[Test]
    public function an_inactive_spot_is_hidden_from_the_detail_page(): void
    {
        $business = $this->venue(spotAttributes: ['name' => 'Live Table']);

        Spot::factory()->inactive()->create([
            'business_game_id' => $business->businessGames()->first()->id,
            'business_id' => $business->id,
            'name' => 'Retired Table',
        ]);

        $this->get(route('businesses.show', $business))
            ->assertSee('Live Table')
            ->assertDontSee('Retired Table');
    }

    #[Test]
    public function a_fully_booked_spot_says_so_rather_than_offering_a_dead_button(): void
    {
        $business = $this->venue();

        Reservation::factory()
            ->forSpot($business->spots()->first())
            ->at(Carbon::parse('2026-08-02 10:00'), 780)
            ->confirmed()
            ->create();

        $this->get(route('businesses.show', ['business' => $business, 'date' => '2026-08-02']))
            ->assertOk()
            ->assertSee('Fully booked')
            ->assertSee('Unavailable');
    }
}
