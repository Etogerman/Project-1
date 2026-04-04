<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('email', 'admin@abrikosoff.local')
            ->update([
                'role' => User::ROLE_SUPERADMIN,
                'is_admin' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', 'admin@abrikosoff.local')
            ->where('role', User::ROLE_SUPERADMIN)
            ->update([
                'role' => User::ROLE_ADMIN,
                'is_admin' => true,
                'updated_at' => now(),
            ]);
    }
};
