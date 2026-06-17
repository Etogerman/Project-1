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
        Schema::table('field_dictionary_fields', function (Blueprint $table): void {
            if (! Schema::hasColumn('field_dictionary_fields', 'condition_visibility')) {
                $table->string('condition_visibility', 32)->default('display_only');
            }

            if (! Schema::hasColumn('field_dictionary_fields', 'write_access')) {
                $table->string('write_access', 32)->default('read_only');
            }

            if (! Schema::hasColumn('field_dictionary_fields', 'hint_group')) {
                $table->string('hint_group', 32)->default('system');
            }
        });

        Schema::table('field_dictionary_fields', function (Blueprint $table): void {
            $table->index(['entity', 'condition_visibility'], 'field_dictionary_fields_entity_condition_visibility_index');
            $table->index(['entity', 'write_access'], 'field_dictionary_fields_entity_write_access_index');
            $table->index(['entity', 'hint_group'], 'field_dictionary_fields_entity_hint_group_index');
        });

        DB::table('field_dictionary_fields')
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('is_system', false)
            ->update([
                'condition_visibility' => FieldDictionaryField::CONDITION_VISIBILITY_MAIN,
                'write_access' => FieldDictionaryField::WRITE_ACCESS_WRITABLE,
                'hint_group' => FieldDictionaryField::HINT_GROUP_DIALOG,
            ]);

        DB::table('field_dictionary_fields')
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('is_system', false)
            ->update([
                'condition_visibility' => FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY,
                'write_access' => FieldDictionaryField::WRITE_ACCESS_READ_ONLY,
                'hint_group' => FieldDictionaryField::HINT_GROUP_CONTACT,
            ]);

        FieldDictionaryField::syncSystemDefinitions();
    }

    public function down(): void
    {
        Schema::table('field_dictionary_fields', function (Blueprint $table): void {
            $table->dropIndex('field_dictionary_fields_entity_condition_visibility_index');
            $table->dropIndex('field_dictionary_fields_entity_write_access_index');
            $table->dropIndex('field_dictionary_fields_entity_hint_group_index');
        });

        Schema::table('field_dictionary_fields', function (Blueprint $table): void {
            if (Schema::hasColumn('field_dictionary_fields', 'condition_visibility')) {
                $table->dropColumn('condition_visibility');
            }

            if (Schema::hasColumn('field_dictionary_fields', 'write_access')) {
                $table->dropColumn('write_access');
            }

            if (Schema::hasColumn('field_dictionary_fields', 'hint_group')) {
                $table->dropColumn('hint_group');
            }
        });
    }
};
