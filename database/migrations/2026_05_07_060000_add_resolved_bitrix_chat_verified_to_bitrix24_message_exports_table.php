<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bitrix24_message_exports')) {
            return;
        }

        if (! Schema::hasColumn('bitrix24_message_exports', 'resolved_bitrix_chat_verified')) {
            Schema::table('bitrix24_message_exports', function (Blueprint $table): void {
                $table->boolean('resolved_bitrix_chat_verified')
                    ->default(false)
                    ->after('resolved_bitrix_chat_id');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('bitrix24_message_exports')
            && Schema::hasColumn('bitrix24_message_exports', 'resolved_bitrix_chat_verified')
        ) {
            Schema::table('bitrix24_message_exports', function (Blueprint $table): void {
                $table->dropColumn('resolved_bitrix_chat_verified');
            });
        }
    }
};
