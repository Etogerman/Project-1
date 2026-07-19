<?php

namespace Tests\Feature;

use App\Jobs\CleanupInboundMediaPartialFilesJob;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Messages\DeleteInboundMediaPartialFilesAction;
use App\Services\Messages\InboundMediaQuotaLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class InboundMediaPartialCleanupJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
    }

    public function test_job_deletes_partial_files_idempotently(): void
    {
        $attachment = $this->createAttachment();
        $partialPath = MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->id
            .'.bin.partial.g1.cleanup-job-token.cleanup-job';
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($partialPath, 'partial');
        $job = new CleanupInboundMediaPartialFilesJob($attachment->id, 1, 'cleanup-job-token');

        $job->handle(
            app(DeleteInboundMediaPartialFilesAction::class),
            app(InboundMediaQuotaLedger::class),
        );
        $job->handle(
            app(DeleteInboundMediaPartialFilesAction::class),
            app(InboundMediaQuotaLedger::class),
        );

        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($partialPath);
    }

    public function test_job_throws_when_cleanup_cannot_be_confirmed(): void
    {
        $attachment = $this->createAttachment();
        $cleanup = $this->mock(DeleteInboundMediaPartialFilesAction::class);
        $cleanup->shouldReceive('scopedBytes')->once()->andReturn(0);
        $cleanup->shouldReceive('handle')->once()->andReturnFalse();

        $this->expectException(RuntimeException::class);

        (new CleanupInboundMediaPartialFilesJob($attachment->id, 1, 'cleanup-job-token'))->handle(
            $cleanup,
            app(InboundMediaQuotaLedger::class),
        );
    }

    public function test_job_has_bounded_retry_and_unique_contract(): void
    {
        config([
            'inbound_media.cleanup.unique_for_seconds' => 900,
            'inbound_media.cleanup.retry_delays_seconds' => [10, 20, 30, 40],
        ]);
        $job = new CleanupInboundMediaPartialFilesJob(42, 2, 'cleanup-job-token');

        $this->assertSame(5, $job->tries);
        $this->assertSame(900, $job->uniqueFor);
        $this->assertSame([10, 20, 30, 40], $job->backoff());
        $this->assertSame(
            'inbound-media-partial-cleanup:42:g2:'.substr(hash('sha256', 'cleanup-job-token'), 0, 16),
            $job->uniqueId(),
        );
    }

    public function test_old_generation_cleanup_does_not_delete_current_generation_partial(): void
    {
        $attachment = $this->createAttachment();
        $attachment->forceFill(['media_download_generation' => 2])->save();
        $directory = MessageAttachment::LOCAL_PATH_PREFIX.'/'.$attachment->message_id.'/';
        $oldPath = $directory.$attachment->id.'.bin.partial.g1.old-token.old';
        $currentPath = $directory.$attachment->id.'.bin.partial.g2.current-token.current';
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($oldPath, 'old');
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($currentPath, 'current');

        (new CleanupInboundMediaPartialFilesJob($attachment->id, 1, 'old-token'))
            ->handle(
                app(DeleteInboundMediaPartialFilesAction::class),
                app(InboundMediaQuotaLedger::class),
            );

        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($oldPath);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists($currentPath);
    }

    public function test_job_deletes_scoped_commit_candidate_but_preserves_adopted_path(): void
    {
        $attachment = $this->createAttachment();
        $directory = MessageAttachment::LOCAL_PATH_PREFIX.'/'.$attachment->message_id.'/';
        $orphanPath = $directory.$attachment->id.'.g1.cleanup-job-token.commit.orphan.mp4';
        $adoptedPath = $directory.$attachment->id.'.g1.cleanup-job-token.commit.adopted.mp4';
        $attachment->forceFill([
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => $adoptedPath,
        ])->save();
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($orphanPath, 'orphan');
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($adoptedPath, 'adopted');

        (new CleanupInboundMediaPartialFilesJob($attachment->id, 1, 'cleanup-job-token'))
            ->handle(
                app(DeleteInboundMediaPartialFilesAction::class),
                app(InboundMediaQuotaLedger::class),
            );

        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($orphanPath);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists($adoptedPath);
    }

    public function test_legacy_job_deletes_unadopted_commit_candidate(): void
    {
        $attachment = $this->createAttachment();
        $commitPath = MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->id
            .'.g1.legacy-token.commit.orphan.mp4';
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($commitPath, 'orphan');

        (new CleanupInboundMediaPartialFilesJob($attachment->id))
            ->handle(
                app(DeleteInboundMediaPartialFilesAction::class),
                app(InboundMediaQuotaLedger::class),
            );

        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($commitPath);
    }

    public function test_final_failure_logs_only_safe_dead_letter_metadata(): void
    {
        Log::spy();
        $job = new CleanupInboundMediaPartialFilesJob(42);

        $job->failed(new RuntimeException('private path must not be logged'));

        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'inbound_media.partial_cleanup_dead_letter',
                [
                    'attachment_id' => 42,
                    'error_type' => RuntimeException::class,
                ],
            );
    }

    private function createAttachment(): MessageAttachment
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);

        return MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_generation' => 1,
        ]);
    }
}
