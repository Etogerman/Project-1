<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bitrix24_open_line_routes', function (Blueprint $table): void {
            $table->string('line_name', 255)->nullable()->after('line_id');
        });
    }

    public function down(): void
    {
        Schema::table('bitrix24_open_line_routes', function (Blueprint $table): void {
            $table->dropColumn('line_name');
        });
    }
};
