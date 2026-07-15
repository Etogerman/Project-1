<?php

namespace Tests\Feature;

use App\Jobs\CleanupInboundMediaPartialFilesJob;
use App\Models\Channel;
use App\Models\MediaDownloadStorageLedger;
use App\Models\MediaDownloadTrafficLedger;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Messages\DeleteInboundMediaPartialFilesAction;
use App\Services\Messages\InboundMediaQuotaLedger;
use App\Services\Messages\ReapExpiredInboundMediaReservationsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboundMediaReservationReaperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config([
            'inbound_media.storage.enforce' => true,
            'inbound_media.storage.global_limit_bytes' => 10_000,
            'inbound_media.storage.channel_limit_bytes' => 10_000,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
            'inbound_media.traffic.enforce' => true,
            'inbound_media.traffic.channel_daily_limit_bytes' => 10_000,
        ]);
    }

    public function test_expired_orphan_reservations_release_only_after_partial_cleanup(): void
    {
        $attachment = $this->createReservedAttachment();
        $partialPath = $this->partialPath($attachment);
        $uploadPath = $this->uploadPath($attachment);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($partialPath, 'partial-bytes');
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($uploadPath, 'upload-bytes');
        app(InboundMediaQuotaLedger::class)->checkpointTraffic($attachment, 1, 40);
        $this->expireReservations();

        $stats = app(ReapExpiredInboundMediaReservationsAction::class)->handle();

        $this->assertSame(1, $stats['released']);
        $this->assertSame(1, $stats['storage_released']);
        $this->assertSame(1, $stats['traffic_released']);
        $this->assertSame(40, $stats['traffic_consumed_bytes']);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($partialPath);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($uploadPath);
        $this->assertSame(
            MediaDownloadStorageLedger::STATUS_RELEASED,
            MediaDownloadStorageLedger::query()->firstOrFail()->status,
        );
        $traffic = MediaDownloadTrafficLedger::query()->firstOrFail();
        $this->assertSame(MediaDownloadTrafficLedger::STATUS_CONSUMED, $traffic->status);
        $this->assertSame(40, $traffic->consumed_bytes);
        $this->assertSame(0, (int) DB::table('media_download_storage_budgets')->sum('reserved_bytes'));
        $this->assertSame(0, (int) DB::table('media_download_traffic_budgets')->sum('reserved_bytes'));
    }

    public function test_active_download_is_left_to_lease_reaper(): void
    {
        $attachment = $this->createReservedAttachment();
        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => 'active-claim',
            'media_download_heartbeat_at' => now(),
        ])->save();
        $this->expireReservations();

        $stats = app(ReapExpiredInboundMediaReservationsAction::class)->handle();

        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(
            MediaDownloadStorageLedger::STATUS_RESERVED,
            MediaDownloadStorageLedger::query()->firstOrFail()->status,
        );
    }

    public function test_cleanup_failure_keeps_reservations_for_durable_retry(): void
    {
        Queue::fake();
        $attachment = $this->createReservedAttachment();
        $this->expireReservations();
        $cleanup = $this->mock(DeleteInboundMediaPartialFilesAction::class);
        $cleanup->shouldReceive('handle')->once()->andReturnFalse();

        $stats = app(ReapExpiredInboundMediaReservationsAction::class)->handle();

        $this->assertSame(1, $stats['cleanup_failed']);
        $this->assertSame(
            MediaDownloadStorageLedger::STATUS_RESERVED,
            MediaDownloadStorageLedger::query()->firstOrFail()->status,
        );
        $this->assertSame(
            MediaDownloadTrafficLedger::STATUS_RESERVED,
            MediaDownloadTrafficLedger::query()->firstOrFail()->status,
        );
        Queue::assertPushed(
            CleanupInboundMediaPartialFilesJob::class,
            fn (CleanupInboundMediaPartialFilesJob $job): bool => $job->attachmentId === $attachment->id,
        );
    }

    private function createReservedAttachment(): MessageAttachment
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'file_size_bytes' => 100,
            'media_download_max_bytes' => 500,
            'media_download_generation' => 1,
            'media_download_attempts' => 1,
        ]);

        app(InboundMediaQuotaLedger::class)->reserveForAttempt($attachment, 1);

        return $attachment->fresh();
    }

    private function expireReservations(): void
    {
        MediaDownloadStorageLedger::query()->update(['expires_at' => now()->subSecond()]);
        MediaDownloadTrafficLedger::query()->update(['expires_at' => now()->subSecond()]);
    }

    private function partialPath(MessageAttachment $attachment): string
    {
        return MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->id
            .'.bin.partial.g1.expired-claim.expired';
    }

    private function uploadPath(MessageAttachment $attachment): string
    {
        return MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->id
            .'.g1.expired-claim.upload';
    }
}
