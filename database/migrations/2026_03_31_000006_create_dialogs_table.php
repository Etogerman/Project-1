<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dialogs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_contact_identity_id')
                ->nullable()
                ->constrained('contact_identities')
                ->nullOnDelete();
            $table->string('external_chat_id')->nullable();
            $table->string('confirmed_phone_raw')->nullable();
            $table->string('confirmed_phone_normalized')->nullable();
            $table->timestamp('phone_confirmed_at')->nullable();
            $table->string('phone_confirmed_via', 32)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamps();

            $table->unique(['contact_id', 'channel_id']);
            $table->index('contact_id');
            $table->index('channel_id');
            $table->index('current_contact_identity_id');
            $table->index('confirmed_phone_normalized');
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dialogs');
    }
};
