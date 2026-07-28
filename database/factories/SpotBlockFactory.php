<?php

namespace Database\Factories;

use App\Models\Spot;
use App\Models\SpotBlock;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<SpotBlock>
 */
class SpotBlockFactory extends Factory
{
    public function definition(): array
    {
        $start = Carbon::tomorrow()->setTime(12, 0);

        return [
            'spot_id' => Spot::factory(),
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addHours(2),
            'reason' => 'Maintenance',
            'created_by' => User::factory()->owner(),
            'created_by_role' => 'owner',
        ];
    }

    public function at(Carbon $start, int $durationMinutes = 120): static
    {
        return $this->state(fn () => [
            'start_datetime' => $start->copy(),
            'end_datetime' => $start->copy()->addMinutes($durationMinutes),
        ]);
    }

    public function forSpot(Spot $spot): static
    {
        return $this->state(fn () => ['spot_id' => $spot->id]);
    }
}
