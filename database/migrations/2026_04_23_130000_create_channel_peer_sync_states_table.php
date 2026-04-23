<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_peer_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('peer_key');
            $table->string('external_chat_id');
            $table->string('backfill_status', 20)->default('not_started');
            $table->string('oldest_imported_message_id')->nullable();
            $table->string('latest_observed_message_id')->nullable();
            $table->timestamp('history_complete_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'peer_key']);
            $table->unique(['channel_id', 'external_chat_id']);
            $table->index('backfill_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_peer_sync_states');
    }
};
