<?php

use App\Models\DataDictionaryEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_dictionary_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('data_dictionary_entries', 'gender')) {
                $table->string('gender', 16)
                    ->default(DataDictionaryEntry::GENDER_UNKNOWN)
                    ->after('result_value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('data_dictionary_entries', function (Blueprint $table): void {
            if (Schema::hasColumn('data_dictionary_entries', 'gender')) {
                $table->dropColumn('gender');
            }
        });
    }
};
