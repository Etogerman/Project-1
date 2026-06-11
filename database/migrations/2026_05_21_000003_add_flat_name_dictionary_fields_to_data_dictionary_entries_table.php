<?php

use App\Models\DataDictionaryEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_dictionary_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('data_dictionary_entries', 'result_normalized')) {
                $table->string('result_normalized')
                    ->default('')
                    ->after('result_value');
            }

            if (! Schema::hasColumn('data_dictionary_entries', 'language')) {
                $table->string('language', 16)
                    ->default(DataDictionaryEntry::LANGUAGE_RU)
                    ->after('gender');
            }

            if (! Schema::hasColumn('data_dictionary_entries', 'variant_type')) {
                $table->string('variant_type', 32)
                    ->default(DataDictionaryEntry::VARIANT_TYPE_SHORT)
                    ->after('language');
            }
        });

        DB::table('data_dictionary_entries')
            ->orderBy('id')
            ->get(['id', 'result_value'])
            ->each(function (object $entry): void {
                DB::table('data_dictionary_entries')
                    ->where('id', $entry->id)
                    ->update([
                        'result_normalized' => DataDictionaryEntry::normalizeLookupValue((string) $entry->result_value),
                    ]);
            });

        Schema::table('data_dictionary_entries', function (Blueprint $table): void {
            $table->unique([
                'dictionary_key',
                'lookup_normalized',
                'result_normalized',
                'gender',
                'language',
            ], 'data_dictionary_entries_unique_name_variant');
        });
    }

    public function down(): void
    {
        Schema::table('data_dictionary_entries', function (Blueprint $table): void {
            $table->dropUnique('data_dictionary_entries_unique_name_variant');

            if (Schema::hasColumn('data_dictionary_entries', 'variant_type')) {
                $table->dropColumn('variant_type');
            }

            if (Schema::hasColumn('data_dictionary_entries', 'language')) {
                $table->dropColumn('language');
            }

            if (Schema::hasColumn('data_dictionary_entries', 'result_normalized')) {
                $table->dropColumn('result_normalized');
            }
        });
    }
};
