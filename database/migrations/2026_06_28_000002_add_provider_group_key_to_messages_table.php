<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('provider_group_key')->nullable()->after('provider_event_key');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS messages_dialog_channel_provider_group_key_index
            ON messages (dialog_id, channel_id, direction, provider_group_key, received_at, id)
            WHERE provider_group_key IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_dialog_channel_provider_group_key_index');

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('provider_group_key');
        });
    }
};
