<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->string('bitrix24_live_chat_id')->nullable()->after('external_chat_id')->index();
            $table->string('bitrix24_live_status')->default('not_linked')->after('bitrix24_live_chat_id')->index();
            $table->timestamp('bitrix24_live_last_exported_at')->nullable()->after('bitrix24_live_status');
            $table->timestamp('bitrix24_live_last_imported_at')->nullable()->after('bitrix24_live_last_exported_at');
        });
    }

    public function down(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->dropIndex(['bitrix24_live_chat_id']);
            $table->dropIndex(['bitrix24_live_status']);
            $table->dropColumn([
                'bitrix24_live_chat_id',
                'bitrix24_live_status',
                'bitrix24_live_last_exported_at',
                'bitrix24_live_last_imported_at',
            ]);
        });
    }
};
