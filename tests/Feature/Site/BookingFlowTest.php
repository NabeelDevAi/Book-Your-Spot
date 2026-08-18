<?php

namespace Tests\Feature\Site;

use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The customer booking loop: form -> request -> confirmation page,
 * plus the guest redirect from SRS 9.13.
 */
class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    private Spot $spot;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));

        $this->customer = User::factory()->create();

        $business = Business::factory()->active()->create([
            'operating_hours' => OperatingHours::everyDay('10:00', '23:00'),
        ]);

        $this->spot = Spot::factory()->create([
            'business_game_id' => BusinessGame::factory()->create(['business_id' => $business->id])->id,
            'business_id' => $business->id,
            'price_amount' => 100,
            'price_unit_minutes' => 10,
            'min_duration_minutes' => 30,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'start_datetime' => '2026-08-02 19:00',
            'duration_minutes' => 60,
        ], $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | SRS 9.13 -- guests browse fully, only booking needs an account
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_guest_can_open_the_booking_form_and_see_real_prices(): void
    {
        $this->get(route('bookings.create', $this->spot))
            ->assertOk()
            ->assertSee('Rs. 600')
            ->assertSee('Sign in to request');
    }

    #[Test]
    public function the_guest_form_never_shows_a_dead_submit_button(): void
    {
        $this->get(route('bookings.create', $this->spot))
            ->assertOk()
            ->assertDontSee('Send request');
    }

    #[Test]
    public function signing_in_returns_the_guest_to_the_page_they_came_from(): void
    {
        // Being dumped on a generic dashboard mid-booking is how people abandon
        // a booking, so the intended URL is preserved through the login.
        $target = route('bookings.create', $this->spot);

        $this->get(route('login', ['redirect' => $target]))->assertOk();

        $this->post('/login', ['email' => $this->customer->email, 'password' => 'password'])
            ->assertRedirect($target);
    }

    #[Test]
    public function an_absolute_offsite_redirect_is_ignored(): void
    {
        // Otherwise the login page becomes an open redirect.
        $this->get(route('login', ['redirect' => 'https://evil.example.com/phish']))->assertOk();

        $this->post('/login', ['email' => $this->customer->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
    }

    #[Test]
    public function a_protocol_relative_redirect_is_ignored(): void
    {
        $this->get(route('login', ['redirect' => '//evil.example.com/phish']))->assertOk();

        $this->post('/login', ['email' => $this->customer->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
    }

    #[Test]
    public function a_guest_cannot_submit_a_booking(): void
    {
        $this->post(route('bookings.store', $this->spot), $this->payload())
            ->assertRedirect('/login');

        $this->assertDatabaseCount('reservations', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Submitting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_customer_can_submit_a_request_and_lands_on_the_confirmation(): void
    {
        $response = $this->actingAs($this->customer)
            ->post(route('bookings.store', $this->spot), $this->payload());

        $reservation = Reservation::sole();

        $response->assertRedirect(route('bookings.show', $reservation));
        $this->assertTrue($reservation->isPending());
        $this->assertSame($this->customer->id, $reservation->user_id);
    }

    #[Test]
    public function the_confirmation_page_shows_the_reference_to_quote_at_the_venue(): void
    {
        $this->actingAs($this->customer)->post(route('bookings.store', $this->spot), $this->payload());

        $reservation = Reservation::sole();

        $this->actingAs($this->customer)
            ->get(route('bookings.show', $reservation))
            ->assertOk()
            ->assertSee($reservation->reference)
            ->assertSee('Quote this at the venue')
            ->assertSee('Waiting for the venue to confirm');
    }

    #[Test]
    public function a_customer_cannot_view_someone_elses_booking(): void
    {
        $this->actingAs($this->customer)->post(route('bookings.store', $this->spot), $this->payload());

        $this->actingAs(User::factory()->create())
            ->get(route('bookings.show', Reservation::sole()))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Engine rules surfaced in the UI
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_invalid_duration_comes_back_as_a_field_error_with_a_suggestion(): void
    {
        $response = $this->actingAs($this->customer)
            ->post(route('bookings.store', $this->spot), $this->payload(['duration_minutes' => 37]));

        $response->assertSessionHasErrors('duration_minutes');
        $this->assertStringContainsString('Try 40 min', session('errors')->first('duration_minutes'));
        $this->assertDatabaseCount('reservations', 0);
    }

    #[Test]
    public function a_slot_outside_opening_hours_comes_back_as_an_error(): void
    {
        $this->actingAs($this->customer)
            ->post(route('bookings.store', $this->spot), $this->payload(['start_datetime' => '2026-08-02 08:00']))
            ->assertSessionHasErrors('start_datetime');

        $this->assertDatabaseCount('reservations', 0);
    }

    #[Test]
    public function a_taken_slot_comes_back_as_an_error(): void
    {
        Reservation::factory()->forSpot($this->spot)
            ->at(Carbon::parse('2026-08-02 19:00'), 60)
            ->confirmed()
            ->create();

        $response = $this->actingAs($this->customer)
            ->post(route('bookings.store', $this->spot), $this->payload());

        $response->assertSessionHasErrors('start_datetime');
        $this->assertStringContainsString('just been booked', session('errors')->first('start_datetime'));
    }

    #[Test]
    public function an_owner_cannot_reach_the_booking_endpoint_at_all(): void
    {
        // Enforced twice on purpose: role middleware at the route, and the
        // engine's own check (SRS 9.14).
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('bookings.store', $this->spot), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('reservations', 0);
    }

    #[Test]
    public function an_owner_viewing_the_form_is_told_why_they_cannot_book(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('bookings.create', $this->spot))
            ->assertOk()
            ->assertSee('Owner accounts can\'t book', false)
            ->assertSee('Blocked times');
    }

    #[Test]
    public function an_inactive_spot_cannot_be_booked_through_the_ui(): void
    {
        $this->spot->update(['status' => \App\Enums\SpotStatus::Inactive]);

        $this->get(route('bookings.create', $this->spot->fresh()))->assertNotFound();

        $this->actingAs($this->customer)
            ->post(route('bookings.store', $this->spot->fresh()), $this->payload())
            ->assertSessionHasErrors();
    }

    /*
    |--------------------------------------------------------------------------
    | The live availability endpoint
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_slots_endpoint_returns_start_times_and_a_price(): void
    {
        $response = $this->getJson(route('bookings.slots', $this->spot).'?date=2026-08-02&duration=60');

        $response->assertOk()
            ->assertJsonStructure(['duration', 'total', 'total_label', 'explanation', 'slots' => [['value', 'label', 'ends']]]);

        $this->assertEqualsWithDelta(600.0, $response->json("total"), 0.001);
    }

    #[Test]
    public function the_slots_endpoint_snaps_an_invalid_duration_to_a_real_one(): void
    {
        // The form should never be able to ask for something the spot can't sell.
        $response = $this->getJson(route('bookings.slots', $this->spot).'?date=2026-08-02&duration=7');

        $response->assertOk();
        $this->assertContains($response->json('duration'), $this->spot->allowedDurations());
    }

    #[Test]
    public function the_slots_endpoint_omits_times_already_booked(): void
    {
        Reservation::factory()->forSpot($this->spot)
            ->at(Carbon::parse('2026-08-02 19:00'), 60)
            ->confirmed()
            ->create();

        $values = collect($this->getJson(route('bookings.slots', $this->spot).'?date=2026-08-02&duration=60')
            ->json('slots'))->pluck('value');

        // Anything from 18:10 onwards would overlap the existing booking.
        $this->assertNotContains('2026-08-02 19:00', $values);
        $this->assertNotContains('2026-08-02 18:30', $values);
        $this->assertContains('2026-08-02 20:00', $values);
    }

    #[Test]
    public function the_slots_endpoint_never_offers_a_time_in_the_past(): void
    {
        $values = collect($this->getJson(route('bookings.slots', $this->spot).'?date=2026-08-01&duration=60')
            ->json('slots'))->pluck('value');

        foreach ($values as $value) {
            $this->assertTrue(
                Carbon::parse($value)->greaterThan(Carbon::now()),
                "Offered a past start time: {$value}"
            );
        }
    }
}
