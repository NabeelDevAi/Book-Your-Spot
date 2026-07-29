<?php

namespace Tests\Feature\Booking;

use App\Enums\HoldStatus;
use App\Enums\RejectionReason;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Exceptions\BookingException;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Services\Booking\ReservationService;
use App\Services\Wallet\WalletService;
use App\Support\OperatingHours;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phases 3 and 4 -- money moving through the booking lifecycle.
 *
 * Two invariants run through every test here:
 *
 *  1. Money is conserved. Whatever leaves a customer arrives somewhere; a
 *     released hold restores exactly what it reserved.
 *
 *  2. An Owner's earnings stay in PENDING until they mature. That is what makes
 *     a refund possible without clawing back money already paid out, and half
 *     these tests would still pass if it were broken -- so it is asserted
 *     explicitly rather than inferred.
 *
 * The spot bills at Rs 100 per 10 minutes, so a 60-minute booking is
 * Rs 600 = 60,000 paisa. Customers start with the factory default of Rs 50,000.
 */
class BookingSettlementTest extends TestCase
{
    use RefreshDatabase;

    private const HOUR_MINOR = 60_000;      // Rs 600, a 60-minute booking

    private ReservationService $service;

    private WalletService $wallets;

    private User $customer;

    private User $owner;

    private Business $business;

