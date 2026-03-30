<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->unsignedSmallInteger('age_years')->nullable()->after('last_name');
            $table->date('birth_date')->nullable()->after('age_years');
            $table->string('country')->nullable()->after('birth_date');
            $table->string('city')->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn([
                'first_name',
                'last_name',
                'age_years',
                'birth_date',
                'country',
                'city',
            ]);
        });
    }
};
