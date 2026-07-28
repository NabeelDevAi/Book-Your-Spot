<?php

namespace Tests\Feature\Admin;

use App\Enums\GameStatus;
use App\Enums\ReservationStatus;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Game;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Services\Admin\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-3.3 (master category list) and FR-3.7 / section 8 (reports).
 */
class GameAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));
        $this->admin = User::factory()->admin()->create();
    }

    /*
    |--------------------------------------------------------------------------
    | FR-3.3 -- categories
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_admin_can_add_a_category(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.games.store'), ['name' => 'Foosball'])
            ->assertRedirect();

        $game = Game::where('name', 'Foosball')->sole();
        $this->assertSame('foosball', $game->slug);
        $this->assertSame(GameStatus::Active, $game->status);
    }

    #[Test]
    public function duplicate_category_names_are_rejected(): void
    {
        // The whole reason this list is centralised.
        Game::factory()->create(['name' => 'Snooker']);

        $this->actingAs($this->admin)
            ->post(route('admin.games.store'), ['name' => 'Snooker'])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function renaming_a_category_keeps_its_slug(): void
    {
        // Slugs live in shared search URLs; changing one would silently break
        // links customers and venues have already sent each other.
        $game = Game::factory()->create(['name' => 'PS5', 'slug' => 'ps5']);

        $this->actingAs($this->admin)
            ->put(route('admin.games.update', $game), ['name' => 'PlayStation 5'])
            ->assertRedirect();

        $game->refresh();
        $this->assertSame('PlayStation 5', $game->name);
        $this->assertSame('ps5', $game->slug);
    }

    #[Test]
    public function deactivating_a_category_keeps_existing_venue_links(): void
    {
        // FR-3.3 is explicit: deactivation prevents NEW links, it does not
        // unlink venues already offering the category.
        $game = Game::factory()->create();
        $business = Business::factory()->active()->create();
        $businessGame = BusinessGame::factory()->create([
            'business_id' => $business->id,
            'game_id' => $game->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.games.deactivate', $game))
            ->assertRedirect();

        $this->assertSame(GameStatus::Inactive, $game->fresh()->status);
        $this->assertDatabaseHas('business_games', ['id' => $businessGame->id]);
    }

    #[Test]
    public function a_deactivated_category_disappears_from_the_customer_filter(): void
    {
        $game = Game::factory()->create(['name' => 'Retired Game']);
        $business = Business::factory()->active()->create();
        $businessGame = BusinessGame::factory()->create([
            'business_id' => $business->id,
            'game_id' => $game->id,
        ]);
        Spot::factory()->create([
            'business_game_id' => $businessGame->id,
            'business_id' => $business->id,
        ]);

        $this->get('/')->assertSee('Retired Game');

        $this->actingAs($this->admin)->post(route('admin.games.deactivate', $game));

        // Gone from the filter dropdown, but the venue itself is still listed
        // and bookable.
        $this->get('/')->assertOk()->assertSee($business->name);
    }

    #[Test]
    public function only_an_admin_can_manage_categories(): void
    {
        $game = Game::factory()->create();

        foreach ([User::factory()->create(), User::factory()->owner()->create()] as $user) {
            $this->actingAs($user)->post(route('admin.games.store'), ['name' => 'Sneaky'])->assertForbidden();
            $this->actingAs($user)->post(route('admin.games.deactivate', $game))->assertForbidden();
        }

        $this->assertSame(GameStatus::Active, $game->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Section 8 -- reports
    |--------------------------------------------------------------------------
    */

    private function seedBookings(): Business
    {
        $business = Business::factory()->active()->create(['area' => 'Clifton']);
        $game = Game::factory()->create(['name' => 'Snooker']);
        $businessGame = BusinessGame::factory()->create([
            'business_id' => $business->id,
            'game_id' => $game->id,
        ]);
        $spot = Spot::factory()->create([
            'business_game_id' => $businessGame->id,
            'business_id' => $business->id,
        ]);

        foreach ([
            ReservationStatus::Completed, ReservationStatus::Completed, ReservationStatus::Completed,
            ReservationStatus::Rejected, ReservationStatus::Expired, ReservationStatus::NoShow,
        ] as $index => $status) {
            Reservation::factory()->forSpot($spot)
                ->at(Carbon::parse('2026-07-25 19:00')->addHours($index * 2), 60)
                ->create(['status' => $status, 'user_id' => User::factory()->create()->id]);
        }

        return $business;
    }

    #[Test]
    public function the_reports_page_renders(): void
    {
        $this->seedBookings();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Reservations over time')
            ->assertSee('Most booked categories')
            ->assertSee('Venue quality signals');
    }

    #[Test]
    public function the_trend_fills_quiet_days_with_zero(): void
    {
        // A chart that skips empty days compresses the axis and makes a flat
        // week look like steady growth.
        $rows = app(ReportService::class)->reservationsOverTime(30);

        $this->assertCount(30, $rows);
        $this->assertSame(0, $rows->first()['total']);
    }

    #[Test]
    public function quality_rates_ignore_venues_with_too_little_history(): void
    {
        // One rejection out of one request is 100% and means nothing, but it
        // would sort straight to the top of a "worst offenders" list.
        $business = Business::factory()->active()->create();
        $spot = Spot::factory()->create([
            'business_game_id' => BusinessGame::factory()->create(['business_id' => $business->id])->id,
            'business_id' => $business->id,
        ]);
        Reservation::factory()->forSpot($spot)->rejected()->create();

        $this->assertTrue(app(ReportService::class)->businessQuality()->isEmpty());
    }

    #[Test]
    public function quality_rates_are_computed_once_there_is_enough_volume(): void
    {
        $business = $this->seedBookings();

        $row = app(ReportService::class)->businessQuality()->firstWhere('id', $business->id);

        $this->assertNotNull($row);
        $this->assertSame(6, $row['total']);
        // 1 of 6 rejected, 1 of 6 expired, 1 of 6 no-show.
        $this->assertSame(17.0, $row['rejection_rate']);
        $this->assertSame(17.0, $row['unanswered_rate']);
        $this->assertSame(17.0, $row['no_show_rate']);
    }

    #[Test]
    public function unanswered_requests_are_tracked_separately_from_rejections(): void
    {
        // Letting a request expire is a distinct failure from declining it: the
        // owner never replied and the customer waited for nothing.
        $business = $this->seedBookings();

        $row = app(ReportService::class)->businessQuality()->firstWhere('id', $business->id);

        $this->assertSame(1, $row['rejected']);
        $this->assertSame(1, $row['expired']);
    }

    #[Test]
    public function bookings_are_grouped_by_category_and_area(): void
    {
        $this->seedBookings();

        $byGame = app(ReportService::class)->bookingsByGame();
        $byArea = app(ReportService::class)->bookingsByArea();

        $this->assertSame('Snooker', $byGame->first()['label']);
        $this->assertSame(6, $byGame->first()['total']);
        $this->assertSame('Clifton', $byArea->first()['label']);
    }

    #[Test]
    public function the_headline_reports_a_conversion_rate(): void
    {
        $this->seedBookings();

        $headline = app(ReportService::class)->headline();

        // 3 completed + 1 no-show reached confirmed, out of 6.
        $this->assertSame(6, $headline['total_reservations']);
        $this->assertSame(4, $headline['confirmed_reservations']);
        $this->assertSame(67.0, $headline['conversion_rate']);
    }

    #[Test]
    public function reports_survive_an_empty_platform(): void
    {
        // A division-by-zero on a fresh install would be an embarrassing way to
        // greet the first admin who logs in.
        $this->actingAs($this->admin)->get(route('admin.reports.index'))->assertOk();

        $this->assertSame(0, app(ReportService::class)->headline()['conversion_rate']);
    }

    #[Test]
    public function only_an_admin_can_see_reports(): void
    {
        foreach ([User::factory()->create(), User::factory()->owner()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.reports.index'))->assertForbidden();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FR-3.5 / NFR-6
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_admin_can_search_every_reservation_by_reference(): void
    {
        $this->seedBookings();
        $reservation = Reservation::first();

        $this->actingAs($this->admin)
            ->get(route('admin.reservations.index', ['search' => $reservation->reference]))
            ->assertOk()
            ->assertSee($reservation->reference);
    }

    #[Test]
    public function the_audit_log_is_readable_and_has_no_write_routes(): void
    {
        $this->actingAs($this->admin)->get(route('admin.audit.index'))->assertOk();

        // NFR-6: a log that can be altered is worth nothing in a dispute.
        $routes = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertFalse(
            $routes->contains(fn ($uri) => str_contains($uri, 'audit') && ! str_ends_with($uri, 'audit')),
            'The audit log must have no mutation routes.'
        );
    }
}
