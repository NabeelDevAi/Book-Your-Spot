<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessGame>
 */
class BusinessGameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'game_id' => Game::factory(),
            'sort_order' => 0,
        ];
    }
}
