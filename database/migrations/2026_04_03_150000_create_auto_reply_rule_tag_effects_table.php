<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_reply_rule_tag_effects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('auto_reply_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('effect', 32);
            $table->timestamps();

            $table->unique(['auto_reply_rule_id', 'tag_id', 'effect'], 'auto_reply_rule_tag_effects_unique');
            $table->index(['auto_reply_rule_id', 'effect']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_reply_rule_tag_effects');
    }
};
