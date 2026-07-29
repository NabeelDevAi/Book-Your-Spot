<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Enums\TopupStatus;
use App\Enums\UserRole;
use App\Enums\WalletTransactionType;
use App\Exceptions\PaymentException;
use App\Models\Topup;
use App\Models\User;
use App\Services\Payment\TopupService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

/**
 * Phase 2 -- money into a wallet.
 *
 * Payments are SIMULATED: the gateway settles synchronously and nothing is
 * really charged. What is NOT simulated is everything after the credit -- the
 * ledger row, the idempotency guard, the amount check -- so those are tested
 * here exactly as they would be against a live provider.
 *
 * The asynchronous cases are kept alive against a fake gateway rather than
 * deleted, because they are the ones that will matter the day a real provider
 * is plugged in and `fulfil()` starts being called from a callback.
 */
class TopupTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->customer = User::factory()->broke()->create(['role' => UserRole::User]);
    }

    private function topups(): TopupService
    {
        return $this->app->make(TopupService::class);
    }

    private function balanceMinor(): int
    {
        return $this->app->make(WalletService::class)->for($this->customer)->refresh()->balance_minor;
    }

    /*
    |--------------------------------------------------------------------------
    | Instant settlement
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_topup_settles_and_credits_the_wallet_immediately(): void
    {
        $started = $this->topups()->start($this->customer, 100_000);

        $this->assertSame(TopupStatus::Succeeded, $started->topup->status);
        $this->assertSame(100_000, $this->balanceMinor());
        $this->assertNotNull($started->topup->succeeded_at);
    }

    #[Test]
    public function it_records_the_attempt_with_a_gateway_reference(): void
    {
        $started = $this->topups()->start($this->customer, 100_000);

        $this->assertDatabaseHas('topups', [
            'id' => $started->topup->id,
            'user_id' => $this->customer->id,
            'amount_minor' => 100_000,
            'status' => TopupStatus::Succeeded->value,
        ]);

        $this->assertNotNull($started->topup->gateway_reference);
    }

    #[Test]
    public function the_credit_goes_through_the_ledger(): void
    {
        $this->topups()->start($this->customer, 100_000);

        $wallet = $this->app->make(WalletService::class)->for($this->customer);

        $this->assertSame(1, $wallet->transactions()->ofType(WalletTransactionType::Topup)->count());
        $this->assertTrue($wallet->reconciles(), 'A simulated credit must still reconcile.');
    }

    #[Test]
    public function a_gateway_failure_leaves_a_failed_record_rather_than_nothing(): void
    {
        $this->gateway->shouldFail = true;

        try {
            $this->topups()->start($this->customer, 100_000);
            $this->fail('Expected a PaymentException.');
        } catch (PaymentException) {
            // A charge with no local record is the one outcome that cannot be
            // reconciled afterwards, so the row must survive the failure.
            $this->assertDatabaseHas('topups', [
                'user_id' => $this->customer->id,
                'status' => TopupStatus::Failed->value,
            ]);
            $this->assertSame(0, $this->balanceMinor());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_enforces_the_topup_floor(): void
    {
        $this->assertSame(50_000, (int) config('wallet.min_topup_minor'));

        $this->expectException(PaymentException::class);
        $this->topups()->start($this->customer, 49_900);
    }

    #[Test]
    public function it_rejects_a_topup_above_the_ceiling(): void
    {
        $this->assertSame(5_000_000, (int) config('wallet.max_topup_minor'));

        $this->expectException(PaymentException::class);
        $this->topups()->start($this->customer, 5_000_100);
    }

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    | Nothing calls fulfil() twice today, but a real gateway's callback will --
    | and it may arrive after the reconcile sweep has already settled the row.
    */

    #[Test]
    public function fulfilling_twice_credits_only_once(): void
    {
        $started = $this->topups()->start($this->customer, 100_000);

        $this->assertFalse(
            $this->topups()->fulfil($started->topup->refresh(), 100_000, 'ch_again'),
            'A second fulfil must report that it did nothing.',
        );

        $this->assertSame(100_000, $this->balanceMinor());

        $wallet = $this->app->make(WalletService::class)->for($this->customer);
        $this->assertSame(1, $wallet->transactions()->ofType(WalletTransactionType::Topup)->count());
    }

    /** The customer's real money is the authority, not what was asked for. */
    #[Test]
    public function it_credits_the_amount_the_gateway_confirms(): void
    {
        $this->gateway->asynchronous();

        $started = $this->topups()->start($this->customer, 100_000);
        $this->assertSame(0, $this->balanceMinor());

        $this->topups()->fulfil($started->topup, 90_000, 'ch_short');

        $this->assertSame(90_000, $this->balanceMinor());
        $this->assertSame(90_000, $started->topup->refresh()->amount_minor);
    }

    /*
    |--------------------------------------------------------------------------
    | Asynchronous providers -- dormant today, load-bearing tomorrow
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_asynchronous_gateway_leaves_the_topup_pending(): void
    {
        $this->gateway->asynchronous();

        $started = $this->topups()->start($this->customer, 100_000);

        $this->assertSame(TopupStatus::Pending, $started->topup->status);
        $this->assertSame(0, $this->balanceMinor());
    }

    #[Test]
    public function the_reconcile_sweep_settles_a_payment_that_never_confirmed(): void
    {
        $this->gateway->asynchronous();

        $started = $this->topups()->start($this->customer, 100_000);
        $this->gateway->markSucceeded($started->topup->gateway_reference);

        Topup::whereKey($started->topup->id)->update(['created_at' => now()->subHour()]);

        $this->artisan('topups:reconcile')->assertSuccessful();

        $this->assertSame(100_000, $this->balanceMinor());
        $this->assertSame(TopupStatus::Succeeded, $started->topup->refresh()->status);
    }

    #[Test]
    public function the_reconcile_sweep_leaves_a_still_pending_payment_alone(): void
    {
        $this->gateway->asynchronous();

        $started = $this->topups()->start($this->customer, 100_000);
        Topup::whereKey($started->topup->id)->update(['created_at' => now()->subHour()]);

        $this->artisan('topups:reconcile')->assertSuccessful();

        $this->assertSame(TopupStatus::Pending, $started->topup->refresh()->status);
        $this->assertSame(0, $this->balanceMinor());
    }

    #[Test]
    public function the_reconcile_sweep_cancels_an_abandoned_payment(): void
    {
        $this->gateway->asynchronous();

        $started = $this->topups()->start($this->customer, 100_000);
        $this->gateway->markCancelled($started->topup->gateway_reference);
        Topup::whereKey($started->topup->id)->update(['created_at' => now()->subHour()]);

        $this->artisan('topups:reconcile')->assertSuccessful();

        $this->assertSame(TopupStatus::Cancelled, $started->topup->refresh()->status);
        $this->assertSame(0, $this->balanceMinor());
    }

    /** With instant settlement there is nothing stale to find. */
    #[Test]
    public function the_reconcile_sweep_is_a_no_op_against_a_synchronous_gateway(): void
    {
        $this->topups()->start($this->customer, 100_000);
        Topup::query()->update(['created_at' => now()->subDay()]);

        $this->artisan('topups:reconcile')->assertSuccessful();

        $this->assertSame(100_000, $this->balanceMinor());
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP surface
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_customer_can_top_up_from_the_wallet_page(): void
    {
        $this->actingAs($this->customer)
            ->post(route('wallet.topup'), ['amount' => 1000])
            ->assertRedirect(route('wallet.show'))
            ->assertSessionHas('success');

        $this->assertSame(100_000, $this->balanceMinor());
    }

    /** Nobody should be able to add money without noticing it is not real. */
    #[Test]
    public function the_wallet_page_says_plainly_that_payments_are_not_real(): void
    {
        $this->actingAs($this->customer)
            ->get(route('wallet.show'))
            ->assertOk()
            ->assertSee('Test mode')
            ->assertSee('no card is charged');
    }

    #[Test]
    public function the_wallet_page_shows_the_available_balance(): void
    {
        $this->topups()->start($this->customer, 100_000);

        $this->actingAs($this->customer)
            ->get(route('wallet.show'))
            ->assertOk()
            ->assertSee('Rs. 1,000')
            ->assertSee('Available to spend');
    }

    #[Test]
    public function the_topup_form_rejects_an_amount_below_the_floor(): void
    {
        $this->actingAs($this->customer)
            ->post(route('wallet.topup'), ['amount' => 100])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('topups', 0);
        $this->assertSame(0, $this->balanceMinor());
    }

    #[Test]
    public function the_topup_form_rejects_an_amount_above_the_ceiling(): void
    {
        $this->actingAs($this->customer)
            ->post(route('wallet.topup'), ['amount' => 500_000])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('topups', 0);
    }

    #[Test]
    public function owners_cannot_reach_the_wallet(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)->get(route('wallet.show'))->assertForbidden();
    }

    #[Test]
    public function a_guest_cannot_top_up(): void
    {
        $this->post(route('wallet.topup'), ['amount' => 1000])->assertRedirect(route('login'));

        $this->assertDatabaseCount('topups', 0);
    }
}
