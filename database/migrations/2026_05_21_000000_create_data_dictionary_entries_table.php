<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_dictionary_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('dictionary_key', 64);
            $table->string('lookup_value');
            $table->string('lookup_normalized');
            $table->string('result_value');
            $table->boolean('auto_apply')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['dictionary_key', 'lookup_normalized']);
            $table->index(['dictionary_key', 'is_active', 'auto_apply']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_dictionary_entries');
    }
};
