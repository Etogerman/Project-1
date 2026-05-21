<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_processors')) {
            Schema::create('ai_processors', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('provider', 64)->default('gemini');
                $table->string('model')->nullable();
                $table->string('base_url')->nullable();
                $table->text('credentials')->nullable();
                $table->boolean('api_key_present')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('priority')->default(100);
                $table->unsignedInteger('timeout_seconds')->default(30);
                $table->decimal('temperature', 4, 2)->default(0.20);
                $table->unsignedInteger('max_output_tokens')->default(512);
                $table->integer('thinking_budget')->default(0);
                $table->timestampTz('last_failed_at')->nullable();
                $table->text('last_error_message')->nullable();
                $table->timestamps();

                $table->index(['is_active', 'priority', 'id']);
                $table->index('provider');
            });
        }

        if (Schema::hasTable('ai_processors') && ! DB::table('ai_processors')->where('provider', 'gemini')->exists()) {
            DB::table('ai_processors')->insert([
                'name' => 'Gemini основной',
                'provider' => 'gemini',
                'model' => (string) config('bots.gemini.model', 'gemini-2.5-flash'),
                'base_url' => (string) config('bots.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'),
                'credentials' => null,
                'api_key_present' => false,
                'is_active' => true,
                'priority' => 10,
                'timeout_seconds' => 30,
                'temperature' => (float) config('bots.gemini.temperature', 0.2),
                'max_output_tokens' => (int) config('bots.gemini.max_output_tokens', 512),
                'thinking_budget' => (int) config('bots.gemini.thinking_budget', 0),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_processors');
    }
};
