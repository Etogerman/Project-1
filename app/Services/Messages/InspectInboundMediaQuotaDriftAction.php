<?php

namespace App\Services\Messages;

use App\Models\MediaDownloadStorageLedger;
use App\Models\MediaDownloadTrafficLedger;
use Illuminate\Support\Facades\DB;

class InspectInboundMediaQuotaDriftAction
{
    /**
     * @return array{storage_drift_rows:int,traffic_drift_rows:int}
     */
    public function handle(): array
    {
        return [
            'storage_drift_rows' => $this->storageDriftRows(),
            'traffic_drift_rows' => $this->trafficDriftRows(),
        ];
    }

    private function storageDriftRows(): int
    {
        $row = DB::selectOne(<<<'SQL'
            WITH storage_totals AS (
                SELECT
                    COALESCE(SUM(CASE WHEN status = ? THEN reserved_bytes ELSE 0 END), 0) AS reserved_bytes,
                    COALESCE(SUM(CASE WHEN status = ? THEN used_bytes ELSE 0 END), 0) AS used_bytes
                FROM media_download_storage_ledgers
            ), expected AS (
                SELECT
                    'global'::varchar AS scope_type,
                    0::bigint AS scope_id,
                    reserved_bytes,
                    used_bytes
                FROM storage_totals

                UNION ALL

                SELECT
                    'channel'::varchar AS scope_type,
                    channel_id::bigint AS scope_id,
                    COALESCE(SUM(CASE WHEN status = ? THEN reserved_bytes ELSE 0 END), 0) AS reserved_bytes,
                    COALESCE(SUM(CASE WHEN status = ? THEN used_bytes ELSE 0 END), 0) AS used_bytes
                FROM media_download_storage_ledgers
                GROUP BY channel_id
            )
            SELECT COUNT(*) AS drift_rows
            FROM expected
            FULL OUTER JOIN media_download_storage_budgets AS actual
                ON actual.scope_type = expected.scope_type
                AND actual.scope_id = expected.scope_id
            WHERE
                actual.id IS NULL
                OR (
                    expected.scope_type IS NULL
                    AND (actual.reserved_bytes <> 0 OR actual.used_bytes <> 0)
                )
                OR (
                    expected.scope_type IS NOT NULL
                    AND (
                        actual.reserved_bytes <> expected.reserved_bytes
                        OR actual.used_bytes <> expected.used_bytes
                    )
                )
        SQL, [
            MediaDownloadStorageLedger::STATUS_RESERVED,
            MediaDownloadStorageLedger::STATUS_USED,
            MediaDownloadStorageLedger::STATUS_RESERVED,
            MediaDownloadStorageLedger::STATUS_USED,
        ]);

        return (int) ($row->drift_rows ?? 0);
    }

    private function trafficDriftRows(): int
    {
        $row = DB::selectOne(<<<'SQL'
            WITH expected AS (
                SELECT
                    channel_id,
                    period_date,
                    COALESCE(SUM(CASE WHEN status = ? THEN reserved_bytes ELSE 0 END), 0) AS reserved_bytes,
                    COALESCE(SUM(consumed_bytes), 0) AS consumed_bytes
                FROM media_download_traffic_ledgers
                GROUP BY channel_id, period_date
            )
            SELECT COUNT(*) AS drift_rows
            FROM expected
            FULL OUTER JOIN media_download_traffic_budgets AS actual
                ON actual.channel_id = expected.channel_id
                AND actual.period_date = expected.period_date
            WHERE
                actual.id IS NULL
                OR (
                    expected.channel_id IS NULL
                    AND (actual.reserved_bytes <> 0 OR actual.consumed_bytes <> 0)
                )
                OR (
                    expected.channel_id IS NOT NULL
                    AND (
                        actual.reserved_bytes <> expected.reserved_bytes
                        OR actual.consumed_bytes <> expected.consumed_bytes
                    )
                )
        SQL, [MediaDownloadTrafficLedger::STATUS_RESERVED]);

        return (int) ($row->drift_rows ?? 0);
    }
}
