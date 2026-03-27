<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->string('bot_external_id')->nullable()->after('credentials');
            $table->string('bot_username')->nullable()->after('bot_external_id');
            $table->string('bot_name')->nullable()->after('bot_username');
            $table->string('bot_profile_url')->nullable()->after('bot_name');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn([
                'bot_external_id',
                'bot_username',
                'bot_name',
                'bot_profile_url',
            ]);
        });
    }
};
