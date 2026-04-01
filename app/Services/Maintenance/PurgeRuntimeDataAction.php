<?php

namespace App\Services\Maintenance;

use App\Data\Maintenance\RuntimeDataPurgeResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurgeRuntimeDataAction
{
    /**
     * @var list<string>
     */
    private const RUNTIME_TABLES = [
        'messages',
        'dialogs',
        'contact_phone_numbers',
        'contact_identities',
        'contact_merge_logs',
        'contact_duplicate_reviews',
        'contacts',
        'channel_activity_logs',
        'jobs',
        'failed_jobs',
        'job_batches',
    ];

    /**
     * @var list<string>
     */
    private const PRESERVED_TABLES = [
        'users',
        'channels',
        'auto_reply_rules',
        'migrations',
        'password_reset_tokens',
        'cache',
        'cache_locks',
    ];

    public function handle(bool $force = false, bool $includeSessions = false): RuntimeDataPurgeResult
    {
        $purgedTables = $this->resolvePurgedTables($includeSessions);
        $beforeCounts = $this->countRows($purgedTables);

        if ($force) {
            $this->purgeTables($purgedTables);
        }

        $afterCounts = $force
            ? $this->countRows($purgedTables)
            : $beforeCounts;

        return new RuntimeDataPurgeResult(
            dryRun: ! $force,
            includedSessions: $includeSessions,
            beforeCounts: $beforeCounts,
            afterCounts: $afterCounts,
            purgedTables: $purgedTables,
            preservedTables: self::PRESERVED_TABLES,
        );
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function countRows(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = Schema::hasTable($table)
                ? DB::table($table)->count()
                : 0;
        }

        return $counts;
    }

    /**
     * @param  list<string>  $tables
     */
    private function purgeTables(array $tables): void
    {
        $existingTables = array_values(array_filter(
            $tables,
            fn (string $table): bool => Schema::hasTable($table),
        ));

        if ($existingTables === []) {
            return;
        }

        $driver = DB::getDriverName();

        DB::transaction(function () use ($driver, $existingTables): void {
            if ($driver === 'pgsql') {
                $quotedTables = implode(', ', array_map(
                    fn (string $table): string => '"'.$table.'"',
                    $existingTables,
                ));

                DB::statement('TRUNCATE TABLE '.$quotedTables.' RESTART IDENTITY CASCADE');

                return;
            }

            Schema::disableForeignKeyConstraints();

            try {
                foreach ($existingTables as $table) {
                    DB::table($table)->delete();
                }
            } finally {
                Schema::enableForeignKeyConstraints();
            }
        });
    }

    /**
     * @return list<string>
     */
    private function resolvePurgedTables(bool $includeSessions): array
    {
        $tables = self::RUNTIME_TABLES;

        if ($includeSessions) {
            $tables[] = 'sessions';
        }

        return $tables;
    }
}
