<?php

use App\Models\FieldDictionaryField;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('field_dictionary_fields')) {
            return;
        }

        DB::table('field_dictionary_fields')
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'current_block_id')
            ->where('name', 'Текущий блок клиента')
            ->update(['name' => 'Текущий блок']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('field_dictionary_fields')) {
            return;
        }

        DB::table('field_dictionary_fields')
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'current_block_id')
            ->where('name', 'Текущий блок')
            ->update(['name' => 'Текущий блок клиента']);
    }
};
