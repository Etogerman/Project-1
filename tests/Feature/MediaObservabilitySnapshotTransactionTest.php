<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaObservabilitySnapshotTransactionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_command_uses_a_repeatable_read_only_snapshot_under_concurrent_change(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The production read-only snapshot contract is PostgreSQL-specific.');
        }

        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config([
            'filesystems.message_attachments_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'inbound_media.observability.attachment_scan_limit' => 100,
            'inbound_media.observability.orphan_scan_limit' => 100,
            'inbound_media.traffic.channel_daily_limit_bytes' => null,
        ]);
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
        ]);
        $defaultConnection = config('database.default');
        $concurrentConnection = 'observability_concurrent';
        config([
            'database.connections.'.$concurrentConnection => config(
                'database.connections.'.$defaultConnection,
            ),
        ]);
        DB::purge($concurrentConnection);
        $queries = [];
        $injectedConcurrentChange = false;

        DB::listen(function (QueryExecuted $query) use (
            &$queries,
            &$injectedConcurrentChange,
            $attachment,
            $channel,
            $concurrentConnection,
            $defaultConnection,
        ): void {
            if ($query->connectionName !== $defaultConnection) {
                return;
            }

            $normalizedSql = strtolower(trim($query->sql));
            $queries[] = $normalizedSql;

            if (
                $injectedConcurrentChange
                || (! str_starts_with($normalizedSql, 'select') && ! str_starts_with($normalizedSql, 'with'))
            ) {
                return;
            }

            $injectedConcurrentChange = true;
            DB::connection($concurrentConnection)
                ->table('media_download_traffic_ledgers')
                ->insert([
                    'message_attachment_id' => $attachment->id,
                    'channel_id' => $channel->id,
                    'generation' => 1,
                    'attempt_number' => 1,
                    'period_date' => now()->toDateString(),
                    'status' => 'consumed',
                    'reserved_bytes' => 10,
                    'consumed_bytes' => 10,
                    'checkpoint_bytes' => 10,
                    'release_reason' => 'completed',
                    'expires_at' => null,
                    'released_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        try {
            $exitCode = Artisan::call('media:observability-snapshot', [
                '--window' => '60',
                '--json' => true,
            ]);
            $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            DB::purge($concurrentConnection);
        }

        $this->assertTrue($injectedConcurrentChange);
        $this->assertContains(
            'set transaction isolation level repeatable read read only',
            $queries,
        );
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(0, $payload['current']['traffic_ledger_drift']);
        $this->assertNotContains('traffic_ledger_drift', $payload['blocking_anomalies']);
        $this->assertSame(1, DB::table('media_download_traffic_ledgers')->count());
    }
}
