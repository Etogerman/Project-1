<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@abrikosoff.local'],
            [
                'name' => 'Admin',
                'is_active' => true,
                'is_admin' => true,
                'role' => User::ROLE_ADMIN,
                'password' => 'admin12345',
            ],
        );
    }
}
