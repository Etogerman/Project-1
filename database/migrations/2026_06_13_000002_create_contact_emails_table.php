<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('email_raw');
            $table->string('email_normalized');
            $table->string('source', 100);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['contact_id', 'email_normalized']);
            $table->index('contact_id');
            $table->index('email_normalized');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_emails');
    }
};
