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
            $table->boolean('sync_external_outgoing_enabled')
                ->default(false)
                ->after('is_hidden');
        });

        DB::statement(<<<'SQL'
            create index if not exists messages_external_outgoing_dedupe_idx
                on messages (channel_id, external_chat_id, external_message_id, message_kind)
                where direction = 'outbound' and external_message_id is not null
        SQL);

        DB::statement(<<<'SQL'
            create index if not exists telegram_account_outgoing_messages_sent_external_idx
                on telegram_account_outgoing_messages (channel_id, external_chat_id, sent_external_message_id)
                where sent_external_message_id is not null
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop index if exists telegram_account_outgoing_messages_sent_external_idx');
        DB::statement('drop index if exists messages_external_outgoing_dedupe_idx');

        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('sync_external_outgoing_enabled');
        });
    }
};
