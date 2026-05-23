<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_pricing_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('model');
            $table->decimal('input_price_per_1m_tokens', 18, 8)->default(0);
            $table->decimal('output_price_per_1m_tokens', 18, 8)->default(0);
            $table->decimal('thinking_price_per_1m_tokens', 18, 8)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->date('effective_from');
            $table->boolean('is_active')->default(true);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['provider', 'model', 'currency', 'is_active', 'effective_from'], 'ai_pricing_rates_lookup_index');
        });

        DB::statement(
            'create unique index ai_pricing_rates_unique_active_rate on ai_pricing_rates (provider, model, effective_from, currency) where is_active = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pricing_rates');
    }
};