    private Spot $spot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));

        $this->service = app(ReservationService::class);
        $this->wallets = app(WalletService::class);

        $this->customer = User::factory()->create();

        $this->business = Business::factory()->active()->create([
            'operating_hours' => OperatingHours::everyDay('10:00', '23:00'),
        ]);
        $this->owner = $this->business->owner;

        $this->spot = Spot::factory()->create([
            'business_game_id' => BusinessGame::factory()->create(['business_id' => $this->business->id])->id,
            'business_id' => $this->business->id,
            'price_amount' => 100,
            'price_unit_minutes' => 10,
            'min_duration_minutes' => 30,
            'max_duration_minutes' => 240,
        ]);
    }

    private function at(int $hour, int $minute = 0, string $day = '2026-08-02'): Carbon
    {
        return Carbon::parse($day)->setTime($hour, $minute);
    }

    private function request(?User $user = null, ?Carbon $start = null, int $duration = 60): Reservation
    {
        return $this->service->request($this->spot, $user ?? $this->customer, $start ?? $this->at(19), $duration);
    }

    private function walletOf(User $user): Wallet
    {
        return $this->wallets->for($user)->refresh();
    }

    private function startBalance(): int
    {
        return UserFactory::DEFAULT_WALLET_MINOR;
    }

    /*
    |--------------------------------------------------------------------------
    | Requesting places a hold
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function requesting_holds_the_full_price_without_moving_it(): void
    {
        $reservation = $this->request();

        $wallet = $this->walletOf($this->customer);

        $this->assertSame(self::HOUR_MINOR, $reservation->amount_paid_minor);
        $this->assertSame($this->startBalance(), $wallet->balance_minor, 'A hold must not move the balance.');
        $this->assertSame(self::HOUR_MINOR, $wallet->held_minor);
        $this->assertSame($this->startBalance() - self::HOUR_MINOR, $wallet->availableMinor());

        $this->assertDatabaseHas('wallet_holds', [
            'reservation_id' => $reservation->id,
            'amount_minor' => self::HOUR_MINOR,
            'status' => HoldStatus::Active->value,
        ]);
    }

    #[Test]
    public function requesting_snapshots_the_refund_policy(): void
    {
        $reservation = $this->request();

        $this->assertIsArray($reservation->refund_policy_snapshot);
        $this->assertSame(config('wallet.refund_tiers'), $reservation->refund_policy_snapshot);
    }

    /**
     * A later policy change must not reach a booking already made -- the same
     * rule the price snapshot follows (SRS 9.10).
     */
    #[Test]
    public function a_later_policy_change_does_not_reach_an_existing_booking(): void
    {
        $reservation = $this->request();

        config(['wallet.refund_tiers' => [['min_hours_before' => 0, 'refund_percent' => 0]]]);

        // Booked well over 24h out, so the snapshot says 100%.
        $this->service->cancel($reservation, $this->customer);

        $this->assertSame(self::HOUR_MINOR, $reservation->fresh()->refund_amount_minor);
    }

    #[Test]
    public function a_customer_who_cannot_afford_it_is_refused_and_nothing_is_written(): void
    {
        $broke = User::factory()->withWalletBalance(50_000)->create();

        try {
            $this->request($broke);
            $this->fail('Expected a BookingException.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('Top up', $e->getMessage());
            $this->assertSame('amount', $e->field);
        }

        $this->assertDatabaseCount('reservations', 0);
        $this->assertSame(0, $this->walletOf($broke)->held_minor);
    }

    /**
     * The distinction the available balance exists for: enough money in total,
     * but not enough that is not already committed elsewhere.
     */
    #[Test]
    public function funds_held_by_another_request_cannot_be_spent_twice(): void
    {
        $customer = User::factory()->withWalletBalance(100_000)->create();

        $this->request($customer, $this->at(19));

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Top up');

        // Rs 400 available against a Rs 600 booking.
        $this->request($customer, $this->at(21));
    }

    #[Test]
    public function a_frozen_wallet_cannot_book(): void
    {
        Wallet::where('user_id', $this->customer->id)->update(['status' => 'frozen']);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('on hold');

        $this->request();
    }

    /*
    |--------------------------------------------------------------------------
    | Approval captures
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function approving_moves_the_money_into_the_owners_pending_earnings(): void
    {
        $reservation = $this->request();

        $this->service->approve($reservation, $this->owner);

        $customerWallet = $this->walletOf($this->customer);
        $ownerWallet = $this->walletOf($this->owner);

        $this->assertSame($this->startBalance() - self::HOUR_MINOR, $customerWallet->balance_minor);
        $this->assertSame(0, $customerWallet->held_minor);

        // PENDING, not withdrawable. The maturity window is the entire reason a
        // later refund never needs a clawback.
        $this->assertSame(self::HOUR_MINOR, $ownerWallet->pending_minor);
        $this->assertSame(0, $ownerWallet->balance_minor);

        $this->assertDatabaseHas('wallet_holds', [
            'reservation_id' => $reservation->id,
            'status' => HoldStatus::Captured->value,
        ]);
    }

    /**
     * The competition mechanic under money. Several customers queue on one slot;
     * the losers must get their funds back the instant they are auto-rejected,
     * or a popular slot would freeze three people's balances for nothing.
     */
    #[Test]
    public function losing_the_race_releases_the_losers_funds_immediately(): void
    {
        $winner = User::factory()->create();
        $loserOne = User::factory()->create();
        $loserTwo = User::factory()->create();

        $winning = $this->request($winner);
        $this->request($loserOne);
        $this->request($loserTwo);

        foreach ([$loserOne, $loserTwo] as $loser) {
            $this->assertSame(self::HOUR_MINOR, $this->walletOf($loser)->held_minor);
        }

        $this->service->approve($winning, $this->owner);

        foreach ([$loserOne, $loserTwo] as $loser) {
            $wallet = $this->walletOf($loser);

            $this->assertSame(0, $wallet->held_minor, 'A losing request must not keep holding funds.');
            $this->assertSame($this->startBalance(), $wallet->balance_minor);
            $this->assertSame($this->startBalance(), $wallet->availableMinor());

            // Nothing moved, so nothing beyond the factory top-up is in the ledger.
            $this->assertSame(1, $wallet->transactions()->count());
        }

        $this->assertDatabaseHas('wallet_holds', [
            'wallet_id' => $this->walletOf($loserOne)->id,
            'status' => HoldStatus::Released->value,
            'released_reason' => WalletHold::REASON_SLOT_TAKEN,
        ]);
    }

    #[Test]
    public function rejecting_releases_the_hold(): void
    {
        $reservation = $this->request();

        $this->service->reject($reservation, $this->owner, RejectionReason::FullyBooked);

        $wallet = $this->walletOf($this->customer);

        $this->assertSame(0, $wallet->held_minor);
        $this->assertSame($this->startBalance(), $wallet->availableMinor());
        $this->assertSame(0, $this->walletOf($this->owner)->pending_minor);
    }

    /** The customer should not be out of pocket for a venue's silence. */
    #[Test]
    public function expiry_releases_the_hold(): void
    {
        $reservation = $this->request();

        $this->travelTo($reservation->response_deadline->copy()->addMinute());
        $this->service->expire($reservation);

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        $this->assertSame(0, $this->walletOf($this->customer)->held_minor);
        $this->assertSame($this->startBalance(), $this->walletOf($this->customer)->availableMinor());
    }

    /*
    |--------------------------------------------------------------------------
    | Cancellation tiers
    |--------------------------------------------------------------------------
    */

    /**
     * Cancelling a request the venue never accepted returns everything,
     * whatever the clock says. The tiers compensate a venue for a slot they
     * committed to; nothing was committed here.
     */
    #[Test]
    public function cancelling_an_unapproved_request_always_returns_everything(): void
    {
        $reservation = $this->request(start: $this->at(11));

        // Well inside the no-refund tier, had it been confirmed.
        $this->travelTo($this->at(10, 30));

        $this->service->cancel($reservation, $this->customer);

        $wallet = $this->walletOf($this->customer);

        $this->assertSame(0, $wallet->held_minor);
        $this->assertSame($this->startBalance(), $wallet->balance_minor);
        $this->assertSame(0, $this->walletOf($this->owner)->pending_minor);
    }

    #[Test]
    public function cancelling_a_confirmed_booking_more_than_24h_out_refunds_in_full(): void
    {
        $reservation = $this->request(start: $this->at(19, 0, '2026-08-05'));
        $this->service->approve($reservation, $this->owner);

        $this->service->cancel($reservation, $this->customer);

        $this->assertSame(self::HOUR_MINOR, $reservation->fresh()->refund_amount_minor);
        $this->assertSame(0, $reservation->fresh()->owner_settlement_minor);
        $this->assertSame($this->startBalance(), $this->walletOf($this->customer)->balance_minor);
        $this->assertSame(0, $this->walletOf($this->owner)->pending_minor);
    }

    #[Test]
    public function cancelling_between_2_and_24_hours_out_refunds_half(): void
    {
        $reservation = $this->request(start: $this->at(19));
        $this->service->approve($reservation, $this->owner);

        $this->travelTo($this->at(13));      // six hours before
        $this->service->cancel($reservation, $this->customer);

        $half = (int) (self::HOUR_MINOR / 2);

        $this->assertSame($half, $reservation->fresh()->refund_amount_minor);
        $this->assertSame($half, $reservation->fresh()->owner_settlement_minor);
        $this->assertSame($this->startBalance() - $half, $this->walletOf($this->customer)->balance_minor);
        $this->assertSame($half, $this->walletOf($this->owner)->pending_minor);
    }

    #[Test]
    public function cancelling_inside_2_hours_forfeits_the_payment(): void
    {
        $reservation = $this->request(start: $this->at(19));
        $this->service->approve($reservation, $this->owner);

        $this->travelTo($this->at(18));      // one hour before
        $this->service->cancel($reservation, $this->customer);

        $this->assertSame(0, $reservation->fresh()->refund_amount_minor);
        $this->assertSame(self::HOUR_MINOR, $reservation->fresh()->owner_settlement_minor);
        $this->assertSame($this->startBalance() - self::HOUR_MINOR, $this->walletOf($this->customer)->balance_minor);
        $this->assertSame(self::HOUR_MINOR, $this->walletOf($this->owner)->pending_minor);
    }

    /**
     * The asymmetry that makes "confirmed" mean something. An Owner cancelling
     * five minutes beforehand still refunds in full.
     */
    #[Test]
    public function an_owner_cancelling_at_the_last_minute_still_refunds_in_full(): void
    {
        $reservation = $this->request(start: $this->at(19));
        $this->service->approve($reservation, $this->owner);

        $this->travelTo($this->at(18, 55));
        $this->service->cancel($reservation, $this->owner, 'Table damaged');

        $this->assertSame(self::HOUR_MINOR, $reservation->fresh()->refund_amount_minor);
        $this->assertSame(0, $reservation->fresh()->owner_settlement_minor);
        $this->assertSame($this->startBalance(), $this->walletOf($this->customer)->balance_minor);
        $this->assertSame(0, $this->walletOf($this->owner)->pending_minor);
    }

    #[Test]
    public function an_admin_cancelling_refunds_in_full(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $reservation = $this->request(start: $this->at(19));
        $this->service->approve($reservation, $this->owner);

        $this->travelTo($this->at(18, 30));
        $this->service->cancel($reservation, $admin, 'Venue suspended');

        $this->assertSame(self::HOUR_MINOR, $reservation->fresh()->refund_amount_minor);
        $this->assertSame($this->startBalance(), $this->walletOf($this->customer)->balance_minor);
    }

    /** The first time no_show_count has real money behind it. */
    #[Test]
    public function a_no_show_pays_the_venue_in_full(): void
    {
        $reservation = $this->request(start: $this->at(19));
        $this->service->approve($reservation, $this->owner);

        $this->travelTo($this->at(21));
        $this->service->flagNoShow($reservation, $this->owner);

        $this->assertSame(ReservationStatus::NoShow, $reservation->fresh()->status);
        $this->assertSame(0, $reservation->fresh()->refund_amount_minor);
        $this->assertSame(self::HOUR_MINOR, $reservation->fresh()->owner_settlement_minor);
        $this->assertSame(self::HOUR_MINOR, $this->walletOf($this->owner)->pending_minor);
        $this->assertSame($this->startBalance() - self::HOUR_MINOR, $this->walletOf($this->customer)->balance_minor);
    }

    /*
    |--------------------------------------------------------------------------
    | Maturity
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function earnings_stay_pending_until_the_dispute_window_has_passed(): void
    {
        $reservation = $this->request(start: $this->at(19));
        $this->service->approve($reservation, $this->owner);

        $this->travelTo($this->at(21));
        $this->service->complete($reservation);

        // Just after the booking ended -- inside the window.
        $this->artisan('wallet:mature')->assertSuccessful();

        $this->assertSame(self::HOUR_MINOR, $this->walletOf($this->owner)->pending_minor);
        $this->assertSame(0, $this->walletOf($this->owner)->balance_minor);
        $this->assertNull($reservation->fresh()->earnings_matured_at);
    }

    #[Test]
    public function earnings_become_withdrawable_once_the_window_closes(): void
    {
        $reservation = $this->request(start: $this->at(19));
        $this->service->approve($reservation, $this->owner);

        $this->travelTo($this->at(21));
        $this->service->complete($reservation);

        $this->travelTo($this->at(21)->addHours((int) config('wallet.maturity_hours') + 1));
        $this->artisan('wallet:mature')->assertSuccessful();

        $ownerWallet = $this->walletOf($this->owner);

        $this->assertSame(0, $ownerWallet->pending_minor);
        $this->assertSame(self::HOUR_MINOR, $ownerWallet->balance_minor);
        $this->assertNotNull($reservation->fresh()->earnings_matured_at);
    }

    #[Test]
    public function maturity_is_idempotent(): void
    {
        $reservation = $this->request(start: $this->at(19));
        $this->service->approve($reservation, $this->owner);

        $this->travelTo($this->at(21));
        $this->service->complete($reservation);

        $this->travelTo($this->at(21)->addHours(48));
        $this->artisan('wallet:mature')->assertSuccessful();
        $this->artisan('wallet:mature')->assertSuccessful();
        $this->artisan('wallet:mature')->assertSuccessful();

        $this->assertSame(self::HOUR_MINOR, $this->walletOf($this->owner)->balance_minor);
    }

    /** A late cancellation leaves the venue a share, and that share matures too. */
    #[Test]
    public function a_late_cancellations_owner_share_matures(): void
    {
        $reservation = $this->request(start: $this->at(19));
        $this->service->approve($reservation, $this->owner);

        $this->travelTo($this->at(18));
        $this->service->cancel($reservation, $this->customer);

        $this->travelTo($this->at(20)->addHours(48));
        $this->artisan('wallet:mature')->assertSuccessful();

        $this->assertSame(self::HOUR_MINOR, $this->walletOf($this->owner)->balance_minor);
        $this->assertSame(0, $this->walletOf($this->owner)->pending_minor);
    }

    /*
    |--------------------------------------------------------------------------
    | Conservation
    |--------------------------------------------------------------------------
    */

    /**
     * The capstone. A mixed sequence of outcomes across several customers, then
     * assert that every rupee is still accounted for and every wallet agrees
     * with its own ledger.
     */
    #[Test]
    public function money_is_conserved_across_a_mixed_lifecycle(): void
    {
        $customers = User::factory()->count(4)->create();
        $expectedTotal = $this->startBalance() * 5;   // four here plus setUp's

        // 1. Confirmed, played, matured.
        $played = $this->request($customers[0], $this->at(11));
        $this->service->approve($played, $this->owner);

        // 2. Confirmed then cancelled late -- venue keeps everything.
        $lateCancel = $this->request($customers[1], $this->at(14));
        $this->service->approve($lateCancel, $this->owner);

        // 3. Rejected outright.
        $rejected = $this->request($customers[2], $this->at(16));
        $this->service->reject($rejected, $this->owner, RejectionReason::FullyBooked);

        // 4. Still pending, funds held.
        $this->request($customers[3], $this->at(21));

        $this->travelTo($this->at(13, 30));
        $this->service->complete($played);
        $this->service->cancel($lateCancel, $customers[1]);

        $this->travelTo($this->at(15)->addHours(48));
        $this->artisan('wallet:mature')->assertSuccessful();

        $ownerWallet = $this->walletOf($this->owner);
        $total = $ownerWallet->balance_minor + $ownerWallet->pending_minor;

        foreach ($customers as $customer) {
            $wallet = $this->walletOf($customer);
            $total += $wallet->balance_minor;

            $this->assertTrue($wallet->reconciles(), "Customer {$customer->id} drifted from its ledger.");
        }

        $total += $this->walletOf($this->customer)->balance_minor;

        $this->assertTrue($ownerWallet->reconciles(), 'Owner wallet drifted from its ledger.');
        $this->assertSame($expectedTotal, $total, 'Money was created or destroyed.');

        // Both fully-charged bookings ended with the venue, and both matured.
        $this->assertSame(self::HOUR_MINOR * 2, $ownerWallet->balance_minor);
        $this->assertSame(0, $ownerWallet->pending_minor);
    }
}
