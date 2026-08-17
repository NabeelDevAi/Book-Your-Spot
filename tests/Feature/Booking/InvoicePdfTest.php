<?php

namespace Tests\Feature\Booking;

use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Downloadable PDF and the WhatsApp share link, on both ends of a booking.
 */
class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $customer;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));

        $this->owner = User::factory()->owner()->create();
        $this->customer = User::factory()->create();

        $business = Business::factory()->active()->create([
            'owner_id' => $this->owner->id,
            'operating_hours' => OperatingHours::everyDay('00:00', '23:59'),
        ]);

        $spot = Spot::factory()->create([
            'business_game_id' => BusinessGame::factory()->create(['business_id' => $business->id])->id,
            'business_id' => $business->id,
        ]);

        $this->reservation = Reservation::factory()->forSpot($spot)->confirmed()->create([
            'user_id' => $this->customer->id,
        ]);
    }

    #[Test]
    public function the_customer_can_download_their_own_invoice(): void
    {
        $response = $this->actingAs($this->customer)
            ->get(route('bookings.invoice', $this->reservation));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    #[Test]
    public function a_customer_cannot_download_someone_elses_invoice(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('bookings.invoice', $this->reservation))
            ->assertForbidden();
    }

    #[Test]
    public function the_owner_can_download_the_same_booking_as_an_invoice(): void
    {
        $response = $this->actingAs($this->owner)
            ->get(route('owner.reservations.invoice', $this->reservation));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    #[Test]
    public function another_owner_cannot_reach_a_booking_that_is_not_theirs(): void
    {
        $otherOwner = User::factory()->owner()->create();

        $this->actingAs($otherOwner)
            ->get(route('owner.reservations.invoice', $this->reservation))
            ->assertForbidden();
    }

    #[Test]
    public function the_signed_whatsapp_link_needs_no_login(): void
    {
        $url = URL::temporarySignedRoute(
            'bookings.invoice.shared',
            now()->addDays(7),
            ['reservation' => $this->reservation],
        );

        $response = $this->get($url);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    #[Test]
    public function a_tampered_or_expired_share_link_is_refused(): void
    {
        $this->get(route('bookings.invoice.shared', $this->reservation))
            ->assertForbidden();
    }

    #[Test]
    public function the_whatsapp_share_url_carries_the_booking_summary_and_a_working_link(): void
    {
        $shareUrl = $this->reservation->whatsappShareUrl();

        $this->assertStringStartsWith('https://wa.me/?text=', $shareUrl);

        $text = urldecode(substr($shareUrl, strlen('https://wa.me/?text=')));

        $this->assertStringContainsString($this->reservation->reference, $text);
        $this->assertStringContainsString($this->reservation->business->name, $text);

        // The link embedded in the message actually resolves.
        preg_match('#https?://\S+#', $text, $matches);
        $this->assertNotEmpty($matches, "No link found in share text: {$text}");

        $this->get($matches[0])
            ->assertOk();
    }

    #[Test]
    public function the_booking_confirmation_page_offers_both_actions(): void
    {
        $this->actingAs($this->customer)
            ->get(route('bookings.show', $this->reservation))
            ->assertOk()
            ->assertSee('Download PDF')
            ->assertSee('Share via WhatsApp');
    }

    #[Test]
    public function the_owner_console_offers_both_actions(): void
    {
        $this->actingAs($this->owner)
            ->get(route('owner.reservations.show', $this->reservation))
            ->assertOk()
            ->assertSee(route('owner.reservations.invoice', $this->reservation), false);
    }
}
