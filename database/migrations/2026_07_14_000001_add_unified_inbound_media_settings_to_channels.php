<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->unsignedBigInteger('inbound_media_auto_download_max_bytes')
                ->nullable()
                ->after('telegram_account_media_on_demand_enabled');
            $table->boolean('inbound_media_on_demand_enabled')
                ->nullable()
                ->after('inbound_media_auto_download_max_bytes');
        });

        DB::table('channels')
            ->where('connection_type', 'account')
            ->update([
                'inbound_media_auto_download_max_bytes' => DB::raw('telegram_account_media_auto_download_max_bytes'),
                'inbound_media_on_demand_enabled' => DB::raw('telegram_account_media_on_demand_enabled'),
            ]);
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn([
                'inbound_media_auto_download_max_bytes',
                'inbound_media_on_demand_enabled',
            ]);
        });
    }
};
