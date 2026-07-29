<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\WalletTransactionType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\WithdrawalException;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 6 -- money leaving the platform.
 *
 * The invariant that matters most: the balance is debited when the withdrawal
 * is REQUESTED, not when it is paid. Several tests exist purely to prove an
 * Owner cannot queue two withdrawals against one balance, because discovering
 * that at transfer time means an Admin has already sent the first one.
 *
 * The second invariant: only MATURED earnings are withdrawable. Pending
 * earnings are still inside their dispute window and may yet be refunded.
 */
class WithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private WithdrawalService $withdrawals;

    private WalletService $wallets;

    private User $owner;

    private User $admin;

    private PayoutAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withdrawals = app(WithdrawalService::class);
        $this->wallets = app(WalletService::class);

        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->account = PayoutAccount::create([
            'owner_id' => $this->owner->id,
            'bank_name' => 'Meezan Bank',
            'account_title' => 'Cue & Console Gaming',
            'account_number' => '01234567890123',
            'iban' => 'PK36MEZN0001234567890123',
        ]);
    }

    /** Give the owner withdrawable (settled) money, through the ledger. */
    private function fundOwner(int $minor): Wallet
    {
        return DB::transaction(function () use ($minor) {
            $wallet = $this->wallets->lock($this->owner);
            $this->wallets->credit($wallet, $minor, WalletTransactionType::EarningMaturedIn);

            return $wallet;
        });
    }

    private function ownerWallet(): Wallet
    {
        return $this->wallets->for($this->owner)->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Requesting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function requesting_debits_the_balance_immediately(): void
    {
        $this->fundOwner(500_000);

        $withdrawal = $this->withdrawals->request($this->owner, $this->account, 300_000);

        $this->assertSame(WithdrawalStatus::Requested, $withdrawal->status);
        $this->assertSame(200_000, $this->ownerWallet()->balance_minor);

        $this->assertDatabaseHas('wallet_transactions', [
            'withdrawal_id' => $withdrawal->id,
            'type' => WalletTransactionType::WithdrawalDebit->value,
            'amount_minor' => -300_000,
        ]);
    }

    /**
     * The reason the debit happens at request time. Debiting at settlement
     * would let this pass and leave an Admin to discover it mid-transfer.
     */
    #[Test]
    public function an_owner_cannot_queue_two_withdrawals_against_one_balance(): void
    {
        $this->fundOwner(500_000);

        $this->withdrawals->request($this->owner, $this->account, 400_000);

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('available to withdraw');

        $this->withdrawals->request($this->owner, $this->account, 400_000);
    }

    /**
     * Unmatured earnings are not withdrawable, and the message says why --
     * "insufficient balance" would baffle an owner looking at a healthy total.
     */
    #[Test]
    public function pending_earnings_cannot_be_withdrawn(): void
    {
        DB::transaction(function () {
            $wallet = $this->wallets->lock($this->owner);
            $this->wallets->credit($wallet, 500_000, WalletTransactionType::BookingEarning);
        });

        $this->assertSame(500_000, $this->ownerWallet()->pending_minor);
        $this->assertSame(0, $this->ownerWallet()->balance_minor);

        try {
            $this->withdrawals->request($this->owner, $this->account, 100_000);
            $this->fail('Expected a WithdrawalException.');
        } catch (WithdrawalException $e) {
            $this->assertStringContainsString('still clearing', $e->getMessage());
        }
    }

    #[Test]
    public function it_enforces_the_minimum_withdrawal(): void
    {
        $this->fundOwner(500_000);

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('smallest withdrawal');

        $this->withdrawals->request($this->owner, $this->account, 50_000);
    }

    #[Test]
    public function an_owner_cannot_pay_into_someone_elses_account(): void
    {
        $this->fundOwner(500_000);

        $stranger = PayoutAccount::create([
            'owner_id' => User::factory()->create(['role' => UserRole::Owner])->id,
            'bank_name' => 'HBL',
            'account_title' => 'Someone Else',
            'account_number' => '99999999',
        ]);

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('does not belong to you');

        $this->withdrawals->request($this->owner, $stranger, 200_000);
    }

    #[Test]
    public function a_frozen_wallet_cannot_withdraw(): void
    {
        $this->fundOwner(500_000);
        Wallet::where('user_id', $this->owner->id)->update(['status' => 'frozen']);

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('frozen');

        $this->withdrawals->request($this->owner, $this->account, 200_000);
    }

    /**
     * The bank details are frozen onto the withdrawal, so editing the payout
     * account later cannot rewrite where a completed transfer actually went.
     */
    #[Test]
    public function the_bank_details_are_snapshotted(): void
    {
        $this->fundOwner(500_000);

        $withdrawal = $this->withdrawals->request($this->owner, $this->account, 200_000);

        $this->account->update(['account_number' => '00000000000000', 'bank_name' => 'Different Bank']);

        $withdrawal->refresh();

        $this->assertSame('01234567890123', $withdrawal->accountNumber());
        $this->assertSame('Meezan Bank', $withdrawal->bankName());
    }

    /*
    |--------------------------------------------------------------------------
    | Settlement
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function marking_paid_does_not_move_money_again(): void
    {
        $this->fundOwner(500_000);
        $withdrawal = $this->withdrawals->request($this->owner, $this->account, 300_000);

        $ledgerRows = $this->ownerWallet()->transactions()->count();

        $this->withdrawals->markPaid($withdrawal, $this->admin, 'FT2026080112345');

        $this->assertSame(WithdrawalStatus::Paid, $withdrawal->fresh()->status);
        $this->assertSame('FT2026080112345', $withdrawal->fresh()->external_reference);

        // The debit already happened at request time.
        $this->assertSame(200_000, $this->ownerWallet()->balance_minor);
        $this->assertSame($ledgerRows, $this->ownerWallet()->transactions()->count());
    }

    #[Test]
    public function the_first_successful_payout_verifies_the_account(): void
    {
        $this->fundOwner(500_000);
        $withdrawal = $this->withdrawals->request($this->owner, $this->account, 300_000);

        $this->assertFalse($this->account->fresh()->isVerified());

        $this->withdrawals->markPaid($withdrawal, $this->admin);

        $this->assertTrue($this->account->fresh()->isVerified());
    }

    #[Test]
    public function rejecting_returns_the_money(): void
    {
        $this->fundOwner(500_000);
        $withdrawal = $this->withdrawals->request($this->owner, $this->account, 300_000);

        $this->withdrawals->reverse($withdrawal, $this->admin, 'Bank details do not match the account title');

        $this->assertSame(WithdrawalStatus::Rejected, $withdrawal->fresh()->status);
        $this->assertSame(500_000, $this->ownerWallet()->balance_minor);

        $this->assertDatabaseHas('wallet_transactions', [
            'withdrawal_id' => $withdrawal->id,
            'type' => WalletTransactionType::WithdrawalReversal->value,
            'amount_minor' => 300_000,
        ]);
    }

    #[Test]
    public function a_bounced_transfer_returns_the_money_and_is_marked_failed(): void
    {
        $this->fundOwner(500_000);
        $withdrawal = $this->withdrawals->request($this->owner, $this->account, 300_000);

        $this->withdrawals->reverse($withdrawal, $this->admin, 'Account closed', failed: true);

        $this->assertSame(WithdrawalStatus::Failed, $withdrawal->fresh()->status);
        $this->assertSame(500_000, $this->ownerWallet()->balance_minor);
    }

    /** A double-clicked button must not pay twice or refund twice. */
    #[Test]
    public function a_settled_withdrawal_cannot_be_settled_again(): void
    {
        $this->fundOwner(500_000);
        $withdrawal = $this->withdrawals->request($this->owner, $this->account, 300_000);

        $this->withdrawals->markPaid($withdrawal, $this->admin);

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('already marked paid');

        $this->withdrawals->reverse($withdrawal->fresh(), $this->admin, 'Changed my mind');
    }

    #[Test]
    public function money_is_conserved_across_request_reject_and_repay(): void
    {
        $this->fundOwner(500_000);

        $first = $this->withdrawals->request($this->owner, $this->account, 300_000);
        $this->withdrawals->reverse($first, $this->admin, 'Wrong account');

        $second = $this->withdrawals->request($this->owner, $this->account, 500_000);
        $this->withdrawals->markPaid($second, $this->admin, 'FT999');

        $wallet = $this->ownerWallet();

        $this->assertSame(0, $wallet->balance_minor);
        $this->assertTrue($wallet->reconciles(), 'Owner wallet drifted from its ledger.');
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP surface
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_owner_can_request_a_withdrawal_over_http(): void
    {
        $this->fundOwner(500_000);

        $this->actingAs($this->owner)
            ->post(route('owner.earnings.withdraw'), [
                'payout_account_id' => $this->account->id,
                'amount' => 3000,
            ])
            ->assertRedirect(route('owner.earnings.index'))
            ->assertSessionHas('success');

        $this->assertSame(200_000, $this->ownerWallet()->balance_minor);
    }

    #[Test]
    public function the_earnings_page_separates_available_from_clearing(): void
    {
        $this->fundOwner(500_000);

        DB::transaction(function () {
            $wallet = $this->wallets->lock($this->owner);
            $this->wallets->credit($wallet, 120_000, WalletTransactionType::BookingEarning);
        });

        $this->actingAs($this->owner)
            ->get(route('owner.earnings.index'))
            ->assertOk()
            ->assertSee('Available to withdraw')
            ->assertSee('Still clearing')
            ->assertSee('Rs. 5,000')
            ->assertSee('Rs. 1,200');
    }

    #[Test]
    public function customers_cannot_reach_the_earnings_console(): void
    {
        $customer = User::factory()->create(['role' => UserRole::User]);

        $this->actingAs($customer)->get(route('owner.earnings.index'))->assertForbidden();
    }

    #[Test]
    public function an_admin_can_settle_a_withdrawal_from_the_queue(): void
    {
        $this->fundOwner(500_000);
        $withdrawal = $this->withdrawals->request($this->owner, $this->account, 300_000);

        $this->actingAs($this->admin)
            ->get(route('admin.withdrawals.index'))
            ->assertOk()
            ->assertSee($withdrawal->reference)
            // The full account number is shown: it is what gets typed into the
            // banking app.
            ->assertSee('01234567890123');

        $this->actingAs($this->admin)
            ->post(route('admin.withdrawals.paid', $withdrawal), ['external_reference' => 'FT123'])
            ->assertRedirect(route('admin.withdrawals.index'));

        $this->assertSame(WithdrawalStatus::Paid, $withdrawal->fresh()->status);
    }

    #[Test]
    public function owners_cannot_reach_the_admin_withdrawal_queue(): void
    {
        $this->actingAs($this->owner)->get(route('admin.withdrawals.index'))->assertForbidden();
    }

    #[Test]
    public function a_withdrawal_reference_is_unique_and_readable(): void
    {
        $this->fundOwner(5_000_000);

        $references = [];
        for ($i = 0; $i < 5; $i++) {
            $references[] = $this->withdrawals->request($this->owner, $this->account, 200_000)->reference;
        }

        $this->assertCount(5, array_unique($references));

        foreach ($references as $reference) {
            // No 0/O or 1/I -- these get read aloud and typed into a bank app.
            $this->assertMatchesRegularExpression('/^BYSW-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', $reference);
        }

        $this->assertSame(5, Withdrawal::count());
    }
}
