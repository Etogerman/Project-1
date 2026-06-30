<?php

namespace App\Services\Messages;

use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

class StoreMessageAttachmentLocalFileAction
{
    public function handle(MessageAttachment $attachment, string $contents, mixed $extension = null): MessageAttachment
    {
        if (! $attachment->exists || $attachment->getKey() === null) {
            throw new LogicException('Message attachment must be persisted before storing a local file.');
        }

        $path = $this->buildPath($attachment, $extension ?? $attachment->extension);

        $stored = Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($path, $contents);

        if ($stored === false) {
            throw new RuntimeException('Failed to store message attachment local file.');
        }

        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => $path,
            'file_size_bytes' => $attachment->file_size_bytes ?? strlen($contents),
            'safe_error_code' => null,
            'safe_error_message' => null,
        ])->save();

        return $attachment->refresh();
    }

    public function buildPath(MessageAttachment $attachment, mixed $extension = null): string
    {
        $safeExtension = MessageAttachment::sanitizeExtension($extension) ?: 'bin';

        return MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->getKey()
            .'.'.$safeExtension;
    }
}
