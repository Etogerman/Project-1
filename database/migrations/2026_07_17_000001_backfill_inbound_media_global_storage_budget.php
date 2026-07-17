<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->lockStorageQuotaTables();

            $totals = DB::table('media_download_storage_ledgers')
                ->selectRaw(
                    <<<'SQL'
                        COALESCE(SUM(CASE WHEN status = ? THEN reserved_bytes ELSE 0 END), 0) AS reserved_bytes,
                        COALESCE(SUM(CASE WHEN status = ? THEN used_bytes ELSE 0 END), 0) AS used_bytes
                    SQL,
                    ['reserved', 'used'],
                )
                ->first();
            $now = now();

            DB::table('media_download_storage_budgets')->insertOrIgnore([
                'scope_type' => 'global',
                'scope_id' => 0,
                'reserved_bytes' => (int) ($totals->reserved_bytes ?? 0),
                'used_bytes' => (int) ($totals->used_bytes ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 3);
    }

    public function down(): void
    {
        // Data-only backfill: keep the live singleton until the owning schema migration rolls back.
    }

    private function lockStorageQuotaTables(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            LOCK TABLE
                media_download_storage_budgets,
                media_download_storage_ledgers
            IN SHARE ROW EXCLUSIVE MODE
        SQL);
    }
};
