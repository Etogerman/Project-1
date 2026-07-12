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
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('Failed to open temporary attachment stream.');
        }

        try {
            $writtenBytes = fwrite($stream, $contents);

            if ($writtenBytes === false || $writtenBytes !== strlen($contents)) {
                throw new RuntimeException('Failed to write attachment contents to temporary stream.');
            }

            rewind($stream);

            return $this->handleStream($attachment, $stream, strlen($contents), $extension);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     */
    public function handleStream(
        MessageAttachment $attachment,
        mixed $stream,
        ?int $fileSizeBytes = null,
        mixed $extension = null,
    ): MessageAttachment {
        if (! $attachment->exists || $attachment->getKey() === null) {
            throw new LogicException('Message attachment must be persisted before storing a local file.');
        }

        if (! is_resource($stream)) {
            throw new LogicException('Message attachment stream must be a resource.');
        }

        $path = $this->buildPath($attachment, $extension ?? $attachment->extension);
        $disk = MessageAttachment::storageDiskName();

        $stored = Storage::disk($disk)->put($path, $stream);

        if ($stored === false) {
            throw new RuntimeException('Failed to store message attachment local file.');
        }

        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => $disk,
            'local_path' => $path,
            'file_size_bytes' => $fileSizeBytes ?? $attachment->file_size_bytes,
            'media_download_claim_token' => null,
            'media_download_upload_size_bytes' => null,
            'media_download_next_retry_at' => null,
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

    public function buildDirectUploadPath(MessageAttachment $attachment, ?string $claimToken = null): string
    {
        $token = trim($claimToken ?? (string) $attachment->media_download_claim_token);

        if ($token === '') {
            throw new LogicException('Message attachment direct upload requires a claim token.');
        }

        $safeToken = preg_replace('/[^A-Za-z0-9_-]+/', '-', $token) ?? '';

        if ($safeToken === '') {
            throw new LogicException('Message attachment direct upload claim token is invalid.');
        }

        return MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->getKey()
            .'.'.$safeToken
            .'.upload';
    }
}
