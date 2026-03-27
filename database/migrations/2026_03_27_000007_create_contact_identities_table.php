<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('external_user_id');
            $table->string('external_username')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'external_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_identities');
    }
};
