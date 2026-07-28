<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Services\Booking\ReservationService;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The in-app notification centre.
 *
 * V1 sends no email or SMS, so this is the only channel that exists. If a
 * notification cannot be seen here, the user never learns about it at all --
 * which makes these tests load-bearing rather than cosmetic.
 */
class NotificationCentreTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $owner;

    private Spot $spot;

    private ReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));

        $this->service = app(ReservationService::class);
        $this->customer = User::factory()->create();

        $business = Business::factory()->active()->create([
            'operating_hours' => OperatingHours::everyDay('00:00', '23:59'),
        ]);
        $this->owner = $business->owner;

        $this->spot = Spot::factory()->create([
            'business_game_id' => BusinessGame::factory()->create(['business_id' => $business->id])->id,
            'business_id' => $business->id,
        ]);
    }

    private function request(): Reservation
    {
        return $this->service->request(
            $this->spot,
            $this->customer,
            Carbon::parse('2026-08-02 19:00'),
            60,
        );
    }

    #[Test]
    public function a_new_request_reaches_the_owners_notification_centre(): void
    {
        $this->request();

        $this->actingAs($this->owner)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('New booking request')
            ->assertSee($this->customer->name);
    }

    #[Test]
    public function a_confirmation_reaches_the_customers_notification_centre(): void
    {
        $this->service->approve($this->request(), $this->owner);

        $this->actingAs($this->customer)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Booking confirmed');
    }

    #[Test]
    public function the_bell_shows_an_unread_count(): void
    {
        $this->request();

        $this->actingAs($this->owner)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee('data-bell-badge', false)
            ->assertSee('New booking request');
    }

    #[Test]
    public function the_unread_endpoint_returns_the_latest_items(): void
    {
        $this->request();

        $response = $this->actingAs($this->owner)->getJson(route('notifications.unread'));

        $response->assertOk()->assertJsonStructure([
            'count',
            'items' => [['id', 'title', 'body', 'icon', 'tone', 'url', 'ago']],
        ]);

        $this->assertSame(1, $response->json('count'));
    }

    #[Test]
    public function an_owners_notification_links_to_their_queue_not_the_customer_page(): void
    {
        // The same notification type means different things to each side, so the
        // link has to depend on who is reading it.
        $reservation = $this->request();

        $ownerUrl = $this->actingAs($this->owner)->getJson(route('notifications.unread'))->json('items.0.url');
        $this->assertStringContainsString('/owner/reservations/'.$reservation->reference, $ownerUrl);

        $this->service->approve($reservation, $this->owner);

        $customerUrl = $this->actingAs($this->customer)->getJson(route('notifications.unread'))->json('items.0.url');
        $this->assertStringContainsString('/bookings/'.$reservation->reference, $customerUrl);
    }

    #[Test]
    public function opening_a_notification_marks_it_read_and_follows_it(): void
    {
        $reservation = $this->request();

        $notification = $this->owner->unreadNotifications()->sole();

        $this->actingAs($this->owner)
            ->get(route('notifications.read', $notification->id))
            ->assertRedirect(route('owner.reservations.show', $reservation->reference));

        $this->assertSame(0, $this->owner->fresh()->unreadNotifications()->count());
    }

    #[Test]
    public function a_user_cannot_open_someone_elses_notification(): void
    {
        $this->request();
        $notification = $this->owner->unreadNotifications()->sole();

        $this->actingAs($this->customer)
            ->get(route('notifications.read', $notification->id))
            ->assertNotFound();

        $this->assertSame(1, $this->owner->fresh()->unreadNotifications()->count());
    }

    #[Test]
    public function all_notifications_can_be_marked_read_at_once(): void
    {
        $this->request();
        $this->service->request($this->spot, User::factory()->create(), Carbon::parse('2026-08-03 19:00'), 60);

        $this->assertSame(2, $this->owner->unreadNotifications()->count());

        $this->actingAs($this->owner)->post(route('notifications.read-all'))->assertRedirect();

        $this->assertSame(0, $this->owner->fresh()->unreadNotifications()->count());
    }

    #[Test]
    public function an_empty_centre_says_so(): void
    {
        $this->actingAs($this->customer)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('No notifications yet');
    }

    #[Test]
    public function the_centre_renders_in_the_right_shell_for_each_role(): void
    {
        // Customers get the public shell, owners and admins the console -- the
        // page is shared but the surrounding navigation must not be.
        $this->actingAs($this->customer)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Browse venues');

        $this->actingAs($this->owner)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('View public site');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Users &amp; owners', false);
    }

    #[Test]
    public function a_declined_request_tells_the_customer_it_was_a_race_not_a_refusal(): void
    {
        $winner = $this->request();
        $loser = $this->service->request(
            $this->spot,
            User::factory()->create(),
            Carbon::parse('2026-08-02 19:00'),
            60,
        );

        $this->service->approve($winner, $this->owner);

        $this->actingAs($loser->user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Slot taken by another booking');
    }
}
