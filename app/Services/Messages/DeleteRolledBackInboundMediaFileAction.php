<?php

namespace App\Services\Messages;

use App\Jobs\DeleteRolledBackInboundMediaFileJob;
use App\Models\MessageAttachment;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DeleteRolledBackInboundMediaFileAction
{
    public function handlePrepared(int $attachmentId, string $disk, string $path): bool
    {
        try {
            return $this->deletePreparedOrFail($disk, $path);
        } catch (Throwable $exception) {
            Log::warning('inbound_media.prepared_file_cleanup_failed', [
                'attachment_id' => $attachmentId,
                'error_type' => $exception::class,
            ]);

            $this->dispatchDurableCleanup($attachmentId, $disk, $path, true);

            return false;
        }
    }

    public function handlePossiblyLatePrepared(int $attachmentId, string $disk, string $path): bool
    {
        $deleted = true;

        try {
            $this->deletePreparedOrFail($disk, $path);
        } catch (Throwable $exception) {
            $deleted = false;

            Log::warning('inbound_media.prepared_file_cleanup_failed', [
                'attachment_id' => $attachmentId,
                'error_type' => $exception::class,
            ]);
        }

        $this->dispatchDurableCleanup(
            $attachmentId,
            $disk,
            $path,
            prepared: true,
            waitForLateArrival: true,
        );

        return $deleted;
    }

    public function deletePreparedOrFail(string $disk, string $path): bool
    {
        $this->deletePreparedIfPresentOrFail($disk, $path);

        return true;
    }

    public function deletePreparedIfPresentOrFail(string $disk, string $path): bool
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return false;
        }

        if (! $storage->delete($path)) {
            throw new RuntimeException('Prepared inbound media file could not be removed.');
        }

        return true;
    }

    public function handle(int $attachmentId, string $disk, string $path): bool
    {
        try {
            return $this->deleteOrFail($attachmentId, $disk, $path);
        } catch (Throwable $exception) {
            Log::warning('inbound_media.rolled_back_file_cleanup_failed', [
                'attachment_id' => $attachmentId,
                'error_type' => $exception::class,
            ]);

            $this->dispatchDurableCleanup($attachmentId, $disk, $path);

            return false;
        }
    }

    public function deleteOrFail(int $attachmentId, string $disk, string $path): bool
    {
        $isReferenced = DB::transaction(function () use ($attachmentId, $disk, $path): bool {
            $attachment = MessageAttachment::query()
                ->whereKey($attachmentId)
                ->lockForUpdate()
                ->first();

            return
                $attachment instanceof MessageAttachment
                && $attachment->local_disk === $disk
                && $attachment->local_path === $path;
        }, 3);

        if ($isReferenced) {
            return false;
        }

        return $this->deletePreparedOrFail($disk, $path);
    }

    private function dispatchDurableCleanup(
        int $attachmentId,
        string $disk,
        string $path,
        bool $prepared = false,
        bool $waitForLateArrival = false,
    ): void {
        $job = new DeleteRolledBackInboundMediaFileJob(
            $attachmentId,
            $disk,
            $path,
            $prepared,
            $waitForLateArrival,
        );

        try {
            dispatch($job);
        } catch (Throwable $exception) {
            try {
                (new UniqueLock(app(CacheRepository::class)))->release($job);
            } catch (Throwable $lockException) {
                Log::error('inbound_media.rolled_back_file_cleanup_unique_lock_release_failed', [
                    'attachment_id' => $attachmentId,
                    'prepared' => $prepared,
                    'error_type' => $lockException::class,
                ]);
            } finally {
                Context::forgetHidden([
                    'laravel_unique_job_cache_store',
                    'laravel_unique_job_key',
                ]);
            }

            Log::error('inbound_media.rolled_back_file_cleanup_enqueue_failed', [
                'attachment_id' => $attachmentId,
                'prepared' => $prepared,
                'error_type' => $exception::class,
            ]);
        }
    }
}
