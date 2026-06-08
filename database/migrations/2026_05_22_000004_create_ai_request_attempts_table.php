<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_request_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_processor_id')->nullable()->constrained('ai_processors')->nullOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('provider', 64)->index();
            $table->string('model')->nullable()->index();
            $table->string('status', 20)->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('request_body_raw')->nullable();
            $table->text('response_body_raw')->nullable();
            $table->boolean('raw_body_truncated')->default(false);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('thinking_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost', 18, 8)->nullable();
            $table->decimal('provider_reported_cost', 18, 8)->nullable();
            $table->string('provider_reported_currency', 3)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('cost_status', 30)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->text('response_preview')->nullable();
            $table->timestampTz('started_at')->nullable()->index();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->unique(['ai_request_id', 'attempt_number']);
            $table->index(['provider', 'model', 'created_at']);
        });

        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->foreign('final_attempt_id')
                ->references('id')
                ->on('ai_request_attempts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->dropForeign(['final_attempt_id']);
        });

        Schema::dropIfExists('ai_request_attempts');
    }
};
