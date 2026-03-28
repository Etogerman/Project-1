<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_reply_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->string('normalized_keyword');
            $table->text('reply_text');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('channel_id');
            $table->unique(['channel_id', 'normalized_keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_reply_rules');
    }
};
