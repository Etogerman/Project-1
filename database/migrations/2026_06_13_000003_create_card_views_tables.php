<?php

use App\Models\FieldDictionaryField;
use App\Services\Contacts\SyncSystemContactCardViewAction;
use App\Services\Dialogs\SyncSystemDialogCardViewAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_views', function (Blueprint $table): void {
            $table->id();
            $table->string('entity', 32);
            $table->string('context', 32);
            $table->string('view_key', 64);
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->string('scope', 32)->default('system');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->unique(['entity', 'context', 'view_key']);
            $table->index(['entity', 'context']);
            $table->index(['scope']);
        });

        DB::statement(
            'create unique index card_views_unique_default_per_entity_context on card_views (entity, context) where is_default = true'
        );

        Schema::create('card_view_tabs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('card_view_id')->constrained('card_views')->cascadeOnDelete();
            $table->string('tab_key', 64);
            $table->string('name');
            $table->integer('sort_order')->default(100);
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();

            $table->unique(['card_view_id', 'tab_key']);
            $table->index(['card_view_id', 'sort_order']);
        });

        Schema::create('card_view_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('card_view_tab_id')->constrained('card_view_tabs')->cascadeOnDelete();
            $table->string('section_key', 64);
            $table->string('name');
            $table->integer('sort_order')->default(100);
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_collapsed_by_default')->default(false);
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();

            $table->unique(['card_view_tab_id', 'section_key']);
            $table->index(['card_view_tab_id', 'sort_order']);
        });

        Schema::create('card_view_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('card_view_section_id')->constrained('card_view_sections')->cascadeOnDelete();
            $table->string('item_key', 128);
            $table->string('item_type', 32);
            $table->foreignId('field_dictionary_field_id')->nullable()->constrained('field_dictionary_fields')->restrictOnDelete();
            $table->integer('sort_order')->default(100);
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();

            $table->unique(['card_view_section_id', 'item_key']);
            $table->index(['field_dictionary_field_id']);
            $table->index(['card_view_section_id', 'sort_order']);
        });

        FieldDictionaryField::syncSystemDefinitions();
        app(SyncSystemContactCardViewAction::class)->handle();
        app(SyncSystemDialogCardViewAction::class)->handle();
    }

    public function down(): void
    {
        Schema::dropIfExists('card_view_items');
        Schema::dropIfExists('card_view_sections');
        Schema::dropIfExists('card_view_tabs');
        Schema::dropIfExists('card_views');
    }
};
