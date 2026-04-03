<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_channel_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('scenario_code', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['channel_id', 'scenario_code'], 'scenario_channel_bindings_channel_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_channel_bindings');
    }
};
