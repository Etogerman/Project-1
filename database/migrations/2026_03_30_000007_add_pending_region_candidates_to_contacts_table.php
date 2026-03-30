<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->json('pending_region_candidates')->nullable()->after('region_source');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('pending_region_candidates');
        });
    }
};
