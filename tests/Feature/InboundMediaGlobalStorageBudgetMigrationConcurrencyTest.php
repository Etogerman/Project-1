<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\MediaDownloadStorageLedger;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InboundMediaGlobalStorageBudgetMigrationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_blocks_concurrent_global_budget_creation_until_totals_are_committed(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The production backfill locking contract is PostgreSQL-specific.');
        }

        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
        ]);
        $now = now();

        DB::table('media_download_storage_budgets')
            ->where('scope_type', 'global')
            ->where('scope_id', 0)
            ->delete();
        $ledgerId = DB::table('media_download_storage_ledgers')->insertGetId([
            'message_attachment_id' => $attachment->id,
            'channel_id' => $channel->id,
            'generation' => 1,
            'status' => MediaDownloadStorageLedger::STATUS_RESERVED,
            'reserved_bytes' => 10,
            'used_bytes' => 0,
            'release_reason' => null,
            'expires_at' => $now->copy()->addHour(),
            'released_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $defaultConnection = config('database.default');
        $concurrentConnection = 'inbound_media_migration_concurrent';
        config([
            'database.connections.'.$concurrentConnection => config(
                'database.connections.'.$defaultConnection,
            ),
        ]);
        DB::purge($concurrentConnection);
        $concurrentBudgetException = null;
        $concurrentLedgerException = null;
        $injectedConcurrentInsert = false;

        DB::listen(function (QueryExecuted $query) use (
            &$concurrentBudgetException,
            &$concurrentLedgerException,
            &$injectedConcurrentInsert,
            $concurrentConnection,
            $defaultConnection,
            $ledgerId,
            $now,
        ): void {
            if ($query->connectionName !== $defaultConnection || $injectedConcurrentInsert) {
                return;
            }

            $normalizedSql = strtolower($query->sql);

            if (
                ! str_contains($normalizedSql, 'sum(')
                || ! str_contains($normalizedSql, 'media_download_storage_ledgers')
            ) {
                return;
            }

            $injectedConcurrentInsert = true;
            $connection = DB::connection($concurrentConnection);
            $connection->statement("SET lock_timeout TO '100ms'");

            try {
                $connection->table('media_download_storage_budgets')->insertOrIgnore([
                    'scope_type' => 'global',
                    'scope_id' => 0,
                    'reserved_bytes' => 0,
                    'used_bytes' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $exception) {
                $concurrentBudgetException = $exception;
            }

            try {
                $connection->table('media_download_storage_ledgers')
                    ->where('id', $ledgerId)
                    ->update(['reserved_bytes' => 11]);
            } catch (QueryException $exception) {
                $concurrentLedgerException = $exception;
            } finally {
                $connection->statement('SET lock_timeout TO DEFAULT');
            }
        });

        $migration = require database_path(
            'migrations/2026_07_17_000001_backfill_inbound_media_global_storage_budget.php',
        );

        try {
            $migration->up();
        } finally {
            DB::purge($concurrentConnection);
        }

        $globalBudget = DB::table('media_download_storage_budgets')
            ->where('scope_type', 'global')
            ->where('scope_id', 0)
            ->first();
        $ledger = DB::table('media_download_storage_ledgers')->where('id', $ledgerId)->first();

        $this->assertTrue($injectedConcurrentInsert);
        $this->assertInstanceOf(QueryException::class, $concurrentBudgetException);
        $this->assertSame(
            '55P03',
            (string) ($concurrentBudgetException->errorInfo[0] ?? $concurrentBudgetException->getCode()),
        );
        $this->assertInstanceOf(QueryException::class, $concurrentLedgerException);
        $this->assertSame(
            '55P03',
            (string) ($concurrentLedgerException->errorInfo[0] ?? $concurrentLedgerException->getCode()),
        );
        $this->assertNotNull($globalBudget);
        $this->assertNotNull($ledger);
        $this->assertSame(10, (int) $globalBudget->reserved_bytes);
        $this->assertSame(0, (int) $globalBudget->used_bytes);
        $this->assertSame(10, (int) $ledger->reserved_bytes);
    }
}
