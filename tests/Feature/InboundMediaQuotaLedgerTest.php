<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\MediaDownloadStorageLedger;
use App\Models\MediaDownloadTrafficLedger;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Messages\InboundMediaDownloadPolicy;
use App\Services\Messages\InboundMediaQuotaLedger;
use App\Services\Messages\InboundMediaStorageCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery\MockInterface;
use Tests\TestCase;

class InboundMediaQuotaLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_media.storage.enforce' => true,
            'inbound_media.storage.global_limit_bytes' => 1_000,
            'inbound_media.storage.channel_limit_bytes' => 1_000,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
            'inbound_media.traffic.enforce' => true,
            'inbound_media.traffic.channel_daily_limit_bytes' => 1_000,
        ]);
    }

    public function test_reservation_and_success_atomically_move_storage_and_traffic_usage(): void
    {
        $attachment = $this->createAttachment(100);
        $ledger = app(InboundMediaQuotaLedger::class);

        $decision = $ledger->reserveForAttempt($attachment, 1);

        $this->assertTrue($decision->allowed);
        $this->assertSame(100, $decision->storageReservedBytes);
        $this->assertSame(100, $decision->trafficReservedBytes);
        $this->assertBudget('media_download_storage_budgets', ['scope_type' => 'global'], 100, 0);
        $this->assertBudget(
            'media_download_storage_budgets',
            ['scope_type' => 'channel', 'scope_id' => $attachment->channel_id],
            100,
            0,
        );
        $this->assertBudget(
            'media_download_traffic_budgets',
            ['channel_id' => $attachment->channel_id],
            100,
            0,
            'consumed_bytes',
        );

        $replayedDecision = $ledger->reserveForAttempt($attachment, 1);

        $this->assertTrue($replayedDecision->allowed);
        $this->assertSame(1, MediaDownloadStorageLedger::query()->count());
        $this->assertSame(1, MediaDownloadTrafficLedger::query()->count());
        $this->assertBudget('media_download_storage_budgets', ['scope_type' => 'global'], 100, 0);

        $ledger->completeAttempt($attachment, 1, 80);
        $ledger->completeAttempt($attachment, 1, 80);

        $storage = MediaDownloadStorageLedger::query()->firstOrFail();
        $traffic = MediaDownloadTrafficLedger::query()->firstOrFail();

        $this->assertSame(MediaDownloadStorageLedger::STATUS_USED, $storage->status);
        $this->assertSame(100, $storage->reserved_bytes);
        $this->assertSame(80, $storage->used_bytes);
        $this->assertSame(MediaDownloadTrafficLedger::STATUS_CONSUMED, $traffic->status);
        $this->assertSame(80, $traffic->consumed_bytes);
        $this->assertBudget('media_download_storage_budgets', ['scope_type' => 'global'], 0, 80);
        $this->assertBudget(
            'media_download_traffic_budgets',
            ['channel_id' => $attachment->channel_id],
            0,
            80,
            'consumed_bytes',
        );
    }

    public function test_failed_attempt_uses_durable_checkpoint_when_caller_does_not_report_transferred_bytes(): void
    {
        $attachment = $this->createAttachment(100);
        $ledger = app(InboundMediaQuotaLedger::class);

        $ledger->reserveForAttempt($attachment, 1);
        $ledger->checkpointTraffic($attachment, 1, 40);
        $ledger->failAttempt($attachment, 1, 0, 'network_timeout');

        $storage = MediaDownloadStorageLedger::query()->firstOrFail();
        $traffic = MediaDownloadTrafficLedger::query()->firstOrFail();

        $this->assertSame(MediaDownloadStorageLedger::STATUS_RELEASED, $storage->status);
        $this->assertSame('network_timeout', $storage->release_reason);
        $this->assertSame(MediaDownloadTrafficLedger::STATUS_CONSUMED, $traffic->status);
        $this->assertSame(40, $traffic->checkpoint_bytes);
        $this->assertSame(40, $traffic->consumed_bytes);
        $this->assertBudget('media_download_storage_budgets', ['scope_type' => 'global'], 0, 0);
        $this->assertBudget(
            'media_download_traffic_budgets',
            ['channel_id' => $attachment->channel_id],
            0,
            40,
            'consumed_bytes',
        );
    }

    public function test_success_reconciles_provider_size_understatement_to_actual_bytes(): void
    {
        $attachment = $this->createAttachment(100);
        $ledger = app(InboundMediaQuotaLedger::class);

        $ledger->reserveForAttempt($attachment, 1);
        $ledger->completeAttempt($attachment, 1, 120);

        $storage = MediaDownloadStorageLedger::query()->firstOrFail();
        $traffic = MediaDownloadTrafficLedger::query()->firstOrFail();

        $this->assertSame(MediaDownloadStorageLedger::STATUS_USED, $storage->status);
        $this->assertSame(120, $storage->reserved_bytes);
        $this->assertSame(120, $storage->used_bytes);
        $this->assertSame(MediaDownloadTrafficLedger::STATUS_CONSUMED, $traffic->status);
        $this->assertSame(120, $traffic->reserved_bytes);
        $this->assertSame(120, $traffic->consumed_bytes);
        $this->assertBudget('media_download_storage_budgets', ['scope_type' => 'global'], 0, 120);
        $this->assertBudget(
            'media_download_traffic_budgets',
            ['channel_id' => $attachment->channel_id],
            0,
            120,
            'consumed_bytes',
        );
    }

    public function test_storage_enforcement_denies_without_leaking_reservations(): void
    {
        config(['inbound_media.storage.global_limit_bytes' => 50]);
        $attachment = $this->createAttachment(100);

        $decision = app(InboundMediaQuotaLedger::class)->reserveForAttempt($attachment, 1);

        $this->assertFalse($decision->allowed);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED, $decision->reason);
        $this->assertSame(0, MediaDownloadStorageLedger::query()->count());
        $this->assertSame(0, MediaDownloadTrafficLedger::query()->count());
        $this->assertBudget('media_download_storage_budgets', ['scope_type' => 'global'], 0, 0);
    }

    public function test_shadow_mode_records_usage_and_reports_the_reason_without_denial(): void
    {
        config([
            'inbound_media.storage.enforce' => false,
            'inbound_media.storage.global_limit_bytes' => 50,
        ]);
        $attachment = $this->createAttachment(100);

        $decision = app(InboundMediaQuotaLedger::class)->reserveForAttempt($attachment, 1);

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->reason);
        $this->assertSame(
            InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED,
            $decision->shadowReason,
        );
        $this->assertSame(1, MediaDownloadStorageLedger::query()->count());
        $this->assertSame(1, MediaDownloadTrafficLedger::query()->count());
        $this->assertBudget('media_download_storage_budgets', ['scope_type' => 'global'], 100, 0);
    }

    public function test_traffic_enforcement_denies_known_file_before_transfer(): void
    {
        config(['inbound_media.traffic.channel_daily_limit_bytes' => 50]);
        $attachment = $this->createAttachment(100);

        $decision = app(InboundMediaQuotaLedger::class)->reserveForAttempt($attachment, 1);

        $this->assertFalse($decision->allowed);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED, $decision->reason);
        $this->assertSame(0, MediaDownloadStorageLedger::query()->count());
        $this->assertSame(0, MediaDownloadTrafficLedger::query()->count());
        $this->assertBudget(
            'media_download_traffic_budgets',
            ['channel_id' => $attachment->channel_id],
            0,
            0,
            'consumed_bytes',
        );
    }

    public function test_shadow_unknown_size_with_zero_remaining_keeps_a_zero_reservation_for_checkpoints(): void
    {
        config([
            'inbound_media.traffic.enforce' => false,
            'inbound_media.traffic.channel_daily_limit_bytes' => 0,
        ]);
        $attachment = $this->createAttachment(null);
        $ledger = app(InboundMediaQuotaLedger::class);

        $decision = $ledger->reserveForAttempt($attachment, 1);

        $this->assertTrue($decision->allowed);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED, $decision->shadowReason);

        $traffic = MediaDownloadTrafficLedger::query()->firstOrFail();
        $this->assertSame(MediaDownloadTrafficLedger::STATUS_RESERVED, $traffic->status);
        $this->assertSame(0, $traffic->reserved_bytes);

        $ledger->checkpointTraffic($attachment, 1, 25);
        $ledger->failAttempt($attachment, 1, 25, 'network_timeout');

        $traffic->refresh();
        $this->assertSame(MediaDownloadTrafficLedger::STATUS_CONSUMED, $traffic->status);
        $this->assertSame(25, $traffic->consumed_bytes);
        $this->assertBudget(
            'media_download_traffic_budgets',
            ['channel_id' => $attachment->channel_id],
            0,
            25,
            'consumed_bytes',
        );
    }

    public function test_physical_free_space_accounts_for_existing_unfinished_reservations(): void
    {
        $this->mock(InboundMediaStorageCapacity::class, function (MockInterface $mock): void {
            $mock->shouldReceive('availableBytes')
                ->twice()
                ->andReturn(150);
        });
        $ledger = app(InboundMediaQuotaLedger::class);
        $first = $this->createAttachment(100);
        $second = $this->createAttachment(100);

        $this->assertTrue($ledger->reserveForAttempt($first, 1)->allowed);

        $decision = $ledger->reserveForAttempt($second, 1);

        $this->assertFalse($decision->allowed);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED, $decision->reason);
        $this->assertSame(1, MediaDownloadStorageLedger::query()->count());
    }

    public function test_s3_storage_enforcement_uses_logical_limits_without_host_disk_probe(): void
    {
        config([
            'filesystems.message_attachments_disk' => 'quota_s3',
            'filesystems.disks.quota_s3' => [
                'driver' => 's3',
            ],
            'inbound_media.storage.global_limit_bytes' => 1_000,
            'inbound_media.storage.channel_limit_bytes' => 1_000,
            'inbound_media.storage.minimum_free_bytes' => 10 * 1024 * 1024 * 1024,
            'inbound_media.storage.minimum_free_percent' => 10,
        ]);
        $ledger = app(InboundMediaQuotaLedger::class);
        $first = $this->createAttachment(100);

        $this->assertTrue($ledger->reserveForAttempt($first, 1)->allowed);

        config(['inbound_media.storage.global_limit_bytes' => 150]);
        $second = $this->createAttachment(100);
        $decision = $ledger->reserveForAttempt($second, 1);

        $this->assertFalse($decision->allowed);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED, $decision->reason);
        $this->assertSame(1, MediaDownloadStorageLedger::query()->count());
    }

    public function test_preview_snapshot_reuses_budget_rows_and_physical_capacity_for_same_channel(): void
    {
        $this->mock(InboundMediaStorageCapacity::class, function (MockInterface $mock): void {
            $mock->shouldReceive('availableBytes')
                ->once()
                ->andReturn(10_000);
        });
        $first = $this->createAttachment(100);
        $second = MessageAttachment::factory()->create([
            'message_id' => $first->message_id,
            'channel_id' => $first->channel_id,
            'file_size_bytes' => 100,
            'media_download_max_bytes' => 500,
            'media_download_generation' => 1,
            'media_download_attempts' => 0,
        ]);
        $ledger = app(InboundMediaQuotaLedger::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $decisions = $ledger->withPreviewSnapshot(fn (): array => [
            $ledger->previewForAttempt($first),
            $ledger->previewForAttempt($second),
        ]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($decisions[0]->allowed);
        $this->assertTrue($decisions[1]->allowed);
        $this->assertCount(2, array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], 'media_download_storage_budgets'),
        ));
        $this->assertCount(1, array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], 'media_download_traffic_budgets'),
        ));
    }

    public function test_used_storage_is_released_only_after_stable_object_is_cleared(): void
    {
        $attachment = $this->createAttachment(100);
        $ledger = app(InboundMediaQuotaLedger::class);
        $ledger->reserveForAttempt($attachment, 1);
        $ledger->completeAttempt($attachment, 1, 80);

        $attachment->forceFill([
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => 'message-attachments/test.bin',
        ]);

        $this->expectException(LogicException::class);
        $ledger->releaseUsedStorageAfterDeletion($attachment);
    }

    public function test_used_storage_release_updates_both_budgets(): void
    {
        $attachment = $this->createAttachment(100);
        $ledger = app(InboundMediaQuotaLedger::class);
        $ledger->reserveForAttempt($attachment, 1);
        $ledger->completeAttempt($attachment, 1, 80);

        $attachment->forceFill([
            'local_disk' => null,
            'local_path' => null,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL,
        ])->save();

        $ledger->releaseUsedStorageAfterDeletion($attachment->fresh());

        $storage = MediaDownloadStorageLedger::query()->firstOrFail();
        $this->assertSame(MediaDownloadStorageLedger::STATUS_RELEASED, $storage->status);
        $this->assertSame('retention_deleted', $storage->release_reason);
        $this->assertBudget('media_download_storage_budgets', ['scope_type' => 'global'], 0, 0);
        $this->assertBudget(
            'media_download_storage_budgets',
            ['scope_type' => 'channel', 'scope_id' => $attachment->channel_id],
            0,
            0,
        );
    }

    private function createAttachment(?int $fileSizeBytes): MessageAttachment
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);

        return MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'file_size_bytes' => $fileSizeBytes,
            'media_download_max_bytes' => 500,
            'media_download_generation' => 1,
            'media_download_attempts' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function assertBudget(
        string $table,
        array $where,
        int $reservedBytes,
        int $otherBytes,
        string $otherColumn = 'used_bytes',
    ): void {
        $budget = DB::table($table)->where($where)->first();

        $this->assertNotNull($budget);
        $this->assertSame($reservedBytes, (int) $budget->reserved_bytes);
        $this->assertSame($otherBytes, (int) $budget->{$otherColumn});
    }
}
