<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * Faker's unique() modifier works by retrying until it stumbles on an
     * unused value, and safeEmail() draws from a small enough pool that a test
     * creating a few dozen users exhausts its 10,000 retries. A monotonic
     * counter is unique by construction, so factories stay reliable at any volume.
     */
    protected static int $sequence = 0;

    public function definition(): array
    {
        $n = ++static::$sequence;

        return [
            'name' => fake()->name(),
            'email' => 'user'.$n.'@example.test',
            'phone' => '+9230'.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::User,
            'status' => UserStatus::Active,
            'no_show_count' => 0,
            'must_change_password' => false,
        ];
    }

    /**
     * Customers are created with money in their wallet.
     *
     * From Phase 3 onward a booking is paid from wallet balance at request
     * time, so a customer with an empty wallet cannot book at all. Making a
     * funded wallet the default reflects that: a test about operating hours or
     * duration rules should not have to plumb a top-up first.
     *
     * The credit is written through the ledger rather than straight onto the
     * balance column, so the reconciliation invariant holds for factory-made
     * users exactly as it does for real ones.
     *
     * Tests that care about the money itself opt out with `broke()` or set
     * their own figure with `withWalletBalance()`.
     */
    /** The default balance every factory-made customer starts with: Rs 50,000. */
    public const DEFAULT_WALLET_MINOR = 5_000_000;

    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if (! $user->role->canBook()) {
                return;
            }

            $this->creditWallet($user, self::DEFAULT_WALLET_MINOR);
        });
    }

    /**
     * A customer with no wallet at all.
     *
     * Implemented by undoing the default rather than by suppressing it, because
     * Laravel binds an `afterCreating` closure to the factory instance that
     * registered it and copies the callback array by reference into every
     * derived instance. A flag on `$this` read inside the default closure would
     * therefore read the ORIGINAL factory's value and silently never apply --
     * and a test helper that quietly does nothing is worse than no helper.
     *
     * Deleting the ledger rows first is required: wallet_transactions restricts
     * deletion of its wallet. Safe here because a factory wallet has exactly
     * one row and no holds.
     */
    public function broke(): static
    {
        return $this->afterCreating(function (User $user) {
            $wallet = Wallet::where('user_id', $user->id)->first();

            if ($wallet === null) {
                return;
            }

            WalletTransaction::where('wallet_id', $wallet->id)->delete();
            $wallet->delete();
        });
    }

    /** A customer with an exact balance, credited through the ledger. */
    public function withWalletBalance(int $minor): static
    {
        return $this->afterCreating(function (User $user) use ($minor) {
            $wallet = Wallet::where('user_id', $user->id)->first();

            if ($wallet === null) {
                $this->creditWallet($user, $minor);

                return;
            }

            $delta = $minor - $wallet->balance_minor;

            if ($delta === 0) {
                return;
            }

            DB::transaction(function () use ($user, $delta) {
                $wallets = app(WalletService::class);
                $locked = $wallets->lock($user);

                $delta > 0
                    ? $wallets->credit($locked, $delta, WalletTransactionType::Topup)
                    : $wallets->debit($locked, -$delta, WalletTransactionType::AdminAdjustment);
            });
        });
    }

    /**
     * Credit through WalletService rather than writing the balance column, so
     * the reconciliation invariant holds for factory users exactly as it does
     * for real ones.
     */
    private function creditWallet(User $user, int $minor): void
    {
        if ($minor <= 0) {
            return;
        }

        DB::transaction(function () use ($user, $minor) {
            $wallets = app(WalletService::class);

            $wallets->credit(
                $wallets->lock($user),
                $minor,
                WalletTransactionType::Topup,
                ['meta' => ['source' => 'factory']],
            );
        });
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role' => UserRole::Owner]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function suspended(?string $reason = null): static
    {
        return $this->state(fn () => [
            'status' => UserStatus::Suspended,
            'suspension_reason' => $reason ?? 'Repeated no-shows',
            'suspended_at' => now(),
        ]);
    }

    /** A customer with a no-show history, for exercising the SRS 9.12 warning. */
    public function repeatNoShow(int $count = 3): static
    {
        return $this->state(fn () => ['no_show_count' => $count]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
