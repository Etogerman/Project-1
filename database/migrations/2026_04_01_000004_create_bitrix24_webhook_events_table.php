<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitrix24_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->nullable()->constrained('bitrix24_connections')->nullOnDelete();
            $table->string('callback_type', 32)->index();
            $table->string('event_name', 191)->default('')->index();
            $table->string('member_id', 191)->default('')->index();
            $table->string('application_token', 191)->default('')->index();
            $table->string('portal_domain')->nullable();
            $table->string('payload_hash', 64)->index();
            $table->jsonb('payload');
            $table->jsonb('headers')->nullable();
            $table->jsonb('query')->nullable();
            $table->string('processing_status', 32)->default('pending')->index();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampsTz();

            $table->unique([
                'callback_type',
                'event_name',
                'member_id',
                'payload_hash',
            ], 'bitrix24_webhook_events_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitrix24_webhook_events');
    }
};
