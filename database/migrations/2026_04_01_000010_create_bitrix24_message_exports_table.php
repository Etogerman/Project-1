<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitrix24_message_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('bitrix24_contact_id')->nullable()->index();
            $table->string('export_mode', 32)->index();
            $table->string('export_status', 32)->index();
            $table->uuid('batch_uuid')->nullable()->index();
            $table->string('bitrix24_timeline_entry_id')->nullable();
            $table->timestampTz('exported_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['message_id', 'export_mode'], 'bitrix24_message_exports_message_mode_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitrix24_message_exports');
    }
};
