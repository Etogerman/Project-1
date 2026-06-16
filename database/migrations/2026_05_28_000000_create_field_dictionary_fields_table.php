<?php

use App\Models\FieldDictionaryField;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_dictionary_fields', function (Blueprint $table): void {
            $table->id();
            $table->string('entity', 32);
            $table->string('field_key', 128);
            $table->string('name');
            $table->string('type', 32);
            $table->jsonb('options')->default(DB::raw("'[]'::jsonb"));
            $table->string('source_field_key', 128)->nullable();
            $table->integer('sort_order')->default(100);
            $table->boolean('is_multiple')->default(false);
            $table->boolean('is_system')->default(false);
            $table->string('condition_visibility', 32)->default('display_only');
            $table->string('write_access', 32)->default('read_only');
            $table->string('hint_group', 32)->default('system');
            $table->string('card_display_type', 32)->default('value');
            $table->timestampsTz();

            $table->unique(['entity', 'field_key']);
            $table->index(['entity', 'sort_order']);
            $table->index(['entity', 'source_field_key']);
            $table->index('is_system');
        });

        FieldDictionaryField::syncSystemDefinitions();
    }

    public function down(): void
    {
        Schema::dropIfExists('field_dictionary_fields');
    }
};
