<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_v3_scheduled_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scenario_run_id')
                ->constrained('scenario_runs')
                ->cascadeOnDelete();
            $table->foreignId('dialog_id')
                ->constrained('dialogs')
                ->cascadeOnDelete();
            $table->foreignId('inbound_message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();
            $table->string('scenario_code')->index();
            $table->foreignId('published_version_id')
                ->constrained('scenario_versions')
                ->restrictOnDelete();
            $table->string('edge_key', 100);
            $table->string('edge_id', 64)->nullable();
            $table->string('source_block_id', 100);
            $table->string('target_block_id', 100);
            $table->jsonb('delay_payload')->default(DB::raw("'{}'::jsonb"));
            $table->timestamp('scheduled_for')->index();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 30)->index();
            $table->string('error_message', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_for'], 'scenario_v3_scheduled_transitions_status_scheduled_index');
            $table->index(['scenario_run_id', 'status'], 'scenario_v3_scheduled_transitions_run_status_index');
            $table->index(['dialog_id', 'status'], 'scenario_v3_scheduled_transitions_dialog_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_v3_scheduled_transitions');
    }
};
