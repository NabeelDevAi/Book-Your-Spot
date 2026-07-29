<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Enums\LedgerBucket;
use App\Enums\UserRole;
use App\Enums\WalletStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\PaymentException;
use App\Exceptions\WalletException;
use App\Models\PayoutAccount;
use App\Models\Topup;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Admin\TreasuryService;
use App\Services\Payment\TopupService;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

/**
 * Phase 7 -- platform oversight and the awkward cases.
 *
 * The float figures here are LIABILITIES, not revenue, and the reconciliation
 * check is the thing that says whether the platform still knows where all the
 * money is. A failure in either is an incident, so both are tested against
 * deliberately messy states rather than a clean ledger.
 */
class TreasuryTest extends TestCase
{
    use RefreshDatabase;

    private TreasuryService $treasury;

    private WalletService $wallets;

    private FakePaymentGateway $gateway;

    private User $customer;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->treasury = app(TreasuryService::class);
        $this->wallets = app(WalletService::class);

        $this->customer = User::factory()->broke()->create(['role' => UserRole::User]);
        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    private function topup(int $minor): Topup
    {
        return app(TopupService::class)->start($this->customer, $minor)->topup->refresh();
    }

    private function customerWallet(): Wallet
    {
        return $this->wallets->for($this->customer)->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Float and reconciliation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_float_reports_what_the_platform_owes(): void
    {
        $this->topup(500_000);

        DB::transaction(function () {
            $this->wallets->credit($this->wallets->lock($this->owner), 120_000, WalletTransactionType::BookingEarning);
        });

        $float = $this->treasury->float();

        $this->assertSame(500_000, $float['customer_balances_minor']);
        $this->assertSame(120_000, $float['owner_pending_minor']);
        $this->assertSame(500_000, $float['lifetime_topups_minor']);

        // Everything held on somebody else's behalf.
        $this->assertSame(620_000, $float['total_liability_minor']);
    }

    #[Test]
    public function a_healthy_ledger_reconciles(): void
    {
        $this->topup(500_000);

        $result = $this->treasury->reconcile();

        $this->assertTrue($result['balanced']);
        $this->assertSame(0, $result['discrepancy_minor']);
        $this->assertSame(0, $result['drifted_wallets']);
    }

    /**
     * The check has to actually catch something. Writing a balance behind
     * WalletService's back is exactly the corruption it exists to detect.
     */
    #[Test]
    public function reconciliation_detects_a_balance_written_behind_the_services_back(): void
    {
        $this->topup(500_000);

        Wallet::where('user_id', $this->customer->id)->update(['balance_minor' => 999_999]);

        $result = $this->treasury->reconcile();

        $this->assertFalse($result['balanced']);
        $this->assertSame(1, $result['drifted_wallets']);
    }

    #[Test]
    public function reconciliation_survives_money_moving_between_wallets(): void
    {
        $this->topup(500_000);

        DB::transaction(function () {
            [$customer, $owner] = $this->wallets->lockPair($this->customer, $this->owner);
            $this->wallets->debit($customer, 200_000, WalletTransactionType::BookingPayment);
            $this->wallets->credit($owner, 200_000, WalletTransactionType::BookingEarning);
        });

        $result = $this->treasury->reconcile();

        $this->assertTrue($result['balanced'], 'A transfer between wallets must not change the total.');
        $this->assertSame(500_000, $this->treasury->float()['total_liability_minor']);
    }

    #[Test]
    public function a_paid_withdrawal_reduces_the_liability(): void
    {
        $this->topup(500_000);

        DB::transaction(function () {
            $this->wallets->credit($this->wallets->lock($this->owner), 300_000, WalletTransactionType::EarningMaturedIn);
        });

        $account = PayoutAccount::create([
            'owner_id' => $this->owner->id,
            'bank_name' => 'Meezan',
            'account_title' => 'Venue',
            'account_number' => '123',
        ]);

        $withdrawals = app(WithdrawalService::class);
        $withdrawal = $withdrawals->request($this->owner, $account, 300_000);

        // Debited but not yet transferred -- still a liability.
        $this->assertSame(800_000, $this->treasury->float()['total_liability_minor']);

        $withdrawals->markPaid($withdrawal, $this->admin);

        // Gone from the platform.
        $this->assertSame(500_000, $this->treasury->float()['total_liability_minor']);
        $this->assertSame(300_000, $this->treasury->float()['lifetime_payouts_minor']);
    }

    /*
    |--------------------------------------------------------------------------
    | Adjustments
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_admin_can_credit_a_wallet_with_a_reason(): void
    {
        $this->treasury->adjust($this->customer, 50_000, credit: true, reason: 'Goodwill after a venue no-show', admin: $this->admin);

        $this->assertSame(50_000, $this->customerWallet()->balance_minor);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'wallet.adjusted',
            'actor_id' => $this->admin->id,
        ]);

        $this->assertTrue($this->treasury->reconcile()['balanced']);
    }

    #[Test]
    public function an_adjustment_cannot_drive_a_balance_negative(): void
    {
        $this->topup(100_000);

        $this->expectException(WalletException::class);

        $this->treasury->adjust($this->customer, 200_000, credit: false, reason: 'Correcting an error', admin: $this->admin);
    }

    #[Test]
    public function an_adjustment_can_target_pending_earnings(): void
    {
        $this->treasury->adjust(
            $this->owner, 75_000,
            credit: true, reason: 'Manual settlement', admin: $this->admin,
            bucket: LedgerBucket::Pending,
        );

        $wallet = $this->wallets->for($this->owner)->refresh();

        $this->assertSame(75_000, $wallet->pending_minor);
        $this->assertSame(0, $wallet->balance_minor);
    }

    /*
    |--------------------------------------------------------------------------
    | Freezing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function freezing_stops_spending_and_unfreezing_restores_it(): void
    {
        $this->topup(500_000);

        $this->treasury->freeze($this->customer, 'Suspected card fraud', $this->admin);

        $this->assertSame(WalletStatus::Frozen, $this->customerWallet()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'wallet.frozen']);

        $this->treasury->unfreeze($this->customer, $this->admin);

        $this->assertSame(WalletStatus::Active, $this->customerWallet()->status);
        $this->assertNull($this->customerWallet()->frozen_reason);
    }

    /*
    |--------------------------------------------------------------------------
    | Chargebacks
    |--------------------------------------------------------------------------
    */

    /**
     * The one case where a balance is permitted to go negative. The customer
     * spent the money, the venue was paid, and the bank is taking it back
     * regardless -- claiming the funds are still there would be a lie the
     * ledger cannot support.
     */
    #[Test]
    public function a_chargeback_recovers_money_that_has_already_been_spent(): void
    {
        $topup = $this->topup(500_000);

        // Customer spends most of it.
        DB::transaction(function () {
            $this->wallets->debit($this->wallets->lock($this->customer), 400_000, WalletTransactionType::BookingPayment);
        });

        $this->assertSame(100_000, $this->customerWallet()->balance_minor);

        $this->treasury->applyChargeback($topup, 'fraudulent');

        $wallet = $this->customerWallet();

        $this->assertSame(-400_000, $wallet->balance_minor);
        $this->assertSame(WalletStatus::Frozen, $wallet->status, 'A disputed wallet must stop spending.');
        $this->assertTrue($wallet->reconciles(), 'A negative balance must still match its ledger.');
    }

    #[Test]
    public function a_chargeback_is_idempotent(): void
    {
        $topup = $this->topup(500_000);

        $this->treasury->applyChargeback($topup, 'fraudulent');
        $this->treasury->applyChargeback($topup, 'fraudulent');
        $this->treasury->applyChargeback($topup, 'fraudulent');

        $this->assertSame(0, $this->customerWallet()->balance_minor);
        $this->assertSame(
            1,
            $this->customerWallet()->transactions()->ofType(WalletTransactionType::ChargebackReversal)->count(),
        );
    }

    /** A second dispute must be recoverable even though the first one froze the wallet. */
    #[Test]
    public function a_second_chargeback_works_on_an_already_frozen_wallet(): void
    {
        $first = $this->topup(300_000);
        $second = $this->topup(200_000);

        $this->treasury->applyChargeback($first, 'fraudulent');
        $this->treasury->applyChargeback($second, 'fraudulent');

        $this->assertSame(0, $this->customerWallet()->balance_minor);
        $this->assertTrue($this->customerWallet()->reconciles());
    }

    /**
     * With no gateway there is no dispute webhook, so a chargeback is recorded
     * by hand. The recovery itself is unchanged -- it is the same code a real
     * provider's callback will drive.
     */
    #[Test]
    public function an_admin_can_record_a_chargeback(): void
    {
        $topup = $this->topup(500_000);

        $this->actingAs($this->admin)
            ->post(route('admin.treasury.chargeback', $topup), ['reason' => 'Customer disputed the charge'])
            ->assertRedirect(route('admin.treasury.wallet', $this->customer));

        $this->assertSame(0, $this->customerWallet()->balance_minor);
        $this->assertSame(WalletStatus::Frozen, $this->customerWallet()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Refund to source
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_admin_can_refund_a_topup_to_the_original_card(): void
    {
        $topup = $this->topup(500_000);

        $refundId = $this->treasury->refundTopupToSource($topup, 'Customer asked to close their account', $this->admin);

        $this->assertSame(0, $this->customerWallet()->balance_minor);
        $this->assertStringStartsWith('re_fake_', $refundId);
        $this->assertSame(500_000, $this->gateway->refunds[0]['amount']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'topup.refunded']);
    }

    /** Refunding money that is no longer in the wallet would create it. */
    #[Test]
    public function a_refund_is_refused_when_the_money_has_already_been_spent(): void
    {
        $topup = $this->topup(500_000);

        DB::transaction(function () {
            $this->wallets->debit($this->wallets->lock($this->customer), 400_000, WalletTransactionType::BookingPayment);
        });

        $this->expectException(WalletException::class);

        $this->treasury->refundTopupToSource($topup, 'Too late', $this->admin);
    }

    /**
     * The compensation path. A gateway failure after the wallet was debited
     * must not silently leave the customer short.
     */
    #[Test]
    public function a_failed_gateway_refund_puts_the_money_back(): void
    {
        $topup = $this->topup(500_000);
        $this->gateway->refundShouldFail = true;

        try {
            $this->treasury->refundTopupToSource($topup, 'Attempted', $this->admin);
            $this->fail('Expected a PaymentException.');
        } catch (PaymentException) {
            $this->assertSame(500_000, $this->customerWallet()->balance_minor);
            $this->assertTrue($this->customerWallet()->reconciles());
        }
    }

    #[Test]
    public function only_a_settled_topup_can_be_refunded(): void
    {
        // Explicitly asynchronous: against the simulated gateway a top-up is
        // settled before start() returns, so an unsettled one cannot otherwise
        // be produced. The guard still matters for a real provider.
        $this->gateway->asynchronous();

        $started = app(TopupService::class)->start($this->customer, 500_000);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('successfully settled');

        $this->treasury->refundTopupToSource($started->topup, 'Never paid', $this->admin);
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP surface
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_treasury_dashboard_leads_with_total_liability(): void
    {
        $this->topup(500_000);

        $this->actingAs($this->admin)
            ->get(route('admin.treasury.index'))
            ->assertOk()
            ->assertSee('Total liability')
            ->assertSee('Books balance')
            ->assertSee('Rs. 5,000');
    }

    #[Test]
    public function the_dashboard_shouts_when_the_books_do_not_balance(): void
    {
        $this->topup(500_000);
        Wallet::where('user_id', $this->customer->id)->update(['balance_minor' => 111_111]);

        $this->actingAs($this->admin)
            ->get(route('admin.treasury.index'))
            ->assertOk()
            ->assertSee('do not balance');
    }

    #[Test]
    public function the_wallet_inspector_shows_the_full_ledger(): void
    {
        $this->topup(500_000);

        $this->actingAs($this->admin)
            ->get(route('admin.treasury.wallet', $this->customer))
            ->assertOk()
            ->assertSee('Ledger')
            ->assertSee('Top-up')
            ->assertSee('Rs. 5,000');
    }

    #[Test]
    public function owners_cannot_reach_the_treasury(): void
    {
        $this->actingAs($this->owner)->get(route('admin.treasury.index'))->assertForbidden();
        $this->actingAs($this->customer)->get(route('admin.treasury.index'))->assertForbidden();
    }

    #[Test]
    public function an_adjustment_over_http_requires_a_reason(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.treasury.adjust', $this->customer), [
                'amount' => 100,
                'direction' => 'credit',
                'bucket' => 'balance',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, $this->customerWallet()->balance_minor);
    }
}
