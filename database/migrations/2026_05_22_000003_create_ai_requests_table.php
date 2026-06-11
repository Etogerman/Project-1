<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('correlation_id')->index();
            $table->foreignId('ai_task_id')->nullable()->constrained('ai_tasks')->nullOnDelete();
            $table->string('task_key', 64)->index();
            $table->string('status', 20)->index();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dialog_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scenario_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scenario_block_id', 100)->nullable();
            $table->foreignId('final_attempt_id')->nullable();
            $table->string('provider', 64)->nullable()->index();
            $table->string('model')->nullable()->index();
            $table->string('prompt_key')->nullable()->index();
            $table->string('prompt_hash', 64)->nullable()->index();
            $table->text('request_body_raw')->nullable();
            $table->text('response_body_raw')->nullable();
            $table->boolean('raw_body_truncated')->default(false);
            $table->text('system_prompt_preview')->nullable();
            $table->text('user_prompt_preview')->nullable();
            $table->text('response_preview')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('thinking_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost', 18, 8)->nullable();
            $table->decimal('provider_reported_cost', 18, 8)->nullable();
            $table->string('provider_reported_currency', 3)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('cost_status', 30)->nullable()->index();
            $table->timestampTz('started_at')->nullable()->index();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->index(['task_key', 'status', 'created_at']);
            $table->index(['provider', 'model', 'created_at']);
            $table->index(['channel_id', 'created_at']);
            $table->index(['scenario_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
