<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_first_name_resolution_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 40)->index();
            $table->uuid('correlation_id')->index();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dialog_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scenario_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scenario_block_id', 100)->nullable();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resolution_attempt_event_id')
                ->nullable()
                ->constrained('contact_first_name_resolution_events')
                ->nullOnDelete();
            $table->string('source', 40)->index();
            $table->string('result', 40)->index();
            $table->text('client_text_preview')->nullable();
            $table->foreignId('matched_dictionary_entry_id')->nullable()->constrained('data_dictionary_entries')->nullOnDelete();
            $table->string('found_first_name')->nullable();
            $table->string('resolved_first_name')->nullable();
            $table->string('old_first_name')->nullable();
            $table->string('new_first_name')->nullable();
            $table->string('written_first_name')->nullable();
            $table->string('first_name_source', 40)->nullable();
            $table->string('first_name_resolution_method', 60)->nullable();
            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();

            $table->index(['event_type', 'source', 'result', 'created_at'], 'first_name_resolution_events_main_index');
            $table->index(['channel_id', 'created_at']);
            $table->index(['scenario_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_first_name_resolution_events');
    }
};
