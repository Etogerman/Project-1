<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_constructor_arrow_runs') || Schema::hasColumn('bot_constructor_arrow_runs', 'schema_cutoff_at')) {
            return;
        }

        Schema::table('bot_constructor_arrow_runs', function (Blueprint $table): void {
            $table->timestamp('schema_cutoff_at')->nullable()->after('processing_started_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bot_constructor_arrow_runs') || ! Schema::hasColumn('bot_constructor_arrow_runs', 'schema_cutoff_at')) {
            return;
        }

        Schema::table('bot_constructor_arrow_runs', function (Blueprint $table): void {
            $table->dropColumn('schema_cutoff_at');
        });
    }
};
