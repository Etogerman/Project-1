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
            if (! Schema::hasColumn('field_dictionary_fields', 'manual_write_access')) {
                $table->string('manual_write_access', 32)->default(FieldDictionaryField::MANUAL_WRITE_ACCESS_READONLY);
            }

            if (! Schema::hasColumn('field_dictionary_fields', 'scenario_write_access')) {
                $table->string('scenario_write_access', 32)->default(FieldDictionaryField::SCENARIO_WRITE_ACCESS_DENIED);
            }

            if (! Schema::hasColumn('field_dictionary_fields', 'value_owner')) {
                $table->string('value_owner', 32)->default(FieldDictionaryField::VALUE_OWNER_SYSTEM);
            }
        });

        Schema::table('field_dictionary_fields', function (Blueprint $table): void {
            $table->index(['entity', 'manual_write_access'], 'field_dictionary_fields_entity_manual_write_access_index');
            $table->index(['entity', 'scenario_write_access'], 'field_dictionary_fields_entity_scenario_write_access_index');
            $table->index(['entity', 'value_owner'], 'field_dictionary_fields_entity_value_owner_index');
        });

        DB::table('field_dictionary_fields')
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('is_system', false)
            ->update([
                'manual_write_access' => FieldDictionaryField::MANUAL_WRITE_ACCESS_EDITABLE,
                'scenario_write_access' => FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED,
                'value_owner' => FieldDictionaryField::VALUE_OWNER_OPERATOR,
                'write_access' => FieldDictionaryField::WRITE_ACCESS_WRITABLE,
            ]);

        DB::table('field_dictionary_fields')
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('is_system', false)
            ->update([
                'manual_write_access' => FieldDictionaryField::MANUAL_WRITE_ACCESS_READONLY,
                'scenario_write_access' => FieldDictionaryField::SCENARIO_WRITE_ACCESS_DENIED,
                'value_owner' => FieldDictionaryField::VALUE_OWNER_OPERATOR,
                'write_access' => FieldDictionaryField::WRITE_ACCESS_READ_ONLY,
            ]);

        DB::table('field_dictionary_fields')
            ->where('write_access', FieldDictionaryField::WRITE_ACCESS_WRITABLE)
            ->update([
                'scenario_write_access' => FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED,
            ]);

        FieldDictionaryField::syncSystemDefinitions();
    }

    public function down(): void
    {
        Schema::table('field_dictionary_fields', function (Blueprint $table): void {
            $table->dropIndex('field_dictionary_fields_entity_manual_write_access_index');
            $table->dropIndex('field_dictionary_fields_entity_scenario_write_access_index');
            $table->dropIndex('field_dictionary_fields_entity_value_owner_index');
        });

        Schema::table('field_dictionary_fields', function (Blueprint $table): void {
            if (Schema::hasColumn('field_dictionary_fields', 'manual_write_access')) {
                $table->dropColumn('manual_write_access');
            }

            if (Schema::hasColumn('field_dictionary_fields', 'scenario_write_access')) {
                $table->dropColumn('scenario_write_access');
            }

            if (Schema::hasColumn('field_dictionary_fields', 'value_owner')) {
                $table->dropColumn('value_owner');
            }
        });
    }
};
