<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_v3_outbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scenario_run_id')
                ->constrained('scenario_runs')
                ->cascadeOnDelete();
            $table->foreignId('dialog_id')
                ->constrained('dialogs')
                ->cascadeOnDelete();
            $table->foreignId('channel_id')
                ->constrained('channels')
                ->cascadeOnDelete();
            $table->foreignId('inbound_message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();
            $table->foreignId('outbound_message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();
            $table->foreignId('published_version_id')
                ->constrained('scenario_versions')
                ->restrictOnDelete();
            $table->foreignId('scheduled_transition_id')
                ->nullable()
                ->constrained('scenario_v3_scheduled_transitions')
                ->nullOnDelete();
            $table->string('scenario_code')->index();
            $table->string('block_id', 100)->nullable();
            $table->text('text');
            $table->string('text_format', 30);
            $table->jsonb('delivery_payload')->default(DB::raw("'{}'::jsonb"));
            $table->string('status', 30)->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at'], 'scenario_v3_outbound_messages_status_available_index');
            $table->index(['scenario_run_id', 'status'], 'scenario_v3_outbound_messages_run_status_index');
            $table->index(['scheduled_transition_id', 'status'], 'scenario_v3_outbound_messages_transition_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_v3_outbound_messages');
    }
};
