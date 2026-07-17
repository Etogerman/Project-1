<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Messages\BuildInboundMediaObservabilitySnapshotAction;
use App\Services\Messages\ReconcileInboundMediaStorageAction;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class MediaObservabilitySnapshotCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config([
            'filesystems.message_attachments_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'inbound_media.lease_stale_seconds' => 120,
            'inbound_media.orphan_grace_seconds' => 60,
            'inbound_media.attempt_deadline_seconds' => 30,
            'inbound_media.reservation_ttl_buffer_seconds' => 30,
            'inbound_media.observability.attachment_scan_limit' => 5000,
            'inbound_media.observability.orphan_scan_limit' => 5000,
            'inbound_media.traffic.channel_daily_limit_bytes' => 100,
        ]);
    }

    public function test_json_schema_is_stable_and_clean_snapshot_succeeds(): void
    {
        [$exitCode, $payload, $output] = $this->runSnapshot();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([
            'schema_version',
            'snapshot_at',
            'window_minutes',
            'complete',
            'incomplete_reasons',
            'current',
            'window',
            'blocking_anomalies',
        ], array_keys($payload));
        $this->assertSame([
            'queue_age_seconds_max',
            'active_leases',
            'stale_leases',
            'unresolved_cleanup_failure_count',
            'orphan_count',
            'orphan_scan_truncated',
            'storage_ledger_drift',
            'traffic_ledger_drift',
            'traffic_channels',
        ], array_keys($payload['current']));
        $this->assertSame([
            'throughput_bytes',
            'successful_downloads',
            'retry_count',
            'provider_error_count',
            'quota_denial_count',
            'integrity_mismatch_count',
            'cleanup_failure_count',
            'storage_bytes',
            'traffic_bytes',
        ], array_keys($payload['window']));
        $this->assertSame(['reserved', 'used', 'released'], array_keys($payload['window']['storage_bytes']));
        $this->assertSame(['reserved', 'consumed', 'released'], array_keys($payload['window']['traffic_bytes']));
        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame(60, $payload['window_minutes']);
        $this->assertTrue($payload['complete']);
        $this->assertSame([], $payload['incomplete_reasons']);
        $this->assertSame(0, $payload['current']['storage_ledger_drift']);
        $this->assertSame([], $payload['blocking_anomalies']);
        $this->assertJson($output);
        $this->assertStringNotContainsString('claim-token', $output);
        $this->assertStringNotContainsString(MessageAttachment::LOCAL_PATH_PREFIX.'/', $output);
    }

    public function test_window_and_current_metrics_respect_frozen_time_and_window_boundaries(): void
    {
        $this->travelTo('2026-07-17 12:00:00');
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $snapshotAt = now()->toImmutable();
        $this->travelTo($snapshotAt->subMinutes(5));
        $pending = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'media_download_next_retry_at' => null,
        ]);
        $this->travelTo($snapshotAt);
        $freshLease = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claimed_at' => now()->subMinute(),
            'media_download_heartbeat_at' => now()->subMinute(),
            'media_download_attempt_deadline_at' => now()->addHour(),
        ]);
        $staleLease = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claimed_at' => now()->subMinutes(10),
            'media_download_heartbeat_at' => now()->subMinutes(10),
            'media_download_attempt_deadline_at' => now()->addHour(),
        ]);
        DB::table('message_attachments')->where('id', $pending->id)->update([
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
        $this->insertTransition($pending, now()->subMinutes(5), [
            'action' => 'download_queued',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
        ]);
        $this->insertTransition($pending, now()->subMinutes(60), [
            'action' => 'download_succeeded',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'actual_bytes' => 50,
        ]);
        $this->insertTransition($pending, now()->subMinutes(60)->subSecond(), [
            'action' => 'download_succeeded',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'actual_bytes' => 999,
        ]);
        $this->insertTransition($pending, now()->subMinutes(50), [
            'action' => 'download_queued',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'safe_reason' => 'network_error',
        ]);
        $this->insertTransition($pending, now()->subMinutes(45), [
            'action' => 'download_failed',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_reason' => 'retries_exhausted',
        ]);
        $this->insertTransition($pending, now()->subMinutes(40), [
            'action' => 'available_on_demand',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            'safe_reason' => 'traffic_quota_exceeded',
        ]);
        $this->insertTransition($pending, now()->subMinutes(30), [
            'action' => 'download_queued',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'safe_reason' => 'integrity_mismatch',
        ]);
        $this->insertTransition($staleLease, now()->subMinutes(20), [
            'action' => 'download_queued',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'safe_reason' => 'lease_expired',
        ]);
        $this->insertTransition($staleLease, now()->subMinutes(15), [
            'action' => 'download_failed',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_reason' => 'retries_exhausted',
        ]);
        DB::table('media_download_traffic_ledgers')->insert([
            'message_attachment_id' => $pending->id,
            'channel_id' => $channel->id,
            'generation' => 1,
            'attempt_number' => 1,
            'period_date' => now()->toDateString(),
            'status' => 'consumed',
            'reserved_bytes' => 100,
            'consumed_bytes' => 60,
            'checkpoint_bytes' => 60,
            'release_reason' => 'network_error',
            'expires_at' => null,
            'released_at' => now()->subMinutes(45),
            'created_at' => now()->subMinutes(55),
            'updated_at' => now()->subMinutes(45),
        ]);
        DB::table('media_download_traffic_budgets')->insert([
            'channel_id' => $channel->id,
            'period_date' => now()->toDateString(),
            'reserved_bytes' => 0,
            'consumed_bytes' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedEmptyStorageBudget();
        $this->insertCleanupFailure(now()->subMinutes(20));
        $this->insertUnrelatedFailureContainingCleanupClassName(now()->subMinutes(15));

        [$exitCode, $payload] = $this->runSnapshot();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(300, $payload['current']['queue_age_seconds_max']);
        $this->assertSame(2, $payload['current']['active_leases']);
        $this->assertSame(1, $payload['current']['stale_leases']);
        $this->assertSame(1, $payload['current']['unresolved_cleanup_failure_count']);
        $this->assertSame(50, $payload['window']['throughput_bytes']);
        $this->assertSame(1, $payload['window']['successful_downloads']);
        $this->assertSame(3, $payload['window']['retry_count']);
        $this->assertSame(2, $payload['window']['provider_error_count']);
        $this->assertSame(1, $payload['window']['quota_denial_count']);
        $this->assertSame(1, $payload['window']['integrity_mismatch_count']);
        $this->assertSame(1, $payload['window']['cleanup_failure_count']);
        $this->assertSame(['reserved' => 0, 'used' => 50, 'released' => 0], $payload['window']['storage_bytes']);
        $this->assertSame(['reserved' => 100, 'consumed' => 60, 'released' => 40], $payload['window']['traffic_bytes']);
        $this->assertContains('stale_lease', $payload['blocking_anomalies']);
        $this->assertContains('unresolved_cleanup_failure', $payload['blocking_anomalies']);
        $this->assertNotSame($freshLease->id, $staleLease->id);
    }

    public function test_terminal_retry_classification_uses_the_final_attempt_ledger_reason(): void
    {
        $this->travelTo('2026-07-17 12:00:00');
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $providerTerminal = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
        ]);
        $this->insertTransition($providerTerminal, now()->subMinutes(11), [
            'action' => 'download_queued',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'safe_reason' => 'lease_expired',
        ]);
        DB::table('media_download_traffic_ledgers')->insert([
            'message_attachment_id' => $providerTerminal->id,
            'channel_id' => $channel->id,
            'generation' => 1,
            'attempt_number' => 5,
            'period_date' => now()->toDateString(),
            'status' => 'released',
            'reserved_bytes' => 0,
            'consumed_bytes' => 0,
            'checkpoint_bytes' => 0,
            'release_reason' => 'retries_exhausted',
            'expires_at' => null,
            'released_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);
        $this->insertTransition($providerTerminal, now()->subMinutes(10), [
            'action' => 'download_failed',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_reason' => 'retries_exhausted',
        ]);
        DB::table('media_download_traffic_budgets')->insert([
            'channel_id' => $channel->id,
            'period_date' => now()->toDateString(),
            'reserved_bytes' => 0,
            'consumed_bytes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedEmptyStorageBudget();

        [, $providerPayload] = $this->runSnapshot();

        $this->assertSame(1, $providerPayload['window']['provider_error_count']);

        $leaseTerminal = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
        ]);
        $this->insertTransition($leaseTerminal, now()->subMinute(), [
            'action' => 'download_queued',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'safe_reason' => 'network_error',
        ]);
        DB::table('media_download_traffic_ledgers')->insert([
            'message_attachment_id' => $leaseTerminal->id,
            'channel_id' => $channel->id,
            'generation' => 1,
            'attempt_number' => 5,
            'period_date' => now()->toDateString(),
            'status' => 'released',
            'reserved_bytes' => 0,
            'consumed_bytes' => 0,
            'checkpoint_bytes' => 0,
            'release_reason' => 'lease_expired',
            'expires_at' => null,
            'released_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertTransition($leaseTerminal, now(), [
            'action' => 'download_failed',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_reason' => 'retries_exhausted',
        ]);

        [, $leasePayload] = $this->runSnapshot(5);

        $this->assertSame(1, $leasePayload['window']['provider_error_count']);
    }

    public function test_provider_error_count_includes_arbitrary_telegram_account_gateway_errors(): void
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $retryableProviderFailure = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
        ]);
        $terminalProviderFailure = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
        ]);
        $this->insertTransition($retryableProviderFailure, now(), [
            'action' => 'download_queued',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'safe_reason' => 'tdlib_download_timeout',
            'transport' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
        ]);
        $this->insertTransition($terminalProviderFailure, now(), [
            'action' => 'download_failed',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_reason' => 'telegram_file_not_found',
            'transport' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
        ]);
        $this->seedEmptyStorageBudget();

        [, $payload] = $this->runSnapshot();

        $this->assertSame(2, $payload['window']['provider_error_count']);
    }

    public function test_provider_error_count_excludes_telegram_account_app_failures(): void
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $integrityFailure = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
        ]);
        $leaseFailure = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
        ]);
        $uploadTargetFailure = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
        ]);
        $this->insertTransition($integrityFailure, now(), [
            'action' => 'download_failed',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_reason' => 'integrity_mismatch',
            'transport' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
        ]);
        $this->insertTransition($leaseFailure, now(), [
            'action' => 'download_queued',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'safe_reason' => 'lease_expired',
            'transport' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
        ]);
        $this->insertTransition($uploadTargetFailure, now(), [
            'action' => 'download_queued',
            'old_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'new_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'safe_reason' => 'upload_target_unavailable',
            'transport' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
        ]);
        $this->seedEmptyStorageBudget();

        [, $payload] = $this->runSnapshot();

        $this->assertSame(0, $payload['window']['provider_error_count']);
        $this->assertSame(1, $payload['window']['integrity_mismatch_count']);
    }

    public function test_traffic_utilization_uses_reserved_plus_consumed_and_safe_nulls(): void
    {
        $this->travelTo('2026-07-17 12:00:00');
        $first = Channel::factory()->create();
        $second = Channel::factory()->create();
        DB::table('media_download_traffic_budgets')->insert([
            [
                'channel_id' => $first->id,
                'period_date' => now()->toDateString(),
                'reserved_bytes' => 30,
                'consumed_bytes' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'channel_id' => $second->id,
                'period_date' => now()->toDateString(),
                'reserved_bytes' => 1,
                'consumed_bytes' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $this->seedEmptyStorageBudget();

        [, $payload] = $this->runSnapshot();
        $channels = $payload['current']['traffic_channels'];
        $channelsById = collect($channels)->keyBy('channel_id');
        $firstChannel = $channelsById->get($first->id);
        $secondChannel = $channelsById->get($second->id);

        $this->assertIsArray($firstChannel);
        $this->assertIsArray($secondChannel);
        $this->assertSame(80.0, $firstChannel['utilization_percent']);
        $this->assertTrue($firstChannel['warning_80_reached']);
        $this->assertFalse($firstChannel['over_limit']);
        $this->assertSame(101.0, $secondChannel['utilization_percent']);
        $this->assertTrue($secondChannel['warning_80_reached']);
        $this->assertTrue($secondChannel['over_limit']);

        config(['inbound_media.traffic.channel_daily_limit_bytes' => null]);
        [, $withoutLimit] = $this->runSnapshot();
        $channelWithoutLimit = collect($withoutLimit['current']['traffic_channels'])
            ->firstWhere('channel_id', $first->id);

        $this->assertIsArray($channelWithoutLimit);
        $this->assertNull($channelWithoutLimit['daily_limit_bytes']);
        $this->assertNull($channelWithoutLimit['utilization_percent']);
        $this->assertNull($channelWithoutLimit['warning_80_reached']);
        $this->assertNull($channelWithoutLimit['over_limit']);
    }

    public function test_snapshot_does_not_mutate_application_state(): void
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'file_size_bytes' => 4,
            'media_download_generation' => 1,
        ]);
        $path = MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/read-only.bin';
        DB::table('message_attachments')->where('id', $attachment->id)->update([
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => $path,
        ]);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($path, 'data');
        DB::table('media_download_storage_ledgers')->insert([
            'message_attachment_id' => $attachment->id,
            'channel_id' => $channel->id,
            'generation' => 1,
            'status' => 'used',
            'reserved_bytes' => 4,
            'used_bytes' => 4,
            'release_reason' => null,
            'expires_at' => null,
            'released_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedStorageBudgets($channel->id, 4);
        Cache::forever(ReconcileInboundMediaStorageAction::ATTACHMENT_CURSOR_CACHE_KEY, 123);
        Cache::forever(ReconcileInboundMediaStorageAction::ORPHAN_CURSOR_CACHE_KEY, 'sentinel');
        $before = $this->databaseState();
        $beforeFiles = Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->allFiles();
        $writeQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$writeQueries): void {
            if (preg_match('/\b(insert|update|delete)\b|lock\s+table|for\s+update/i', $query->sql) === 1) {
                $writeQueries[] = $query->sql;
            }
        });

        [$exitCode] = $this->runSnapshot();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([], $writeQueries);
        $this->assertSame($before, $this->databaseState());
        $this->assertSame($beforeFiles, Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->allFiles());
        $this->assertSame('data', Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->get($path));
        $this->assertSame(123, Cache::get(ReconcileInboundMediaStorageAction::ATTACHMENT_CURSOR_CACHE_KEY));
        $this->assertSame('sentinel', Cache::get(ReconcileInboundMediaStorageAction::ORPHAN_CURSOR_CACHE_KEY));
    }

    public function test_storage_listing_failure_is_incomplete_and_does_not_mutate_state(): void
    {
        $this->seedEmptyStorageBudget();
        $storage = Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE);
        $path = MessageAttachment::LOCAL_PATH_PREFIX.'/read-only/sentinel.bin';
        $storage->put($path, 'unchanged');
        Cache::forever(ReconcileInboundMediaStorageAction::ATTACHMENT_CURSOR_CACHE_KEY, 321);
        Cache::forever(ReconcileInboundMediaStorageAction::ORPHAN_CURSOR_CACHE_KEY, 'unchanged');
        $before = $this->databaseState();
        $beforeFiles = $storage->allFiles();
        $writeQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$writeQueries): void {
            if (preg_match('/\b(insert|update|delete)\b|lock\s+table|for\s+update/i', $query->sql) === 1) {
                $writeQueries[] = $query->sql;
            }
        });

        Storage::set(MessageAttachment::LOCAL_DISK_PRIVATE, new class
        {
            public function getDriver(): object
            {
                return new class
                {
                    public function listContents(string $path, bool $deep): never
                    {
                        throw new \RuntimeException('Safe test storage listing failure.');
                    }
                };
            }
        });

        [$exitCode, $payload] = $this->runSnapshot();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertFalse($payload['complete']);
        $this->assertSame(['storage_scan_failed'], $payload['incomplete_reasons']);
        $this->assertNull($payload['current']['orphan_count']);
        $this->assertFalse($payload['current']['orphan_scan_truncated']);
        $this->assertSame([], $writeQueries);
        $this->assertSame($before, $this->databaseState());
        $this->assertSame($beforeFiles, $storage->allFiles());
        $this->assertSame('unchanged', $storage->get($path));
        $this->assertSame(321, Cache::get(ReconcileInboundMediaStorageAction::ATTACHMENT_CURSOR_CACHE_KEY));
        $this->assertSame('unchanged', Cache::get(ReconcileInboundMediaStorageAction::ORPHAN_CURSOR_CACHE_KEY));
    }

    public function test_fresh_unreferenced_object_is_not_blocking_but_stale_orphan_is(): void
    {
        $this->seedEmptyStorageBudget();
        $storage = Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE);
        $freshPath = MessageAttachment::LOCAL_PATH_PREFIX.'/orphan/fresh.bin';
        $stalePath = MessageAttachment::LOCAL_PATH_PREFIX.'/orphan/stale.bin';
        $storage->put($freshPath, 'fresh');

        [$freshExitCode, $freshPayload] = $this->runSnapshot();

        $this->assertSame(Command::SUCCESS, $freshExitCode);
        $this->assertSame(0, $freshPayload['current']['orphan_count']);
        $this->assertNotContains('orphan_object', $freshPayload['blocking_anomalies']);

        $storage->put($stalePath, 'stale');
        touch($storage->path($stalePath), now()->subMinutes(2)->timestamp);

        [$staleExitCode, $stalePayload] = $this->runSnapshot();

        $this->assertSame(Command::FAILURE, $staleExitCode);
        $this->assertSame(1, $stalePayload['current']['orphan_count']);
        $this->assertContains('orphan_object', $stalePayload['blocking_anomalies']);
    }

    public function test_truncated_orphan_scan_is_incomplete_without_partial_count(): void
    {
        $this->seedEmptyStorageBudget();
        config(['inbound_media.observability.orphan_scan_limit' => 1]);
        $storage = Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE);
        $storage->put(MessageAttachment::LOCAL_PATH_PREFIX.'/orphan/a.bin', 'a');
        $storage->put(MessageAttachment::LOCAL_PATH_PREFIX.'/orphan/b.bin', 'b');
        Cache::forever(ReconcileInboundMediaStorageAction::ORPHAN_CURSOR_CACHE_KEY, 'unchanged');

        [$exitCode, $payload] = $this->runSnapshot();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertFalse($payload['complete']);
        $this->assertSame(['orphan_scan_truncated'], $payload['incomplete_reasons']);
        $this->assertTrue($payload['current']['orphan_scan_truncated']);
        $this->assertNull($payload['current']['orphan_count']);
        $this->assertSame('unchanged', Cache::get(ReconcileInboundMediaStorageAction::ORPHAN_CURSOR_CACHE_KEY));

        config(['inbound_media.observability.orphan_scan_limit' => 2]);

        [$recoveredExitCode, $recoveredPayload] = $this->runSnapshot();

        $this->assertSame(Command::SUCCESS, $recoveredExitCode);
        $this->assertTrue($recoveredPayload['complete']);
        $this->assertFalse($recoveredPayload['current']['orphan_scan_truncated']);
        $this->assertSame(0, $recoveredPayload['current']['orphan_count']);
    }

    public function test_storage_ledger_drift_is_a_named_blocker_with_failure_exit_code(): void
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
        ]);
        DB::table('media_download_storage_ledgers')->insert([
            'message_attachment_id' => $attachment->id,
            'channel_id' => $channel->id,
            'generation' => 1,
            'status' => 'reserved',
            'reserved_bytes' => 10,
            'used_bytes' => 0,
            'release_reason' => null,
            'expires_at' => now()->addHour(),
            'released_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$exitCode, $payload] = $this->runSnapshot();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertGreaterThan(0, $payload['current']['storage_ledger_drift']);
        $this->assertSame(0, $payload['current']['traffic_ledger_drift']);
        $this->assertContains('storage_ledger_drift', $payload['blocking_anomalies']);
    }

    public function test_truncated_inventory_keeps_a_known_stale_orphan_blocker(): void
    {
        $this->seedEmptyStorageBudget();
        config(['inbound_media.observability.orphan_scan_limit' => 1]);
        $storage = Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE);

        foreach (['first-stale.bin', 'second-stale.bin'] as $filename) {
            $path = MessageAttachment::LOCAL_PATH_PREFIX.'/orphan/'.$filename;
            $storage->put($path, 'stale');
            touch($storage->path($path), now()->subMinutes(2)->timestamp);
        }

        [$exitCode, $payload] = $this->runSnapshot();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertFalse($payload['complete']);
        $this->assertNull($payload['current']['orphan_count']);
        $this->assertContains('orphan_scan_truncated', $payload['incomplete_reasons']);
        $this->assertContains('orphan_object', $payload['blocking_anomalies']);
    }

    public function test_attachment_scan_limit_is_incomplete_and_can_be_raised_safely(): void
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);

        foreach (['first.bin', 'second.bin'] as $filename) {
            $attachment = MessageAttachment::factory()->create([
                'message_id' => $message->id,
                'channel_id' => $channel->id,
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
                'file_size_bytes' => 1,
                'media_download_generation' => 1,
            ]);
            $path = MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/'.$filename;
            DB::table('message_attachments')->where('id', $attachment->id)->update([
                'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
                'local_path' => $path,
            ]);
            Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($path, 'x');
            DB::table('media_download_storage_ledgers')->insert([
                'message_attachment_id' => $attachment->id,
                'channel_id' => $channel->id,
                'generation' => 1,
                'status' => 'used',
                'reserved_bytes' => 1,
                'used_bytes' => 1,
                'release_reason' => null,
                'expires_at' => null,
                'released_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->seedStorageBudgets($channel->id, 2);
        config(['inbound_media.observability.attachment_scan_limit' => 1]);

        [$limitedExitCode, $limitedPayload] = $this->runSnapshot();

        $this->assertSame(Command::FAILURE, $limitedExitCode);
        $this->assertFalse($limitedPayload['complete']);
        $this->assertContains('attachment_scan_truncated', $limitedPayload['incomplete_reasons']);
        $this->assertNull($limitedPayload['current']['orphan_count']);

        config(['inbound_media.observability.attachment_scan_limit' => 2]);

        [$recoveredExitCode, $recoveredPayload] = $this->runSnapshot();

        $this->assertSame(Command::SUCCESS, $recoveredExitCode);
        $this->assertTrue($recoveredPayload['complete']);
        $this->assertSame(0, $recoveredPayload['current']['storage_ledger_drift']);
        $this->assertSame(0, $recoveredPayload['current']['orphan_count']);
    }

    public function test_traffic_ledger_drift_is_a_named_blocker_with_failure_exit_code(): void
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
        ]);
        $this->seedEmptyStorageBudget();
        DB::table('media_download_traffic_ledgers')->insert([
            'message_attachment_id' => $attachment->id,
            'channel_id' => $channel->id,
            'generation' => 1,
            'attempt_number' => 1,
            'period_date' => now()->toDateString(),
            'status' => 'reserved',
            'reserved_bytes' => 10,
            'consumed_bytes' => 0,
            'checkpoint_bytes' => 0,
            'release_reason' => null,
            'expires_at' => now()->addHour(),
            'released_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$exitCode, $payload] = $this->runSnapshot();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(0, $payload['current']['storage_ledger_drift']);
        $this->assertGreaterThan(0, $payload['current']['traffic_ledger_drift']);
        $this->assertContains('traffic_ledger_drift', $payload['blocking_anomalies']);
    }

    public function test_window_requires_a_positive_integer(): void
    {
        $this->mock(
            BuildInboundMediaObservabilitySnapshotAction::class,
            fn (MockInterface $mock) => $mock->shouldReceive('handle')->never(),
        );

        foreach (['0', '-1', 'abc', '9223372036854775808'] as $window) {
            $exitCode = Artisan::call('media:observability-snapshot', [
                '--window' => $window,
                '--json' => true,
            ]);

            $this->assertSame(Command::INVALID, $exitCode, 'Window '.$window.' must be rejected.');
        }
    }

    /**
     * @return array{0:int,1:array<string,mixed>,2:string}
     */
    private function runSnapshot(int $window = 60): array
    {
        $exitCode = Artisan::call('media:observability-snapshot', [
            '--window' => (string) $window,
            '--json' => true,
        ]);
        $output = trim(Artisan::output());

        return [
            $exitCode,
            json_decode($output, true, flags: JSON_THROW_ON_ERROR),
            $output,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertTransition(
        MessageAttachment $attachment,
        mixed $createdAt,
        array $overrides = [],
    ): void {
        static $sequence = 0;
        $sequence++;

        DB::table('media_download_state_transitions')->insert(array_merge([
            'message_attachment_id' => $attachment->id,
            'channel_id' => $attachment->channel_id,
            'previous_transition_id' => null,
            'previous_generation' => null,
            'generation' => 1,
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => 'state_changed',
            'old_status' => null,
            'new_status' => null,
            'safe_reason' => null,
            'expected_bytes' => null,
            'actual_bytes' => null,
            'transport' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'correlation_id' => hash('sha256', 'snapshot-test-'.$sequence),
            'created_at' => $createdAt,
        ], $overrides));
    }

    private function insertCleanupFailure(mixed $failedAt): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\CleanupInboundMediaPartialFilesJob',
                'data' => ['commandName' => 'App\\Jobs\\CleanupInboundMediaPartialFilesJob'],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'safe test exception',
            'failed_at' => $failedAt,
        ]);
    }

    private function insertUnrelatedFailureContainingCleanupClassName(mixed $failedAt): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\UnrelatedJob',
                'data' => [
                    'commandName' => 'App\\Jobs\\UnrelatedJob',
                    'note' => 'CleanupInboundMediaPartialFilesJob',
                ],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'safe unrelated test exception',
            'failed_at' => $failedAt,
        ]);
    }

    private function seedEmptyStorageBudget(): void
    {
        DB::table('media_download_storage_budgets')->updateOrInsert(
            [
                'scope_type' => 'global',
                'scope_id' => 0,
            ],
            [
                'reserved_bytes' => 0,
                'used_bytes' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function seedStorageBudgets(int $channelId, int $usedBytes): void
    {
        foreach ([
            [
                'scope_type' => 'global',
                'scope_id' => 0,
            ],
            [
                'scope_type' => 'channel',
                'scope_id' => $channelId,
            ],
        ] as $scope) {
            DB::table('media_download_storage_budgets')->updateOrInsert(
                $scope,
                [
                    'reserved_bytes' => 0,
                    'used_bytes' => $usedBytes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /**
     * @return array<string, list<object>>
     */
    private function databaseState(): array
    {
        return collect([
            'message_attachments',
            'media_download_storage_budgets',
            'media_download_storage_ledgers',
            'media_download_traffic_budgets',
            'media_download_traffic_ledgers',
            'media_download_state_transitions',
            'jobs',
            'failed_jobs',
        ])->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ])->all();
    }
}
