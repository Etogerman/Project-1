<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->boolean('telegram_account_media_on_demand_enabled')
                ->default(false)
                ->after('telegram_account_media_auto_download_max_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('telegram_account_media_on_demand_enabled');
        });
    }
};
