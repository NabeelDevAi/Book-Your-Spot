<?php

namespace Database\Seeders;

use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Database\Seeder;

/**
 * The Admin-managed master category list (SRS FR-2.3).
 *
 * This is the canonical taxonomy Owners choose from. Keeping it seeded rather
 * than owner-editable is precisely what prevents "PS5" / "Playstation 5" /
 * "PS 5" fragmenting the category filter.
 */
class GameSeeder extends Seeder
{
    public function run(): void
    {
        $games = [
            ['name' => 'Snooker', 'icon' => 'grid', 'description' => 'Snooker and billiards tables.'],
            ['name' => 'Pool', 'icon' => 'grid', 'description' => 'American pool tables.'],
            ['name' => 'Futsal', 'icon' => 'layers', 'description' => 'Indoor and rooftop futsal courts.'],
            ['name' => 'Padel', 'icon' => 'layers', 'description' => 'Padel tennis courts.'],
            ['name' => 'Cricket Nets', 'icon' => 'layers', 'description' => 'Practice nets and bowling machines.'],
            ['name' => 'PS5', 'icon' => 'sparkle', 'description' => 'PlayStation 5 gaming rooms and booths.'],
            ['name' => 'Xbox', 'icon' => 'sparkle', 'description' => 'Xbox gaming rooms and booths.'],
            ['name' => 'Table Tennis', 'icon' => 'grid', 'description' => 'Table tennis tables.'],
            ['name' => 'Badminton', 'icon' => 'layers', 'description' => 'Indoor badminton courts.'],
            ['name' => 'Squash', 'icon' => 'layers', 'description' => 'Squash courts.'],
            ['name' => 'Bowling', 'icon' => 'grid', 'description' => 'Ten-pin bowling lanes.'],
            ['name' => 'VR Gaming', 'icon' => 'sparkle', 'description' => 'Virtual reality booths and arenas.'],
        ];

        foreach ($games as $index => $game) {
            Game::updateOrCreate(
                ['slug' => str($game['name'])->slug()->value()],
                [
                    'name' => $game['name'],
                    'icon' => $game['icon'],
                    'description' => $game['description'],
                    'status' => GameStatus::Active,
                    'sort_order' => $index,
                ],
            );
        }
    }
}
