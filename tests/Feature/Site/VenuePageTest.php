<?php

namespace Tests\Feature\Site;

use App\Enums\BusinessStatus;
use App\Enums\SpotStatus;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Game;
use App\Models\Spot;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-4.2 -- the venue detail page.
 *
 * This file exists because the page shipped without any coverage at all and
 * broke in a browser: the controller looped over every spot calling
 * freeWindows(), and a spot with no hours override falls back to
 * $this->business->hours(). Nothing had handed those spots their venue, so each
 * one lazy-loaded it.
 *
 * The reason no test caught it is worth recording. Eloquent only arms the
 * lazy-loading guard on models hydrated from a result set of more than one row
 * (Builder::hydrate -- `if (count($items) > 1)`), so the fault was invisible
 * until a venue happened to have two spots. Every test venue had one.
 *
 * So the multi-spot case below is the regression test, and the query-count test
 * is what stops the eager load being quietly dropped again later.
 */
class VenuePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));
    }

    /** A venue with $spots bookable spots spread over one category. */
    private function venue(int $spots = 1, array $attributes = [], array $spotAttributes = []): Business
    {
        $business = Business::factory()->active()->create(array_merge([
            'operating_hours' => OperatingHours::everyDay('10:00', '23:00'),
        ], $attributes));

        $businessGame = BusinessGame::factory()->create([
            'business_id' => $business->id,
            'game_id' => Game::factory()->create()->id,
        ]);

        for ($i = 1; $i <= $spots; $i++) {
            Spot::factory()->create(array_merge([
                'business_game_id' => $businessGame->id,
                'business_id' => $business->id,
                'name' => "Table {$i}",
                // No operating_hours_override: these spots inherit from the
                // venue, which is precisely the path that used to lazy-load.
                'operating_hours_override' => null,
            ], $spotAttributes));
        }

        return $business->fresh();
    }

    #[Test]
    public function a_guest_can_open_a_venue_and_see_its_spots(): void
    {
        $business = $this->venue(spotAttributes: ['price_amount' => 1500, 'price_unit_minutes' => 60]);

        $this->get(route('businesses.show', $business))
            ->assertOk()
            ->assertSee('Table 1')
            ->assertSee('Rs. 1,500');
    }

    #[Test]
    public function a_venue_with_several_spots_renders(): void
    {
        // The regression. With one spot this passed even with the eager load
        // missing; with four it is a 500.
        $business = $this->venue(spots: 4);

        $this->get(route('businesses.show', $business))
            ->assertOk()
            ->assertSee('Table 1')
            ->assertSee('Table 4');
    }

    #[Test]
    public function rendering_more_spots_does_not_cost_more_queries(): void
    {
        // A guard against the eager load being dropped again. The point is not
        // the absolute number -- it is that it does not grow with the spot
        // count, which is the signature of the bug this file was written for.
        $small = $this->venue(spots: 1);
        $large = $this->venue(spots: 8);

        $this->assertSame(
            $this->queriesToRender($small),
            $this->queriesToRender($large),
            'Query count grew with the number of spots: something is lazy-loading per spot.',
        );
    }

    private function queriesToRender(Business $business): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('businesses.show', $business))->assertOk();

        $count = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /*
    |--------------------------------------------------------------------------
    | Visibility (SRS 9.17)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_venue_awaiting_review_is_not_reachable_by_link(): void
    {
        // Hiding it from search is not enough: the slug is guessable and gets
        // shared, so the page itself has to refuse.
        $business = $this->venue();
        $business->forceFill(['status' => BusinessStatus::PendingReview])->save();

        $this->get(route('businesses.show', $business))->assertNotFound();
    }

    #[Test]
    public function a_suspended_venue_is_not_reachable_by_link(): void
    {
        $business = $this->venue();
        $business->forceFill(['status' => BusinessStatus::Suspended])->save();

        $this->get(route('businesses.show', $business))->assertNotFound();
    }

    #[Test]
    public function a_venue_with_nothing_bookable_is_not_reachable(): void
    {
        // SRS 9.17: an approved venue whose every spot is deactivated is a dead
        // end, so it must not render as if a booking were possible.
        $business = $this->venue(spots: 2, spotAttributes: ['status' => SpotStatus::Inactive]);

        $this->get(route('businesses.show', $business))->assertNotFound();
    }

    #[Test]
    public function a_deactivated_spot_is_hidden_but_its_siblings_still_show(): void
    {
        $business = $this->venue(spots: 2);
        $business->spots()->where('name', 'Table 2')
            ->update(['status' => SpotStatus::Inactive]);

        $this->get(route('businesses.show', $business))
            ->assertOk()
            ->assertSee('Table 1')
            ->assertDontSee('Table 2');
    }

    /*
    |--------------------------------------------------------------------------
    | Availability
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_spot_with_its_own_hours_is_shown_as_differing_from_the_venue(): void
    {
        $business = $this->venue(spotAttributes: [
            'operating_hours_override' => OperatingHours::everyDay('18:00', '23:00'),
        ]);

        $this->get(route('businesses.show', $business))
            ->assertOk()
            ->assertSee('different hours');
    }

    #[Test]
    public function a_date_beyond_the_booking_window_falls_back_to_today(): void
    {
        // A hand-edited or stale date must not render a day nobody can book.
        $business = $this->venue();
        $tooFar = Carbon::today()->addDays((int) config('booking.max_advance_days') + 30);

        $this->get(route('businesses.show', ['business' => $business, 'date' => $tooFar->toDateString()]))
            ->assertOk()
            ->assertDontSee($tooFar->format('D j M'));
    }

    #[Test]
    public function an_unparseable_date_does_not_break_the_page(): void
    {
        $business = $this->venue();

        $this->get(route('businesses.show', ['business' => $business, 'date' => 'not-a-date']))
            ->assertOk();
    }
}
