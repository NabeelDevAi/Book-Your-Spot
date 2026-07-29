<?php

namespace Tests\Feature;

use App\Enums\HoldStatus;
use App\Enums\LedgerBucket;
use App\Enums\UserRole;
use App\Enums\WalletTransactionType;
use App\Exceptions\WalletException;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The ledger core (Phase 1 of claude-docs/wallet-payments-plan.md).
 *
 * Two things are being proved here. The obvious one is that money moves in the
 * right direction. The one that actually matters is that the cached balance
 * columns never disagree with the ledger rows that produced them -- everything
 * downstream, from a customer's visible balance to an Owner's withdrawal, is
 * only as trustworthy as that.
 */
class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallets;

    private User $customer;

    private User $owner;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallets = app(WalletService::class);

        $this->customer = User::factory()->broke()->create(['role' => UserRole::User]);
        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->reservation = Reservation::factory()->create(['user_id' => $this->customer->id]);
    }

    /** Build a spendable balance the honest way, so reconciliation holds. */
    private function fund(User $user, int $minor): Wallet
    {
        return DB::transaction(function () use ($user, $minor) {
            $wallet = $this->wallets->lock($user);
            $this->wallets->credit($wallet, $minor, WalletTransactionType::Topup);

            return $wallet;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Acquisition
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_a_wallet_on_first_touch_and_reuses_it_after(): void
    {
        $first = $this->wallets->for($this->customer);
        $second = $this->wallets->for($this->customer);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Wallet::where('user_id', $this->customer->id)->count());
        $this->assertSame(0, $first->balance_minor);
    }

    /*
    |--------------------------------------------------------------------------
    | Structural guards
    |--------------------------------------------------------------------------
    */

    /**
     * Money must commit or roll back with the reservation status it belongs to.
     * An unwrapped call would let a capture survive a rolled-back approval.
     *
     * This guard is production-only and cannot be exercised by this suite:
     * RefreshDatabase wraps every test in its own transaction, so
     * `DB::transactionLevel()` is already 1 before the test body runs and the
     * guard has nothing to catch. Skipping loudly is more honest than deleting
     * the test and pretending the guard does not exist.
     */
    #[Test]
    public function it_refuses_to_move_money_outside_a_transaction(): void
    {
        if (DB::transactionLevel() > 0) {
            $this->markTestSkipped(
                'RefreshDatabase holds an open transaction, so assertInTransaction() cannot fire in-suite.'
            );
        }

        $wallet = $this->wallets->for($this->customer);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('must be called inside a database transaction');

        $this->wallets->credit($wallet, 50_000, WalletTransactionType::Topup);
    }

    #[Test]
    public function it_refuses_non_positive_amounts(): void
    {
        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('must be positive');

        DB::transaction(function () {
            $wallet = $this->wallets->lock($this->customer);
            $this->wallets->credit($wallet, 0, WalletTransactionType::Topup);
        });
    }

    #[Test]
    public function it_refuses_to_drive_a_balance_negative(): void
    {
        $this->fund($this->customer, 50_000);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('cannot go negative');

        DB::transaction(function () {
            $wallet = $this->wallets->lock($this->customer);
            $this->wallets->debit($wallet, 60_000, WalletTransactionType::WithdrawalDebit);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Holds
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_hold_reserves_funds_without_moving_them(): void
    {
        $this->fund($this->customer, 100_000);

        DB::transaction(function () {
            $wallet = $this->wallets->lock($this->customer);
            $this->wallets->hold($wallet, $this->reservation, 60_000);
        });

        $wallet = $this->wallets->for($this->customer)->refresh();

        $this->assertSame(100_000, $wallet->balance_minor, 'Balance must not move on a hold.');
        $this->assertSame(60_000, $wallet->held_minor);
        $this->assertSame(40_000, $wallet->availableMinor());

        // Nothing moved, so nothing belongs in the ledger.
        $this->assertSame(1, $wallet->transactions()->count());
        $this->assertSame(
            WalletTransactionType::Topup,
            $wallet->transactions()->first()->type,
        );
    }

    /**
     * The distinction the whole `held_minor` column exists for: the balance
     * still reads 1,000 but 600 of it is already spoken for.
     */
    #[Test]
    public function it_refuses_a_hold_against_funds_that_are_already_held(): void
    {
        $this->fund($this->customer, 100_000);
        $second = Reservation::factory()->create(['user_id' => $this->customer->id]);

        DB::transaction(function () {
            $wallet = $this->wallets->lock($this->customer);
            $this->wallets->hold($wallet, $this->reservation, 60_000);
        });

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('Please top up');

        DB::transaction(function () use ($second) {
            $wallet = $this->wallets->lock($this->customer);
            $this->wallets->hold($wallet, $second, 60_000);
        });
    }

    #[Test]
    public function an_insufficient_funds_error_names_the_exact_shortfall(): void
    {
        $this->fund($this->customer, 30_000);

        try {
            DB::transaction(function () {
                $wallet = $this->wallets->lock($this->customer);
                $this->wallets->hold($wallet, $this->reservation, 50_000);
            });
            $this->fail('Expected a WalletException.');
        } catch (WalletException $e) {
            $this->assertSame(20_000, $e->shortfallMinor);
            $this->assertStringContainsString('Rs. 200', $e->getMessage());
        }
    }

    #[Test]
    public function releasing_a_hold_returns_the_funds_and_writes_no_ledger_row(): void
    {
        $this->fund($this->customer, 100_000);

        $hold = DB::transaction(function () {
            $wallet = $this->wallets->lock($this->customer);

            return $this->wallets->hold($wallet, $this->reservation, 60_000);
        });

        DB::transaction(fn () => $this->wallets->release($hold, WalletHold::REASON_SLOT_TAKEN));

        $wallet = $this->wallets->for($this->customer)->refresh();

        $this->assertSame(100_000, $wallet->balance_minor);
        $this->assertSame(0, $wallet->held_minor);
        $this->assertSame(100_000, $wallet->availableMinor());
        $this->assertSame(1, $wallet->transactions()->count(), 'Only the top-up should be in the ledger.');

        $hold->refresh();
        $this->assertSame(HoldStatus::Released, $hold->status);
        $this->assertSame(WalletHold::REASON_SLOT_TAKEN, $hold->released_reason);
        $this->assertNotNull($hold->resolved_at);
    }

    #[Test]
    public function a_hold_cannot_be_resolved_twice(): void
    {
        $this->fund($this->customer, 100_000);

        $hold = DB::transaction(function () {
            $wallet = $this->wallets->lock($this->customer);

            return $this->wallets->hold($wallet, $this->reservation, 60_000);
        });

        DB::transaction(fn () => $this->wallets->release($hold, WalletHold::REASON_EXPIRED));

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('already released');

        DB::transaction(fn () => $this->wallets->release($hold, WalletHold::REASON_EXPIRED));
    }

    /*
    |--------------------------------------------------------------------------
    | Capture
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function capturing_moves_the_customers_money_into_the_owners_pending_earnings(): void
    {
        $this->fund($this->customer, 100_000);
        $ownerWallet = $this->wallets->for($this->owner);

        $hold = DB::transaction(function () {
            $wallet = $this->wallets->lock($this->customer);

            return $this->wallets->hold($wallet, $this->reservation, 60_000);
        });

        DB::transaction(fn () => $this->wallets->capture($hold, $ownerWallet));

        $customerWallet = $this->wallets->for($this->customer)->refresh();
        $ownerWallet->refresh();

        $this->assertSame(40_000, $customerWallet->balance_minor);
        $this->assertSame(0, $customerWallet->held_minor);

        // Pending, NOT withdrawable. This is what makes a refund possible
        // without clawing money back out of a settled account.
        $this->assertSame(60_000, $ownerWallet->pending_minor);
        $this->assertSame(0, $ownerWallet->balance_minor);

        $this->assertSame(HoldStatus::Captured, $hold->refresh()->status);
    }

    #[Test]
    public function a_capture_writes_two_cross_referenced_ledger_rows(): void
    {
        $this->fund($this->customer, 100_000);
        $ownerWallet = $this->wallets->for($this->owner);

        $hold = DB::transaction(function () {
            $wallet = $this->wallets->lock($this->customer);

            return $this->wallets->hold($wallet, $this->reservation, 60_000);
        });

        DB::transaction(fn () => $this->wallets->capture($hold, $ownerWallet));

        $customerWallet = $this->wallets->for($this->customer);

        $payment = $customerWallet->transactions()
            ->ofType(WalletTransactionType::BookingPayment)->sole();
        $earning = $ownerWallet->transactions()
            ->ofType(WalletTransactionType::BookingEarning)->sole();

        $this->assertSame(-60_000, $payment->amount_minor);
        $this->assertSame(LedgerBucket::Balance, $payment->bucket);
        $this->assertSame(40_000, $payment->balance_after_minor);
        $this->assertSame($ownerWallet->id, $payment->counterparty_wallet_id);

        $this->assertSame(60_000, $earning->amount_minor);
        $this->assertSame(LedgerBucket::Pending, $earning->bucket);
        $this->assertSame(60_000, $earning->balance_after_minor);
        $this->assertSame($customerWallet->id, $earning->counterparty_wallet_id);

        // Either half of a movement traces to the other without guessing.
        $this->assertSame($this->reservation->id, $payment->reservation_id);
        $this->assertSame($this->reservation->id, $earning->reservation_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Freezing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_frozen_wallet_cannot_spend(): void
    {
        $this->fund($this->customer, 100_000);
        Wallet::where('user_id', $this->customer->id)->update(['status' => 'frozen']);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('temporarily frozen');

        DB::transaction(function () {
            $wallet = $this->wallets->lock($this->customer);
            $this->wallets->hold($wallet, $this->reservation, 60_000);
        });
    }

    /**
     * Freezing stops money going out. A release only returns the customer's own
     * funds to their own available pool, so it must still work -- otherwise a
     * freeze would strand every in-flight booking's money indefinitely.
     */
    #[Test]
    public function a_frozen_wallet_can_still_release_a_hold(): void
    {
        $this->fund($this->customer, 100_000);

        $hold = DB::transaction(function () {
            $wallet = $this->wallets->lock($this->customer);

            return $this->wallets->hold($wallet, $this->reservation, 60_000);
        });

        Wallet::where('user_id', $this->customer->id)->update(['status' => 'frozen']);

        DB::transaction(fn () => $this->wallets->release($hold, WalletHold::REASON_ADMIN));

        $this->assertSame(0, $this->wallets->for($this->customer)->refresh()->held_minor);
    }

    /*
    |--------------------------------------------------------------------------
    | Earnings
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function maturing_earnings_moves_pending_into_the_withdrawable_balance(): void
    {
        $ownerWallet = $this->fundOwnerPending(60_000);

        DB::transaction(function () use ($ownerWallet) {
            $locked = $this->wallets->lock($ownerWallet);
            $this->wallets->matureEarnings($locked, $this->reservation, 60_000);
        });

        $ownerWallet->refresh();

        $this->assertSame(0, $ownerWallet->pending_minor);
        $this->assertSame(60_000, $ownerWallet->balance_minor);

        // Two rows, one per bucket -- a single row could not carry a meaningful
        // balance_after for both sides of the move.
        $this->assertSame(1, $ownerWallet->transactions()->ofType(WalletTransactionType::EarningMaturedOut)->count());
        $this->assertSame(1, $ownerWallet->transactions()->ofType(WalletTransactionType::EarningMaturedIn)->count());
    }

    #[Test]
    public function reversing_an_earning_takes_it_out_of_pending_only(): void
    {
        $ownerWallet = $this->fundOwnerPending(60_000);

        DB::transaction(function () use ($ownerWallet) {
            $locked = $this->wallets->lock($ownerWallet);
            $this->wallets->reverseEarning($locked, $this->reservation, 30_000, 'user_cancelled');
        });

        $ownerWallet->refresh();

        $this->assertSame(30_000, $ownerWallet->pending_minor);
        $this->assertSame(0, $ownerWallet->balance_minor, 'A reversal must never touch settled funds.');
    }

    /** Put money in the Owner's pending pot the honest way, via a real capture. */
    private function fundOwnerPending(int $minor): Wallet
    {
        $this->fund($this->customer, $minor);
        $ownerWallet = $this->wallets->for($this->owner);

        $hold = DB::transaction(function () use ($minor) {
            $wallet = $this->wallets->lock($this->customer);

            return $this->wallets->hold($wallet, $this->reservation, $minor);
        });

        DB::transaction(fn () => $this->wallets->capture($hold, $ownerWallet));

        return $ownerWallet->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Reconciliation -- the capstone
    |--------------------------------------------------------------------------
    */

    /**
     * The cached balance columns must always equal the ledger that produced
     * them, and held_minor must always equal the sum of active holds.
     *
     * If this passes after a full lifecycle the ledger is sound. If it fails,
     * nothing else in the wallet is trustworthy.
     */
    #[Test]
    public function balances_reconcile_against_the_ledger_after_a_full_lifecycle(): void
    {
        $second = Reservation::factory()->create(['user_id' => $this->customer->id]);
        $third = Reservation::factory()->create(['user_id' => $this->customer->id]);

        $this->fund($this->customer, 200_000);
        $ownerWallet = $this->wallets->for($this->owner);

        // One booking captured and matured.
        $captured = DB::transaction(function () {
            $wallet = $this->wallets->lock($this->customer);

            return $this->wallets->hold($wallet, $this->reservation, 60_000);
        });
        DB::transaction(fn () => $this->wallets->capture($captured, $ownerWallet));
        DB::transaction(function () use ($ownerWallet) {
            $locked = $this->wallets->lock($ownerWallet);
            $this->wallets->matureEarnings($locked, $this->reservation, 60_000);
        });

        // One booking captured, then cancelled with a partial refund.
        $refunded = DB::transaction(function () use ($second) {
            $wallet = $this->wallets->lock($this->customer);

            return $this->wallets->hold($wallet, $second, 40_000);
        });
        DB::transaction(fn () => $this->wallets->capture($refunded, $ownerWallet));
        DB::transaction(function () use ($second, $ownerWallet) {
            [$customerWallet, $locked] = $this->wallets->lockPair($this->customer, $ownerWallet);
            $this->wallets->reverseEarning($locked, $second, 20_000, 'late_cancellation');
            $this->wallets->refund($customerWallet, $second, 20_000, 'late_cancellation');
        });

        // One booking still holding funds.
        DB::transaction(function () use ($third) {
            $wallet = $this->wallets->lock($this->customer);
            $this->wallets->hold($wallet, $third, 30_000);
        });

        $customerWallet = $this->wallets->for($this->customer)->refresh();
        $ownerWallet->refresh();

        // 200,000 in, 60,000 and 40,000 out, 20,000 refunded back.
        $this->assertSame(120_000, $customerWallet->balance_minor);
        $this->assertSame(30_000, $customerWallet->held_minor);
        $this->assertSame(90_000, $customerWallet->availableMinor());

        // 60,000 matured, 40,000 earned less 20,000 reversed.
        $this->assertSame(60_000, $ownerWallet->balance_minor);
        $this->assertSame(20_000, $ownerWallet->pending_minor);

        $this->assertTrue($customerWallet->reconciles(), 'Customer wallet drifted from its ledger.');
        $this->assertTrue($ownerWallet->reconciles(), 'Owner wallet drifted from its ledger.');

        // Conservation: nothing was created or destroyed anywhere in the system.
        $this->assertSame(
            200_000,
            $customerWallet->balance_minor + $ownerWallet->balance_minor + $ownerWallet->pending_minor,
        );
    }
}
