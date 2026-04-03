<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLE_ADMIN = 'admin';

    private const ROLE_EMPLOYEE = 'employee';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)
                ->default(self::ROLE_EMPLOYEE)
                ->after('is_admin');
        });

        DB::table('users')
            ->where('is_admin', true)
            ->update(['role' => self::ROLE_ADMIN]);

        DB::table('users')
            ->where('is_admin', false)
            ->update(['role' => self::ROLE_EMPLOYEE]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
