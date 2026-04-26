<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_account_outgoing_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dialog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('external_chat_id');
            $table->text('text');
            $table->string('text_format', 32)->default('plain_text');
            $table->string('dedupe_key')->unique();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('sent_external_message_id')->nullable();
            $table->text('last_error_message')->nullable();
            $table->jsonb('result_payload')->nullable();
            $table->timestamps();

            $table->index(['channel_id', 'status', 'id']);
            $table->index(['dialog_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_account_outgoing_messages');
    }
};
