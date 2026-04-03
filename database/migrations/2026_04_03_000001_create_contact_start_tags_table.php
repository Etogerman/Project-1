<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_start_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('category', 50);
            $table->string('code', 255);
            $table->string('source', 50);
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->unique(['contact_id', 'category', 'code']);
            $table->index(['contact_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_start_tags');
    }
};
