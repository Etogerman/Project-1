<?php

namespace Tests\Feature;

use App\Jobs\CleanupInboundMediaPartialFilesJob;
use App\Jobs\DownloadBotMessageAttachmentJob;
use App\Models\Channel;
use App\Models\MediaDownloadStorageLedger;
use App\Models\MediaDownloadTrafficLedger;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Messages\DeleteInboundMediaPartialFilesAction;
use App\Services\Messages\InboundMediaQuotaLedger;
use App\Services\Messages\ReapStaleInboundMediaDownloadsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboundMediaLeaseReaperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config([
            'inbound_media.lease_stale_seconds' => 120,
            'inbound_media.max_attempts' => 5,
            'inbound_media.retry_delays_seconds' => [60, 300, 900, 3600, 10800],
            'inbound_media.storage.enforce' => true,
            'inbound_media.storage.global_limit_bytes' => 10_000,
            'inbound_media.storage.channel_limit_bytes' => 10_000,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
            'inbound_media.traffic.enforce' => true,
            'inbound_media.traffic.channel_daily_limit_bytes' => 10_000,
        ]);
    }

    public function test_fresh_lease_is_not_reaped(): void
    {
        $attachment = $this->createDownloadingAttachment(
            heartbeatAt: now()->subSeconds(30),
        );

        $stats = app(ReapStaleInboundMediaDownloadsAction::class)->handle();

        $this->assertSame(0, $stats['inspected']);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING, $attachment->fresh()->download_status);
        $this->assertSame(
            MediaDownloadStorageLedger::STATUS_RESERVED,
            MediaDownloadStorageLedger::query()->firstOrFail()->status,
        );
        $this->assertSame(
            MediaDownloadTrafficLedger::STATUS_RESERVED,
            MediaDownloadTrafficLedger::query()->firstOrFail()->status,
        );
    }

    public function test_stale_lease_deletes_partial_file_releases_reservations_and_schedules_retry(): void
    {
        $attachment = $this->createDownloadingAttachment(
            heartbeatAt: now()->subSeconds(121),
        );
        $partialPath = $this->partialPath($attachment);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($partialPath, 'partial-bytes');
        app(InboundMediaQuotaLedger::class)->checkpointTraffic($attachment, 1, 40);

        $stats = app(ReapStaleInboundMediaDownloadsAction::class)->handle();
        $attachment->refresh();

        $this->assertSame(1, $stats['retried']);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $attachment->download_status);
        $this->assertSame(ReapStaleInboundMediaDownloadsAction::ERROR_LEASE_EXPIRED, $attachment->safe_error_code);
        $this->assertNotNull($attachment->media_download_next_retry_at);
        $this->assertNull($attachment->media_download_claim_token);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($partialPath);
        $this->assertSame(
            MediaDownloadStorageLedger::STATUS_RELEASED,
            MediaDownloadStorageLedger::query()->firstOrFail()->status,
        );
        $traffic = MediaDownloadTrafficLedger::query()->firstOrFail();
        $this->assertSame(MediaDownloadTrafficLedger::STATUS_CONSUMED, $traffic->status);
        $this->assertSame(40, $traffic->consumed_bytes);
    }

    public function test_stale_lease_counts_unreported_upload_bytes_before_cleanup(): void
    {
        $attachment = $this->createDownloadingAttachment(
            provider: MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            heartbeatAt: now()->subSeconds(121),
        );
        $uploadPath = MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->id
            .'.g1.claim-token.upload';
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($uploadPath, str_repeat('x', 73));

        app(ReapStaleInboundMediaDownloadsAction::class)->handle();

        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($uploadPath);
        $traffic = MediaDownloadTrafficLedger::query()->firstOrFail();
        $this->assertSame(MediaDownloadTrafficLedger::STATUS_CONSUMED, $traffic->status);
        $this->assertSame(73, $traffic->consumed_bytes);
    }

    public function test_stale_lease_deletes_unadopted_commit_candidate_before_releasing_quota(): void
    {
        $attachment = $this->createDownloadingAttachment(
            provider: MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            heartbeatAt: now()->subSeconds(121),
        );
        $commitPath = MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->id
            .'.g1.claim-token.commit.orphan.mp4';
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($commitPath, str_repeat('x', 73));

        app(ReapStaleInboundMediaDownloadsAction::class)->handle();

        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($commitPath);
        $this->assertSame(
            MediaDownloadStorageLedger::STATUS_RELEASED,
            MediaDownloadStorageLedger::query()->firstOrFail()->status,
        );
        $traffic = MediaDownloadTrafficLedger::query()->firstOrFail();
        $this->assertSame(MediaDownloadTrafficLedger::STATUS_CONSUMED, $traffic->status);
        $this->assertSame(73, $traffic->consumed_bytes);
    }

    public function test_stale_lease_does_not_double_count_upload_and_commit_candidate_bytes(): void
    {
        $attachment = $this->createDownloadingAttachment(
            provider: MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            heartbeatAt: now()->subSeconds(121),
        );
        $directory = MessageAttachment::LOCAL_PATH_PREFIX.'/'.$attachment->message_id.'/';
        $uploadPath = $directory.$attachment->id.'.g1.claim-token.upload';
        $commitPath = $directory.$attachment->id.'.g1.claim-token.commit.orphan.mp4';
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($uploadPath, str_repeat('x', 73));
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($commitPath, str_repeat('x', 73));

        app(ReapStaleInboundMediaDownloadsAction::class)->handle();

        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($uploadPath);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($commitPath);
        $traffic = MediaDownloadTrafficLedger::query()->firstOrFail();
        $this->assertSame(MediaDownloadTrafficLedger::STATUS_CONSUMED, $traffic->status);
        $this->assertSame(73, $traffic->consumed_bytes);
    }

    public function test_fifth_stale_attempt_becomes_terminal_failure(): void
    {
        $attachment = $this->createDownloadingAttachment(
            attempts: 5,
            heartbeatAt: now()->subSeconds(121),
        );

        $stats = app(ReapStaleInboundMediaDownloadsAction::class)->handle();
        $attachment->refresh();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $attachment->download_status);
        $this->assertSame('retries_exhausted', $attachment->safe_error_code);
        $this->assertNull($attachment->media_download_next_retry_at);
    }

    public function test_cleanup_failure_revokes_lease_and_is_retried_without_releasing_quota_early(): void
    {
        Queue::fake();
        $attachment = $this->createDownloadingAttachment(
            heartbeatAt: now()->subSeconds(121),
        );
        $cleanup = $this->mock(DeleteInboundMediaPartialFilesAction::class);
        $cleanup->shouldReceive('scopedBytes')
            ->twice()
            ->andReturn(0);
        $cleanup->shouldReceive('handle')
            ->twice()
            ->andReturn(false, true);
        $reaper = app(ReapStaleInboundMediaDownloadsAction::class);

        $firstStats = $reaper->handle();
        $attachment->refresh();

        $this->assertSame(1, $firstStats['cleanup_failed']);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING, $attachment->download_status);
        $this->assertStringStartsWith('revoked-', (string) $attachment->media_download_claim_token);
        $this->assertSame(
            MediaDownloadStorageLedger::STATUS_RESERVED,
            MediaDownloadStorageLedger::query()->firstOrFail()->status,
        );
        Queue::assertPushed(
            CleanupInboundMediaPartialFilesJob::class,
            fn (CleanupInboundMediaPartialFilesJob $job): bool => $job->attachmentId === $attachment->id,
        );

        $secondStats = $reaper->handle();
        $attachment->refresh();

        $this->assertSame(1, $secondStats['retried']);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $attachment->download_status);
        $this->assertSame(
            MediaDownloadStorageLedger::STATUS_RELEASED,
            MediaDownloadStorageLedger::query()->firstOrFail()->status,
        );
    }

    public function test_manual_bot_retry_is_dispatched_after_stale_lease_is_reaped(): void
    {
        Queue::fake();
        $attachment = $this->createDownloadingAttachment(
            manual: true,
            heartbeatAt: now()->subSeconds(121),
        );

        app(ReapStaleInboundMediaDownloadsAction::class)->handle();

        Queue::assertPushed(
            DownloadBotMessageAttachmentJob::class,
            fn (DownloadBotMessageAttachmentJob $job): bool => $job->attachmentId === $attachment->id,
        );
    }

    public function test_legacy_telegram_account_claim_without_token_is_left_to_compatibility_timeout(): void
    {
        $attachment = $this->createDownloadingAttachment(
            provider: MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            claimToken: null,
            heartbeatAt: now()->subMinutes(20),
        );

        $stats = app(ReapStaleInboundMediaDownloadsAction::class)->handle();

        $this->assertSame(0, $stats['inspected']);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING, $attachment->fresh()->download_status);
    }

    private function createDownloadingAttachment(
        string $provider = MessageAttachment::PROVIDER_TELEGRAM_BOT,
        int $attempts = 1,
        bool $manual = false,
        mixed $heartbeatAt = null,
        ?string $claimToken = 'claim-token',
    ): MessageAttachment {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => $provider,
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'file_size_bytes' => 100,
            'media_download_max_bytes' => 500,
            'media_download_generation' => 1,
            'media_download_attempts' => 0,
            'manual_download_requested_at' => $manual ? now()->subMinute() : null,
        ]);

        app(InboundMediaQuotaLedger::class)->reserveForAttempt($attachment, $attempts);
        $claimedAt = now()->subMinutes(5);
        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => $claimToken,
            'media_download_attempts' => $attempts,
            'media_download_trigger' => $manual ? 'manual' : 'auto',
            'media_download_claimed_at' => $claimedAt,
            'media_download_heartbeat_at' => $heartbeatAt ?? $claimedAt,
            'media_download_attempt_deadline_at' => now()->addHour(),
        ])->save();

        return $attachment->fresh();
    }

    private function partialPath(MessageAttachment $attachment): string
    {
        return MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->id
            .'.bin.partial.g1.claim-token.test';
    }
}
