<?php

namespace Tests\Feature\Owner;

use App\Enums\ReservationChannel;
use App\Enums\ReservationStatus;
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
 * The Owner recording a walk-in or phone booking on the customer's behalf --
 * a booking never routed through the platform's own request/approve flow.
 */
class ManualBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spot $spot;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));

        $this->owner = User::factory()->owner()->create();

        $this->business = Business::factory()->active()->create([
            'owner_id' => $this->owner->id,
            'operating_hours' => OperatingHours::everyDay('00:00', '23:59'),
        ]);

        $this->spot = Spot::factory()->create([
            'business_game_id' => BusinessGame::factory()->create(['business_id' => $this->business->id])->id,
            'business_id' => $this->business->id,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'start_datetime' => Carbon::parse('2026-08-01 12:00')->format('Y-m-d H:i'),
            'duration_minutes' => 60,
            'channel' => ReservationChannel::WalkIn->value,
            'customer_name' => 'Bilal Khan',
            'customer_phone' => '03001234567',
            'note' => null,
        ], $overrides);
    }

    #[Test]
    public function an_owner_can_record_a_walk_in_booking_confirmed_immediately(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.reservations.store', [$this->business, $this->spot]), $this->payload())
            ->assertRedirect();

        $reservation = Reservation::first();

        $this->assertNotNull($reservation);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertTrue($reservation->isManual());
        $this->assertNull($reservation->user_id);
        $this->assertSame('Bilal Khan', $reservation->customer_name);
        $this->assertSame(ReservationChannel::WalkIn, $reservation->channel);
    }

    #[Test]
    public function a_manual_booking_can_start_right_now_with_no_lead_time(): void
    {
        // The whole point of a walk-in: the customer is standing at the
        // counter, so the platform's usual minimum notice does not apply.
        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.reservations.store', [$this->business, $this->spot]), $this->payload([
                'start_datetime' => Carbon::parse('2026-08-01 10:05')->format('Y-m-d H:i'),
            ]))
            ->assertRedirect();

        $this->assertSame(1, Reservation::count());
    }

    #[Test]
    public function a_phone_number_matching_an_existing_customer_links_the_account(): void
    {
        $customer = User::factory()->create(['phone' => '03119998888']);

        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.reservations.store', [$this->business, $this->spot]), $this->payload([
                'channel' => ReservationChannel::Phone->value,
                'customer_phone' => '03119998888',
                'customer_name' => 'Whatever the owner typed',
            ]))
            ->assertRedirect();

        $reservation = Reservation::with('user')->first();

        $this->assertSame($customer->id, $reservation->user_id);
        $this->assertNull($reservation->customer_name);
        $this->assertSame($customer->name, $reservation->customerDisplayName());
    }

    #[Test]
    public function a_manual_booking_cannot_double_book_an_already_confirmed_slot(): void
    {
        Reservation::factory()->forSpot($this->spot)
            ->at(Carbon::parse('2026-08-01 12:00'), 60)
            ->confirmed()
            ->create();

        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.reservations.store', [$this->business, $this->spot]), $this->payload())
            ->assertSessionHasErrors();

        $this->assertSame(1, Reservation::count());
    }

    #[Test]
    public function a_manual_booking_auto_rejects_a_customers_pending_request_for_the_same_slot(): void
    {
        $pending = Reservation::factory()->forSpot($this->spot)
            ->at(Carbon::parse('2026-08-01 12:00'), 60)
            ->create();

        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.reservations.store', [$this->business, $this->spot]), $this->payload())
            ->assertRedirect();

        $this->assertSame(ReservationStatus::Rejected, $pending->fresh()->status);
    }

    #[Test]
    public function an_owner_cannot_record_a_booking_on_another_owners_spot(): void
    {
        $theirs = Spot::factory()->create();

        $this->actingAs($this->owner)
            ->get(route('owner.businesses.spots.reservations.create', [$theirs->business, $theirs]))
            ->assertForbidden();
    }

    #[Test]
    public function the_customer_name_and_phone_are_required(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.reservations.store', [$this->business, $this->spot]), $this->payload([
                'customer_name' => '',
                'customer_phone' => '',
            ]))
            ->assertSessionHasErrors(['customer_name', 'customer_phone']);
    }

    #[Test]
    public function the_booking_shows_up_in_the_owners_queue_as_confirmed(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.reservations.store', [$this->business, $this->spot]), $this->payload());

        $reservation = Reservation::first();

        $this->actingAs($this->owner)
            ->get(route('owner.reservations.show', $reservation))
            ->assertOk()
            ->assertSee('Bilal Khan')
            ->assertSee('Walk-in');
    }

    #[Test]
    public function a_manual_booking_can_be_cancelled_by_the_owner_without_a_linked_account(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.businesses.spots.reservations.store', [$this->business, $this->spot]), $this->payload());

        $reservation = Reservation::first();

        $this->actingAs($this->owner)
            ->post(route('owner.reservations.cancel', $reservation), ['reason' => 'Customer left early'])
            ->assertRedirect();

        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
    }
}
