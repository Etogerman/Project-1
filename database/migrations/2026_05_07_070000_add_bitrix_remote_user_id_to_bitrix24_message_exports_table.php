<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bitrix24_message_exports', 'bitrix_remote_user_id')) {
            Schema::table('bitrix24_message_exports', function (Blueprint $table): void {
                $table->string('bitrix_remote_user_id')
                    ->nullable()
                    ->after('bitrix_remote_message_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bitrix24_message_exports', 'bitrix_remote_user_id')) {
            Schema::table('bitrix24_message_exports', function (Blueprint $table): void {
                $table->dropColumn('bitrix_remote_user_id');
            });
        }
    }
};
