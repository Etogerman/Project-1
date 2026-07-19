<?php

namespace Tests\Feature;

use App\Jobs\DeleteRolledBackInboundMediaFileJob;
use App\Models\MessageAttachment;
use App\Services\Messages\DeleteRolledBackInboundMediaFileAction;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DeleteRolledBackInboundMediaFileActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_preserves_a_file_committed_by_a_concurrent_successful_result(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        $attachment = MessageAttachment::factory()->create([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => 'message-attachments/concurrent-success.pdf',
        ]);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)
            ->put((string) $attachment->local_path, 'committed-file');

        $deleted = app(DeleteRolledBackInboundMediaFileAction::class)->handle(
            (int) $attachment->id,
            MessageAttachment::LOCAL_DISK_PRIVATE,
            (string) $attachment->local_path,
        );

        $this->assertFalse($deleted);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)
            ->assertExists((string) $attachment->local_path);
    }

    public function test_it_deletes_a_file_not_referenced_by_the_current_attachment_state(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        $attachment = MessageAttachment::factory()->create([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'local_disk' => null,
            'local_path' => null,
        ]);
        $path = 'message-attachments/rolled-back-file.pdf';
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($path, 'rolled-back-file');

        $deleted = app(DeleteRolledBackInboundMediaFileAction::class)->handle(
            (int) $attachment->id,
            MessageAttachment::LOCAL_DISK_PRIVATE,
            $path,
        );

        $this->assertTrue($deleted);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($path);
    }

    public function test_unreferenced_file_storage_io_runs_after_the_reference_transaction(): void
    {
        $attachment = MessageAttachment::factory()->create([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'local_disk' => null,
            'local_path' => null,
        ]);
        $path = 'message-attachments/outside-transaction.pdf';
        $baselineTransactionLevel = DB::connection()->transactionLevel();
        $storageTransactionLevels = [];
        $storage = Mockery::mock(FilesystemAdapter::class);
        $storage->shouldReceive('exists')
            ->once()
            ->with($path)
            ->andReturnUsing(function () use (&$storageTransactionLevels): bool {
                $storageTransactionLevels[] = DB::connection()->transactionLevel();

                return true;
            });
        $storage->shouldReceive('delete')
            ->once()
            ->with($path)
            ->andReturnUsing(function () use (&$storageTransactionLevels): bool {
                $storageTransactionLevels[] = DB::connection()->transactionLevel();

                return true;
            });
        Storage::shouldReceive('disk')
            ->once()
            ->with(MessageAttachment::LOCAL_DISK_PRIVATE)
            ->andReturn($storage);

        $this->assertTrue(app(DeleteRolledBackInboundMediaFileAction::class)->deleteOrFail(
            (int) $attachment->id,
            MessageAttachment::LOCAL_DISK_PRIVATE,
            $path,
        ));
        $this->assertSame(
            [$baselineTransactionLevel, $baselineTransactionLevel],
            $storageTransactionLevels,
        );
    }

    public function test_it_dispatches_durable_cleanup_when_immediate_deletion_fails(): void
    {
        Queue::fake();
        $attachment = MessageAttachment::factory()->create([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'local_disk' => null,
            'local_path' => null,
        ]);
        $path = 'message-attachments/rolled-back-file.pdf';
        $storage = Mockery::mock(FilesystemAdapter::class);
        $storage->shouldReceive('exists')->once()->with($path)->andReturnTrue();
        $storage->shouldReceive('delete')->once()->with($path)->andReturnFalse();
        Storage::shouldReceive('disk')
            ->once()
            ->with(MessageAttachment::LOCAL_DISK_PRIVATE)
            ->andReturn($storage);

        $deleted = app(DeleteRolledBackInboundMediaFileAction::class)->handle(
            (int) $attachment->id,
            MessageAttachment::LOCAL_DISK_PRIVATE,
            $path,
        );

        $this->assertFalse($deleted);
        Queue::assertPushed(
            DeleteRolledBackInboundMediaFileJob::class,
            fn (DeleteRolledBackInboundMediaFileJob $job): bool => $job->attachmentId === $attachment->id
                && $job->disk === MessageAttachment::LOCAL_DISK_PRIVATE
                && $job->path === $path,
        );
    }

    public function test_durable_cleanup_job_retries_unconfirmed_deletion(): void
    {
        $action = Mockery::mock(DeleteRolledBackInboundMediaFileAction::class);
        $action->shouldReceive('deleteOrFail')
            ->once()
            ->with(42, MessageAttachment::LOCAL_DISK_PRIVATE, 'message-attachments/orphan.pdf')
            ->andThrow(new RuntimeException('cleanup failed'));

        $this->expectException(RuntimeException::class);

        (new DeleteRolledBackInboundMediaFileJob(
            42,
            MessageAttachment::LOCAL_DISK_PRIVATE,
            'message-attachments/orphan.pdf',
        ))->handle($action);
    }

    public function test_durable_cleanup_job_has_bounded_retry_and_safe_dead_letter_contract(): void
    {
        config([
            'inbound_media.cleanup.unique_for_seconds' => 900,
            'inbound_media.cleanup.retry_delays_seconds' => [10, 20, 30, 40],
        ]);
        $job = new DeleteRolledBackInboundMediaFileJob(
            42,
            MessageAttachment::LOCAL_DISK_PRIVATE,
            'message-attachments/private/orphan.pdf',
        );

        $this->assertSame(5, $job->tries);
        $this->assertSame(900, $job->uniqueFor);
        $this->assertSame([10, 20, 30, 40], $job->backoff());
        $this->assertStringNotContainsString('private', $job->uniqueId());

        Log::spy();
        $job->failed(new RuntimeException('private path must not be logged'));

        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'inbound_media.rolled_back_file_cleanup_dead_letter',
                [
                    'attachment_id' => 42,
                    'error_type' => RuntimeException::class,
                ],
            );
    }
}
