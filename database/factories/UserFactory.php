<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
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
