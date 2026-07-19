<?php

namespace App\Services\Messages;

use App\Jobs\DeleteRolledBackInboundMediaFileJob;
use App\Models\MessageAttachment;
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

            DeleteRolledBackInboundMediaFileJob::dispatch($attachmentId, $disk, $path, true);

            return false;
        }
    }

    public function deletePreparedOrFail(string $disk, string $path): bool
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return true;
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

            DeleteRolledBackInboundMediaFileJob::dispatch($attachmentId, $disk, $path);

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
}
