<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitrix24_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->nullable()->constrained('bitrix24_connections')->nullOnDelete();
            $table->string('direction', 16)->index();
            $table->string('operation', 100)->index();
            $table->string('entity_type', 100)->nullable()->index();
            $table->string('entity_id')->nullable();
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->string('status', 32)->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->string('fingerprint', 191)->nullable()->index();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitrix24_sync_logs');
    }
};
