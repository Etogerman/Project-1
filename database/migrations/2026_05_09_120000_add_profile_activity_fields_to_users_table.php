<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('last_name')->nullable()->after('name');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->timestamp('last_seen_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'last_name',
                'last_login_at',
                'last_seen_at',
            ]);
        });
    }
};
