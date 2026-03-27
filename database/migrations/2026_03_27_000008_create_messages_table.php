<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_identity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('direction')->default('inbound');
            $table->string('external_chat_id');
            $table->string('external_message_id')->nullable();
            $table->text('text')->nullable();
            $table->jsonb('raw_payload');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['channel_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
