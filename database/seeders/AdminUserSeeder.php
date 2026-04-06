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
        $user = User::query()->firstOrNew([
            'email' => 'admin@abrikosoff.local',
        ]);

        $user->fill([
            'name' => 'Admin',
            'is_active' => true,
            'password' => 'admin12345',
        ]);

        $user->forceFill([
            'is_admin' => true,
            'role' => User::ROLE_SUPERADMIN,
        ]);

        $user->save();
    }
}
