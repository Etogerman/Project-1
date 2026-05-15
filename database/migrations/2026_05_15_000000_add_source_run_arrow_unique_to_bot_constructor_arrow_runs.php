<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'bot_constructor_arrow_runs_source_run_arrow_unique';

    public function up(): void
    {
        if (
            ! Schema::hasTable('bot_constructor_arrow_runs')
            || ! Schema::hasColumn('bot_constructor_arrow_runs', 'source_execution_block_run_id')
            || $this->hasIndex(self::INDEX_NAME)
        ) {
            return;
        }

        Schema::table('bot_constructor_arrow_runs', function (Blueprint $table): void {
            $table->unique(
                ['source_execution_block_run_id', 'bot_constructor_arrow_id'],
                self::INDEX_NAME,
            );
        });
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('bot_constructor_arrow_runs')
            || ! $this->hasIndex(self::INDEX_NAME)
        ) {
            return;
        }

        Schema::table('bot_constructor_arrow_runs', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX_NAME);
        });
    }

    private function hasIndex(string $indexName): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        $result = DB::selectOne(
            "select exists (
                select 1
                from pg_indexes
                where schemaname = current_schema()
                  and tablename = 'bot_constructor_arrow_runs'
                  and indexname = ?
            ) as exists",
            [$indexName],
        );

        return (bool) ($result?->exists ?? false);
    }
};
