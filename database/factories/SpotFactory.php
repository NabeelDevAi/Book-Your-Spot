<?php

namespace Database\Factories;

use App\Enums\SpotStatus;
use App\Models\BusinessGame;
use App\Models\Spot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Spot>
 */
class SpotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_game_id' => BusinessGame::factory(),
            // Kept consistent with the parent business_game rather than
            // creating a second, unrelated Business.
            'business_id' => fn (array $attributes) => BusinessGame::find($attributes['business_game_id'])?->business_id
                ?? BusinessGame::factory()->create()->business_id,
            'name' => 'Table '.fake()->numberBetween(1, 12),
            'description' => null,
            'price_amount' => 100,
            'weekend_price_amount' => null,
            'price_unit_minutes' => 10,
            'min_duration_minutes' => 30,
            'status' => SpotStatus::Active,
            'operating_hours_override' => null,
            'sort_order' => 0,
        ];
    }

    /** Rs 100 per 10 minutes -- the fine-grained billing the SRS calls out. */
    public function snooker(int $number = 1): static
    {
        return $this->state(fn () => [
            'name' => "Snooker Table {$number}",
            'price_amount' => 100,
            'price_unit_minutes' => 10,
            'min_duration_minutes' => 30,
        ]);
    }

    /** Rs 2,500 per hour, booked in whole hours. */
    public function futsal(int $number = 1): static
    {
        return $this->state(fn () => [
            'name' => "Futsal Court {$number}",
            'price_amount' => 2500,
            'price_unit_minutes' => 60,
            'min_duration_minutes' => 60,
        ]);
    }

    public function ps5(string $room = 'A'): static
    {
        return $this->state(fn () => [
            'name' => "PS5 Room {$room}",
            'price_amount' => 400,
            'price_unit_minutes' => 30,
            'min_duration_minutes' => 60,
        ]);
    }

    public function padel(int $number = 1): static
    {
        return $this->state(fn () => [
            'name' => "Padel Court {$number}",
            'price_amount' => 4000,
            'price_unit_minutes' => 60,
            'min_duration_minutes' => 60,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => SpotStatus::Inactive]);
    }

    public function pricedAt(float $amount, int $unitMinutes): static
    {
        return $this->state(fn () => [
            'price_amount' => $amount,
            'price_unit_minutes' => $unitMinutes,
        ]);
    }

    /** A distinct Sat/Sun/holiday rate, separate from the weekday price_amount. */
    public function weekendPricedAt(float $amount): static
    {
        return $this->state(fn () => ['weekend_price_amount' => $amount]);
    }

    /**
     * There is no maximum any more (SRS amendment) -- $max is accepted and
     * ignored so existing call sites keep compiling; only the minimum lands
     * on the model. New tests should call minDuration() instead.
     */
    public function duration(int $min, ?int $max = null): static
    {
        return $this->state(fn () => ['min_duration_minutes' => $min]);
    }

    public function minDuration(int $min): static
    {
        return $this->state(fn () => ['min_duration_minutes' => $min]);
    }
}
