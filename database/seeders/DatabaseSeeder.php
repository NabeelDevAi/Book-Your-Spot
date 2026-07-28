<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Note: deliberately NOT using WithoutModelEvents. Business slugs and
 * reservation references are generated in `creating` hooks, so suppressing
 * model events would produce rows that violate their own NOT NULL/unique
 * constraints. The events are business logic here, not incidental observers.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * GameSeeder and AdminSeeder are production-safe: the master category list
     * and an admin account are needed on any install. DemoDataSeeder is sample
     * content and is skipped outside local/testing.
     */
    public function run(): void
    {
        $this->call([
            GameSeeder::class,
            AdminSeeder::class,
        ]);

        if (app()->environment('local', 'testing')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
