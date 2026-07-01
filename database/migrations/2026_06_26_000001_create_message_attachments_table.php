<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            ALTER TABLE messages
            ADD CONSTRAINT messages_id_channel_id_unique UNIQUE (id, channel_id)
        ');

        Schema::create('message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id');
            $table->foreignId('channel_id');
            $table->string('provider', 64)->nullable();
            $table->string('provider_event_key')->nullable();
            $table->string('provider_attachment_key')->nullable();
            $table->string('outbound_attachment_key')->nullable();
            $table->string('media_kind', 32)->default('unknown');
            $table->string('mime_type')->nullable();
            $table->string('extension', 32)->nullable();
            $table->string('original_filename')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('provider_file_id')->nullable();
            $table->string('provider_file_unique_id')->nullable();
            $table->text('provider_file_reference')->nullable();
            $table->jsonb('provider_metadata')->nullable();
            $table->string('download_status', 32)->default('metadata_only');
            $table->string('send_status', 32)->default('not_applicable');
            $table->string('local_disk')->nullable();
            $table->string('local_path')->nullable();
            $table->string('safe_error_code')->nullable();
            $table->text('safe_error_message')->nullable();
            $table->jsonb('raw_payload_excerpt')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['message_id', 'sort_order']);
            $table->index(['channel_id', 'provider_event_key']);
            $table->index('media_kind');
            $table->index('download_status');
            $table->index('send_status');
        });

        DB::statement('
            ALTER TABLE message_attachments
            ADD CONSTRAINT message_attachments_message_channel_foreign
            FOREIGN KEY (message_id, channel_id)
            REFERENCES messages (id, channel_id)
            ON DELETE CASCADE
        ');

        DB::statement('
            CREATE UNIQUE INDEX message_attachments_inbound_unique
            ON message_attachments (provider, channel_id, provider_event_key, provider_attachment_key)
            WHERE provider IS NOT NULL
                AND provider_event_key IS NOT NULL
                AND provider_attachment_key IS NOT NULL
        ');

        DB::statement('
            CREATE UNIQUE INDEX message_attachments_outbound_key_unique
            ON message_attachments (outbound_attachment_key)
            WHERE outbound_attachment_key IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS message_attachments_outbound_key_unique');
        DB::statement('DROP INDEX IF EXISTS message_attachments_inbound_unique');

        Schema::dropIfExists('message_attachments');

        DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_id_channel_id_unique');
    }
};
