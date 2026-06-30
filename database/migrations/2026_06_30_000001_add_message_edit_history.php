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
            $table->timestamp('edited_at')->nullable()->after('received_at');
            $table->unsignedInteger('edit_count')->default(0)->after('edited_at');
            $table->string('last_edit_provider_event_key')->nullable()->after('provider_event_key');
        });

        Schema::create('message_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('revision_type', 32)->default('edit');
            $table->string('provider_event_key')->nullable();
            $table->timestamp('provider_edited_at')->nullable();
            $table->text('previous_text')->nullable();
            $table->jsonb('previous_rich_text')->nullable();
            $table->jsonb('previous_raw_payload')->nullable();
            $table->text('new_text')->nullable();
            $table->jsonb('new_rich_text')->nullable();
            $table->jsonb('new_raw_payload')->nullable();
            $table->timestamps();

            $table->index(['message_id', 'created_at']);
            $table->index('provider_edited_at');
        });

        DB::statement('
            CREATE UNIQUE INDEX message_revisions_provider_event_key_unique
            ON message_revisions (message_id, provider_event_key)
            WHERE provider_event_key IS NOT NULL
        ');

        DB::statement('
            CREATE INDEX messages_inbound_external_message_lookup_idx
            ON messages (channel_id, external_chat_id, external_message_id)
            WHERE direction = \'inbound\' AND external_message_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_inbound_external_message_lookup_idx');
        DB::statement('DROP INDEX IF EXISTS message_revisions_provider_event_key_unique');

        Schema::dropIfExists('message_revisions');

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn([
                'edited_at',
                'edit_count',
                'last_edit_provider_event_key',
            ]);
        });
    }
};
