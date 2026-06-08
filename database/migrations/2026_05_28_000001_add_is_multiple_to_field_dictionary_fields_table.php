<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_dictionary_fields', function (Blueprint $table): void {
            if (! Schema::hasColumn('field_dictionary_fields', 'is_multiple')) {
                $table->boolean('is_multiple')->default(false)->after('sort_order');
            }
        });

        DB::table('field_dictionary_fields')
            ->where('entity', 'contact')
            ->whereIn('field_key', ['phones', 'emails'])
            ->update(['is_multiple' => true]);

        DB::table('field_dictionary_fields')
            ->where('entity', 'contact')
            ->where('field_key', 'phones')
            ->update(['type' => 'phone']);

        DB::table('field_dictionary_fields')
            ->where('entity', 'contact')
            ->where('field_key', 'emails')
            ->update(['type' => 'email']);

        DB::table('field_dictionary_fields')
            ->where('entity', 'dialog')
            ->where('field_key', 'phone')
            ->update(['type' => 'phone']);
    }

    public function down(): void
    {
        Schema::table('field_dictionary_fields', function (Blueprint $table): void {
            if (Schema::hasColumn('field_dictionary_fields', 'is_multiple')) {
                $table->dropColumn('is_multiple');
            }
        });
    }
};
