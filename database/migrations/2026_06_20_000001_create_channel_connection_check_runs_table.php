<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_connection_check_runs', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->string('status', 32)->index();
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->string('last_error_message', 1000)->nullable();
            $table->string('app_rev', 80)->nullable()->index();
            $table->string('environment', 80)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_connection_check_runs');
    }
};
