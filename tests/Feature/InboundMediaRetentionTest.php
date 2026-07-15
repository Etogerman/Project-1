<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\MediaDownloadStorageLedger;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Messages\InboundMediaQuotaLedger;
use App\Services\Messages\PruneInboundMediaStorageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboundMediaRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config([
            'inbound_media.retention_days' => 90,
            'inbound_media.storage.enforce' => true,
            'inbound_media.storage.global_limit_bytes' => 10_000,
            'inbound_media.storage.channel_limit_bytes' => 10_000,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
            'inbound_media.traffic.enforce' => true,
            'inbound_media.traffic.channel_daily_limit_bytes' => 10_000,
        ]);
    }

    public function test_retention_deletes_stable_object_before_releasing_used_storage(): void
    {
        $attachment = $this->createDownloadedAttachment();
        MediaDownloadStorageLedger::query()->update(['updated_at' => now()->subDays(91)]);

        $stats = app(PruneInboundMediaStorageAction::class)->handle();
        $attachment->refresh();

        $this->assertSame(1, $stats['deleted']);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($this->stablePath($attachment));
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL, $attachment->download_status);
        $this->assertNull($attachment->local_disk);
        $this->assertNull($attachment->local_path);
        $this->assertSame(PruneInboundMediaStorageAction::REASON_RETENTION_DELETED, $attachment->safe_error_code);
        $this->assertSame(
            MediaDownloadStorageLedger::STATUS_RELEASED,
            MediaDownloadStorageLedger::query()->firstOrFail()->status,
        );
        $this->assertSame(0, (int) DB::table('media_download_storage_budgets')->sum('used_bytes'));
    }

    public function test_recent_stable_object_is_not_deleted(): void
    {
        $attachment = $this->createDownloadedAttachment();

        $stats = app(PruneInboundMediaStorageAction::class)->handle();

        $this->assertSame(0, $stats['inspected']);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists($this->stablePath($attachment));
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->fresh()->download_status);
    }

    public function test_zero_retention_keeps_files_indefinitely(): void
    {
        config(['inbound_media.retention_days' => 0]);
        $attachment = $this->createDownloadedAttachment();
        MediaDownloadStorageLedger::query()->update(['updated_at' => now()->subYear()]);

        $stats = app(PruneInboundMediaStorageAction::class)->handle();

        $this->assertSame(1, $stats['disabled']);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists($this->stablePath($attachment));
    }

    private function createDownloadedAttachment(): MessageAttachment
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'file_size_bytes' => 80,
            'media_download_max_bytes' => 500,
            'media_download_generation' => 1,
            'media_download_attempts' => 1,
        ]);
        $path = $this->stablePath($attachment);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($path, str_repeat('x', 80));
        app(InboundMediaQuotaLedger::class)->reserveForAttempt($attachment, 1);
        app(InboundMediaQuotaLedger::class)->completeAttempt($attachment, 1, 80);
        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => $path,
        ])->save();

        return $attachment->fresh();
    }

    private function stablePath(MessageAttachment $attachment): string
    {
        return MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->id
            .'.mp4';
    }
}
