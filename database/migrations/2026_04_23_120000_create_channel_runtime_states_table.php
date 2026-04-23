<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_runtime_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('auth_status', 20)->default('unknown');
            $table->string('authorization_state', 32)->default('not_started');
            $table->string('sync_status', 32)->default('idle');
            $table->timestamp('last_gateway_heartbeat_at')->nullable();
            $table->timestamp('last_sync_started_at')->nullable();
            $table->timestamp('last_sync_completed_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->jsonb('runtime_payload')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();

            $table->index(['auth_status', 'authorization_state']);
            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_runtime_states');
    }
};
