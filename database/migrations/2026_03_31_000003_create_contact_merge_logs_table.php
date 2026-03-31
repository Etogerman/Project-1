<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_merge_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('primary_contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('secondary_contact_id')->unique()->constrained('contacts')->restrictOnDelete();
            $table->string('trigger_phone', 32);
            $table->foreignId('trigger_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('merge_reason', 50);
            $table->unsignedInteger('messages_moved_count')->default(0);
            $table->unsignedInteger('identities_moved_count')->default(0);
            $table->unsignedInteger('phones_moved_count')->default(0);
            $table->jsonb('fields_copied')->nullable();
            $table->jsonb('fields_conflicted')->nullable();
            $table->string('created_by_type', 20)->default('system');
            $table->timestamp('created_at')->useCurrent();

            $table->index('primary_contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_merge_logs');
    }
};
