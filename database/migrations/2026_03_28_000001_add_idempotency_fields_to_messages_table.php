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
            $table->string('provider_event_key')->nullable();
            $table->timestamp('auto_reply_sent_at')->nullable();
        });

        DB::statement('
            CREATE UNIQUE INDEX messages_channel_direction_provider_event_key_unique
            ON messages (channel_id, direction, provider_event_key)
            WHERE provider_event_key IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_channel_direction_provider_event_key_unique');

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn([
                'provider_event_key',
                'auto_reply_sent_at',
            ]);
        });
    }
};
