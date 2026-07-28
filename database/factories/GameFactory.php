<?php

namespace Database\Factories;

use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /** @see UserFactory::$sequence -- same reason: unique() by retry does not scale. */
    protected static int $sequence = 0;

    public function definition(): array
    {
        $pool = [
            'Snooker', 'Futsal', 'Padel', 'PS5', 'Table Tennis', 'Cricket Nets',
            'Badminton', 'Pool', 'Bowling', 'Squash',
        ];

        $n = ++static::$sequence;
        $name = $pool[($n - 1) % count($pool)];

        // The slug carries the counter so a test can create more games than the
        // pool has entries without colliding on the unique index.
        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.$n,
            'icon' => null,
            'description' => fake()->sentence(),
            'status' => GameStatus::Active,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => GameStatus::Inactive]);
    }
}
