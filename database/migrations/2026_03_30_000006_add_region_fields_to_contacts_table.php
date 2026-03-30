<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('region')->nullable()->after('city');
            $table->string('region_status')->nullable()->after('region');
            $table->string('region_source')->nullable()->after('region_status');
            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex(['region']);
            $table->dropColumn([
                'region',
                'region_status',
                'region_source',
            ]);
        });
    }
};
