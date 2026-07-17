<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('media_download_storage_budgets')->insertOrIgnore([
            'scope_type' => 'global',
            'scope_id' => 0,
            'reserved_bytes' => (int) DB::table('media_download_storage_ledgers')
                ->where('status', 'reserved')
                ->sum('reserved_bytes'),
            'used_bytes' => (int) DB::table('media_download_storage_ledgers')
                ->where('status', 'used')
                ->sum('used_bytes'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Data-only backfill: keep the live singleton until the owning schema migration rolls back.
    }
};
