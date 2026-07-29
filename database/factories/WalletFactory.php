<?php

namespace Database\Factories;

use App\Enums\WalletStatus;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'balance_minor' => 0,
            'held_minor' => 0,
            'pending_minor' => 0,
            'status' => WalletStatus::Active,
        ];
    }

    /**
     * Seed a settled balance directly.
     *
     * Bypasses the ledger deliberately: a test that needs a customer with money
     * should not have to run a top-up to get one. Anything
     * asserting reconciliation must build its balance through WalletService
     * instead, or the ledger will not match.
     */
    public function withBalance(int $minor): static
    {
        return $this->state(fn () => ['balance_minor' => $minor]);
    }

    public function withPending(int $minor): static
    {
        return $this->state(fn () => ['pending_minor' => $minor]);
    }

    public function frozen(string $reason = 'Chargeback under review'): static
    {
        return $this->state(fn () => [
            'status' => WalletStatus::Frozen,
            'frozen_reason' => $reason,
            'frozen_at' => now(),
        ]);
    }
}
