<?php

namespace App\Services\Messages;

use App\Models\MessageAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

class StoreMessageAttachmentLocalFileAction
{
    public function __construct(
        private readonly DeleteRolledBackInboundMediaFileAction $deleteRolledBackInboundMediaFileAction,
    ) {}

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
     * @param  (callable(MessageAttachment): void)|null  $afterAttachmentSaved
     * @param  array<string, mixed>  $attachmentValues
     */
    public function handleStream(
        MessageAttachment $attachment,
        mixed $stream,
        ?int $fileSizeBytes = null,
        mixed $extension = null,
        ?callable $afterAttachmentSaved = null,
        ?string $expectedClaimToken = null,
        array $attachmentValues = [],
    ): MessageAttachment {
        if (! $attachment->exists || $attachment->getKey() === null) {
            throw new LogicException('Message attachment must be persisted before storing a local file.');
        }

        if (! is_resource($stream)) {
            throw new LogicException('Message attachment stream must be a resource.');
        }

        $path = $expectedClaimToken !== null
            ? $this->buildClaimedPath(
                $attachment,
                $extension ?? $attachment->extension,
                $expectedClaimToken,
            )
            : $this->buildPath($attachment, $extension ?? $attachment->extension);
        $disk = MessageAttachment::storageDiskName();
        $temporaryPath = $this->buildPartialPath($attachment, $path, $expectedClaimToken);

        $stored = Storage::disk($disk)->put($temporaryPath, $stream);

        if ($stored === false) {
            throw new RuntimeException('Failed to store temporary message attachment file.');
        }

        $published = false;
        $storedAttachment = null;

        try {
            $storedFileSizeBytes = (int) Storage::disk($disk)->size($temporaryPath);

            if ($fileSizeBytes !== null && $storedFileSizeBytes !== $fileSizeBytes) {
                throw new MediaDownloadIntegrityException(
                    'Stored media size does not match the declared file size.',
                );
            }

            DB::transaction(function () use (
                $attachment,
                $disk,
                $path,
                $temporaryPath,
                $storedFileSizeBytes,
                $afterAttachmentSaved,
                $expectedClaimToken,
                $attachmentValues,
                &$published,
                &$storedAttachment,
            ): void {
                /** @var MessageAttachment $locked */
                $locked = MessageAttachment::query()
                    ->whereKey($attachment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($expectedClaimToken !== null) {
                    $currentClaimToken = trim((string) $locked->media_download_claim_token);

                    if (
                        $locked->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
                        || $currentClaimToken === ''
                        || ! hash_equals($currentClaimToken, $expectedClaimToken)
                        || $locked->media_download_attempt_deadline_at === null
                        || $locked->media_download_attempt_deadline_at->isPast()
                    ) {
                        throw new MediaDownloadLeaseLostException;
                    }
                }

                if (Storage::disk($disk)->exists($path) && ! Storage::disk($disk)->delete($path)) {
                    throw new RuntimeException('Failed to remove orphaned stable message attachment file.');
                }

                if (! Storage::disk($disk)->move($temporaryPath, $path)) {
                    throw new RuntimeException('Failed to publish stable message attachment file.');
                }

                $published = true;

                $locked->forceFill([
                    ...$attachmentValues,
                    'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
                    'local_disk' => $disk,
                    'local_path' => $path,
                    'file_size_bytes' => $storedFileSizeBytes,
                    'media_download_claim_token' => null,
                    'media_download_upload_size_bytes' => null,
                    'media_download_next_retry_at' => null,
                    'media_download_claimed_at' => null,
                    'media_download_heartbeat_at' => null,
                    'media_download_attempt_deadline_at' => null,
                    'safe_error_code' => null,
                    'safe_error_message' => null,
                ])->save();

                if ($afterAttachmentSaved !== null) {
                    $afterAttachmentSaved($locked);
                }

                $storedAttachment = $locked;
            }, 3);
        } catch (\Throwable $throwable) {
            $this->deleteRolledBackInboundMediaFileAction->handle(
                (int) $attachment->getKey(),
                $disk,
                $temporaryPath,
            );

            if ($published) {
                $this->deleteRolledBackInboundMediaFileAction->handle(
                    (int) $attachment->getKey(),
                    $disk,
                    $path,
                );
            }

            throw $throwable;
        }

        if (! $storedAttachment instanceof MessageAttachment) {
            throw new RuntimeException('Message attachment file transaction did not return an attachment.');
        }

        return $storedAttachment->refresh();
    }

    public function buildPath(MessageAttachment $attachment, mixed $extension = null): string
    {
        $safeExtension = MessageAttachment::sanitizeExtension($extension) ?: 'bin';

        return MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->getKey()
            .'.'.$safeExtension;
    }

    public function buildClaimedPath(
        MessageAttachment $attachment,
        mixed $extension,
        string $claimToken,
    ): string {
        $safeExtension = MessageAttachment::sanitizeExtension($extension) ?: 'bin';
        $safeToken = $this->safeClaimToken($claimToken);
        $generation = max(1, (int) $attachment->media_download_generation);

        return MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->getKey()
            .'.g'.$generation
            .'.'.$safeToken
            .'.'.$safeExtension;
    }

    public function buildDirectUploadPath(MessageAttachment $attachment, ?string $claimToken = null): string
    {
        $safeToken = $this->safeClaimToken($claimToken ?? (string) $attachment->media_download_claim_token);
        $generation = max(1, (int) $attachment->media_download_generation);

        return MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->getKey()
            .'.g'.$generation
            .'.'.$safeToken
            .'.upload';
    }

    private function buildPartialPath(
        MessageAttachment $attachment,
        string $stablePath,
        ?string $expectedClaimToken,
    ): string {
        $generation = max(1, (int) $attachment->media_download_generation);
        $claimSegment = $expectedClaimToken !== null
            ? $this->safeClaimToken($expectedClaimToken)
            : 'unclaimed';

        return $stablePath
            .'.partial.g'.$generation
            .'.'.$claimSegment
            .'.'.Str::uuid()->toString();
    }

    private function safeClaimToken(string $claimToken): string
    {
        $token = trim($claimToken);

        if ($token === '') {
            throw new LogicException('Message attachment direct upload requires a claim token.');
        }

        $safeToken = preg_replace('/[^A-Za-z0-9_-]+/', '-', $token) ?? '';

        if ($safeToken === '') {
            throw new LogicException('Message attachment direct upload claim token is invalid.');
        }

        return $safeToken;
    }
}
