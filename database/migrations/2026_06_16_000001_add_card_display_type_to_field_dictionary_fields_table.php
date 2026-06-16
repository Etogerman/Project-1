<?php

use App\Models\FieldDictionaryField;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_dictionary_fields', function (Blueprint $table): void {
            if (! Schema::hasColumn('field_dictionary_fields', 'card_display_type')) {
                $table->string('card_display_type', 32)->default(FieldDictionaryField::CARD_DISPLAY_VALUE)->after('hint_group');
            }
        });

        FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'phones')
            ->update(['card_display_type' => FieldDictionaryField::CARD_DISPLAY_PHONE_LIST]);

        FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'emails')
            ->update(['card_display_type' => FieldDictionaryField::CARD_DISPLAY_EMAIL_LIST]);

        FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'tags')
            ->update(['card_display_type' => FieldDictionaryField::CARD_DISPLAY_TAG_LIST]);

        FieldDictionaryField::syncSystemDefinitions();
    }

    public function down(): void
    {
        Schema::table('field_dictionary_fields', function (Blueprint $table): void {
            if (Schema::hasColumn('field_dictionary_fields', 'card_display_type')) {
                $table->dropColumn('card_display_type');
            }
        });
    }
};
