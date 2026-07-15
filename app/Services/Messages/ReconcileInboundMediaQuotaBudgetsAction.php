<?php

namespace App\Services\Messages;

use App\Models\MediaDownloadStorageLedger;
use App\Models\MediaDownloadTrafficLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconcileInboundMediaQuotaBudgetsAction
{
    private const STORAGE_SCOPE_GLOBAL = 'global';

    private const STORAGE_SCOPE_CHANNEL = 'channel';

    /**
     * @return array{storage_rows_checked:int,storage_drift_rows:int,traffic_rows_checked:int,traffic_drift_rows:int,repaired_rows:int,remaining_drift_rows:int}
     */
    public function handle(bool $repair = false): array
    {
        return DB::transaction(function () use ($repair): array {
            $this->lockQuotaTables();

            $storageLedgers = DB::table('media_download_storage_ledgers')
                ->select(['channel_id', 'status', 'reserved_bytes', 'used_bytes'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $trafficLedgers = DB::table('media_download_traffic_ledgers')
                ->select(['channel_id', 'period_date', 'status', 'reserved_bytes', 'consumed_bytes'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $storageExpected = $this->expectedStorageBudgets($storageLedgers);
            $trafficExpected = $this->expectedTrafficBudgets($trafficLedgers);
            $storageActual = DB::table('media_download_storage_budgets')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $trafficActual = DB::table('media_download_traffic_budgets')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $storageDrift = $this->storageDriftRows($storageExpected, $storageActual);
            $trafficDrift = $this->trafficDriftRows($trafficExpected, $trafficActual);
            $repairedRows = 0;

            if ($repair) {
                $repairedRows += $this->repairStorageBudgets($storageExpected, $storageActual);
                $repairedRows += $this->repairTrafficBudgets($trafficExpected, $trafficActual);
            }

            $driftRows = $storageDrift + $trafficDrift;

            return [
                'storage_rows_checked' => $storageExpected->count() + $storageActual->count(),
                'storage_drift_rows' => $storageDrift,
                'traffic_rows_checked' => $trafficExpected->count() + $trafficActual->count(),
                'traffic_drift_rows' => $trafficDrift,
                'repaired_rows' => $repairedRows,
                'remaining_drift_rows' => $repair ? max(0, $driftRows - $repairedRows) : $driftRows,
            ];
        }, 3);
    }

    /**
     * @param  Collection<int, object>  $ledgers
     * @return Collection<string, array{scope_type:string,scope_id:int,reserved_bytes:int,used_bytes:int}>
     */
    private function expectedStorageBudgets(Collection $ledgers): Collection
    {
        $expected = collect([
            $this->storageKey(self::STORAGE_SCOPE_GLOBAL, 0) => [
                'scope_type' => self::STORAGE_SCOPE_GLOBAL,
                'scope_id' => 0,
                'reserved_bytes' => 0,
                'used_bytes' => 0,
            ],
        ]);

        foreach ($ledgers as $ledger) {
            $reservedBytes = $ledger->status === MediaDownloadStorageLedger::STATUS_RESERVED
                ? (int) $ledger->reserved_bytes
                : 0;
            $usedBytes = $ledger->status === MediaDownloadStorageLedger::STATUS_USED
                ? (int) $ledger->used_bytes
                : 0;
            $this->addStorageUsage($expected, self::STORAGE_SCOPE_GLOBAL, 0, $reservedBytes, $usedBytes);
            $this->addStorageUsage(
                $expected,
                self::STORAGE_SCOPE_CHANNEL,
                (int) $ledger->channel_id,
                $reservedBytes,
                $usedBytes,
            );
        }

        return $expected;
    }

    /**
     * @param  Collection<string, array{scope_type:string,scope_id:int,reserved_bytes:int,used_bytes:int}>  $expected
     */
    private function addStorageUsage(
        Collection $expected,
        string $scopeType,
        int $scopeId,
        int $reservedBytes,
        int $usedBytes,
    ): void {
        $key = $this->storageKey($scopeType, $scopeId);
        $row = $expected->get($key, [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'reserved_bytes' => 0,
            'used_bytes' => 0,
        ]);
        $row['reserved_bytes'] += $reservedBytes;
        $row['used_bytes'] += $usedBytes;
        $expected->put($key, $row);
    }

    /**
     * @param  Collection<int, object>  $ledgers
     * @return Collection<string, array{channel_id:int,period_date:string,reserved_bytes:int,consumed_bytes:int}>
     */
    private function expectedTrafficBudgets(Collection $ledgers): Collection
    {
        $expected = collect();

        foreach ($ledgers as $ledger) {
            $periodDate = (string) $ledger->period_date;
            $key = $this->trafficKey((int) $ledger->channel_id, $periodDate);
            $row = $expected->get($key, [
                'channel_id' => (int) $ledger->channel_id,
                'period_date' => $periodDate,
                'reserved_bytes' => 0,
                'consumed_bytes' => 0,
            ]);

            if ($ledger->status === MediaDownloadTrafficLedger::STATUS_RESERVED) {
                $row['reserved_bytes'] += (int) $ledger->reserved_bytes;
            }

            $row['consumed_bytes'] += (int) $ledger->consumed_bytes;
            $expected->put($key, $row);
        }

        return $expected;
    }

    /**
     * @param  Collection<string, array{scope_type:string,scope_id:int,reserved_bytes:int,used_bytes:int}>  $expected
     * @param  Collection<int, object>  $actual
     */
    private function storageDriftRows(Collection $expected, Collection $actual): int
    {
        $actualByKey = $actual->keyBy(fn (object $row): string => $this->storageKey(
            (string) $row->scope_type,
            (int) $row->scope_id,
        ));
        $keys = $expected->keys()->merge($actualByKey->keys())->unique();

        return $keys->filter(function (string $key) use ($expected, $actualByKey): bool {
            $expectedRow = $expected->get($key, ['reserved_bytes' => 0, 'used_bytes' => 0]);
            $actualRow = $actualByKey->get($key);

            return ! is_object($actualRow)
                || (int) $actualRow->reserved_bytes !== $expectedRow['reserved_bytes']
                || (int) $actualRow->used_bytes !== $expectedRow['used_bytes'];
        })->count();
    }

    /**
     * @param  Collection<string, array{channel_id:int,period_date:string,reserved_bytes:int,consumed_bytes:int}>  $expected
     * @param  Collection<int, object>  $actual
     */
    private function trafficDriftRows(Collection $expected, Collection $actual): int
    {
        $actualByKey = $actual->keyBy(fn (object $row): string => $this->trafficKey(
            (int) $row->channel_id,
            (string) $row->period_date,
        ));
        $keys = $expected->keys()->merge($actualByKey->keys())->unique();

        return $keys->filter(function (string $key) use ($expected, $actualByKey): bool {
            $expectedRow = $expected->get($key, ['reserved_bytes' => 0, 'consumed_bytes' => 0]);
            $actualRow = $actualByKey->get($key);

            return ! is_object($actualRow)
                || (int) $actualRow->reserved_bytes !== $expectedRow['reserved_bytes']
                || (int) $actualRow->consumed_bytes !== $expectedRow['consumed_bytes'];
        })->count();
    }

    /**
     * @param  Collection<string, array{scope_type:string,scope_id:int,reserved_bytes:int,used_bytes:int}>  $expected
     * @param  Collection<int, object>  $actual
     */
    private function repairStorageBudgets(Collection $expected, Collection $actual): int
    {
        $rows = $expected;

        foreach ($actual as $actualRow) {
            $key = $this->storageKey((string) $actualRow->scope_type, (int) $actualRow->scope_id);

            if (! $rows->has($key)) {
                $rows->put($key, [
                    'scope_type' => (string) $actualRow->scope_type,
                    'scope_id' => (int) $actualRow->scope_id,
                    'reserved_bytes' => 0,
                    'used_bytes' => 0,
                ]);
            }
        }

        $repaired = 0;

        foreach ($rows as $row) {
            $existing = $actual->first(fn (object $actualRow): bool => (string) $actualRow->scope_type === $row['scope_type']
                && (int) $actualRow->scope_id === $row['scope_id']);

            if (
                is_object($existing)
                && (int) $existing->reserved_bytes === $row['reserved_bytes']
                && (int) $existing->used_bytes === $row['used_bytes']
            ) {
                continue;
            }

            DB::table('media_download_storage_budgets')->updateOrInsert(
                ['scope_type' => $row['scope_type'], 'scope_id' => $row['scope_id']],
                [
                    'reserved_bytes' => $row['reserved_bytes'],
                    'used_bytes' => $row['used_bytes'],
                    'created_at' => $existing->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
            $repaired++;
        }

        return $repaired;
    }

    /**
     * @param  Collection<string, array{channel_id:int,period_date:string,reserved_bytes:int,consumed_bytes:int}>  $expected
     * @param  Collection<int, object>  $actual
     */
    private function repairTrafficBudgets(Collection $expected, Collection $actual): int
    {
        $rows = $expected;

        foreach ($actual as $actualRow) {
            $key = $this->trafficKey((int) $actualRow->channel_id, (string) $actualRow->period_date);

            if (! $rows->has($key)) {
                $rows->put($key, [
                    'channel_id' => (int) $actualRow->channel_id,
                    'period_date' => (string) $actualRow->period_date,
                    'reserved_bytes' => 0,
                    'consumed_bytes' => 0,
                ]);
            }
        }

        $repaired = 0;

        foreach ($rows as $row) {
            $existing = $actual->first(fn (object $actualRow): bool => (int) $actualRow->channel_id === $row['channel_id']
                && (string) $actualRow->period_date === $row['period_date']);

            if (
                is_object($existing)
                && (int) $existing->reserved_bytes === $row['reserved_bytes']
                && (int) $existing->consumed_bytes === $row['consumed_bytes']
            ) {
                continue;
            }

            DB::table('media_download_traffic_budgets')->updateOrInsert(
                ['channel_id' => $row['channel_id'], 'period_date' => $row['period_date']],
                [
                    'reserved_bytes' => $row['reserved_bytes'],
                    'consumed_bytes' => $row['consumed_bytes'],
                    'created_at' => $existing->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
            $repaired++;
        }

        return $repaired;
    }

    private function storageKey(string $scopeType, int $scopeId): string
    {
        return $scopeType.':'.$scopeId;
    }

    private function trafficKey(int $channelId, string $periodDate): string
    {
        return $channelId.':'.$periodDate;
    }

    private function lockQuotaTables(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            LOCK TABLE
                media_download_storage_budgets,
                media_download_storage_ledgers,
                media_download_traffic_budgets,
                media_download_traffic_ledgers
            IN SHARE ROW EXCLUSIVE MODE
        SQL);
    }
}
