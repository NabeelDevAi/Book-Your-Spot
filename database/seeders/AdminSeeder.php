<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Admin accounts are never self-registerable (SRS FR-1.3) -- they exist only
 * through this seeder or direct DB access.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@bookyourspot.pk'],
            [
                'name' => 'Platform Admin',
                'phone' => '+923001112233',
                'password' => Hash::make('password'),
                'role' => UserRole::Admin,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );
    }
}
