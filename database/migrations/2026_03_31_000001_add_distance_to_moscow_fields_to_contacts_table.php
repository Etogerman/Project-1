<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->unsignedInteger('distance_to_moscow_km')->nullable()->after('region_source');
            $table->string('distance_to_moscow_status')->nullable()->after('distance_to_moscow_km');
            $table->dateTime('distance_to_moscow_calculated_at')->nullable()->after('distance_to_moscow_status');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn([
                'distance_to_moscow_km',
                'distance_to_moscow_status',
                'distance_to_moscow_calculated_at',
            ]);
        });
    }
};
