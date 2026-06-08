<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_countries', function (Blueprint $table): void {
            $table->id();
            $table->string('iso2', 2)->unique();
            $table->string('iso3', 3)->unique();
            $table->string('name_ru');
            $table->string('name_en')->nullable();
            $table->string('normalized_name')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('geo_regions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained('geo_countries')->cascadeOnDelete();
            $table->string('code')->nullable();
            $table->string('name_ru');
            $table->string('name_en')->nullable();
            $table->string('normalized_name');
            $table->string('type')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'code']);
            $table->unique(['country_id', 'normalized_name']);
        });

        Schema::create('geo_cities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained('geo_countries')->cascadeOnDelete();
            $table->foreignId('region_id')->constrained('geo_regions')->cascadeOnDelete();
            $table->string('name_ru');
            $table->string('name_en')->nullable();
            $table->string('normalized_name');
            $table->unsignedInteger('population')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lon', 10, 7)->nullable();
            $table->string('timezone')->nullable();
            $table->string('source')->nullable();
            $table->string('source_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'region_id', 'normalized_name']);
        });

        Schema::create('geo_aliases', function (Blueprint $table): void {
            $table->id();
            $table->string('alias');
            $table->string('normalized_alias');
            $table->foreignId('city_id')->constrained('geo_cities')->cascadeOnDelete();
            $table->string('language')->nullable();
            $table->string('alias_type')->nullable();
            $table->unsignedTinyInteger('confidence')->default(100);
            $table->boolean('auto_apply')->default(true);
            $table->boolean('active')->default(true);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['normalized_alias', 'city_id']);
            $table->index('normalized_alias');
        });

        Schema::create('geo_resolution_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('dialog_id')->nullable()->constrained('dialogs')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('status');
            $table->text('source_text')->nullable();
            $table->string('matched_alias')->nullable();
            $table->foreignId('geo_alias_id')->nullable()->constrained('geo_aliases')->nullOnDelete();
            $table->foreignId('country_id')->nullable()->constrained('geo_countries')->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('geo_regions')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('geo_cities')->nullOnDelete();
            $table->string('country')->nullable();
            $table->string('region')->nullable();
            $table->string('city')->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['contact_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_resolution_events');
        Schema::dropIfExists('geo_aliases');
        Schema::dropIfExists('geo_cities');
        Schema::dropIfExists('geo_regions');
        Schema::dropIfExists('geo_countries');
    }
};
