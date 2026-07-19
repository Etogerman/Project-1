<?php

namespace App\Services\Messages;

use App\Data\Messages\PreparedMessageAttachmentFile;
use App\Models\MessageAttachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

class StoreMessageAttachmentLocalFileAction
{
    private const LOCAL_COPY_CHUNK_BYTES = 64 * 1024 * 1024;

    private const S3_COPY_MULTIPART_PART_BYTES = 16 * 1024 * 1024;

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
        $prepared = $this->prepareStream(
            $attachment,
            $stream,
            $fileSizeBytes,
            $extension,
            $expectedClaimToken,
        );
        $storedAttachment = null;
        $previousFile = null;

        try {
            DB::transaction(function () use (
                $attachment,
                $prepared,
                $afterAttachmentSaved,
                $expectedClaimToken,
                $attachmentValues,
                &$storedAttachment,
                &$previousFile,
            ): void {
                /** @var MessageAttachment $locked */
                $locked = MessageAttachment::query()
                    ->whereKey($attachment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (filled($locked->local_disk) && filled($locked->local_path)) {
                    $previousFile = [
                        'disk' => (string) $locked->local_disk,
                        'path' => (string) $locked->local_path,
                    ];
                }

                $storedAttachment = $this->adoptPreparedFile(
                    $locked,
                    $prepared,
                    $attachmentValues,
                    $afterAttachmentSaved,
                    $expectedClaimToken,
                );
            }, 3);
        } catch (\Throwable $throwable) {
            $this->discardPreparedFile($prepared);

            throw $throwable;
        }

        if (! $storedAttachment instanceof MessageAttachment) {
            throw new RuntimeException('Message attachment file transaction did not return an attachment.');
        }

        if (
            is_array($previousFile)
            && ($previousFile['disk'] !== $prepared->disk || $previousFile['path'] !== $prepared->path)
        ) {
            $this->deleteRolledBackInboundMediaFileAction->handle(
                (int) $storedAttachment->getKey(),
                $previousFile['disk'],
                $previousFile['path'],
            );
        }

        return $storedAttachment->refresh();
    }

    /**
     * @param  resource  $stream
     */
    public function prepareStream(
        MessageAttachment $attachment,
        mixed $stream,
        ?int $fileSizeBytes = null,
        mixed $extension = null,
        ?string $expectedClaimToken = null,
        ?callable $onStorageProgress = null,
    ): PreparedMessageAttachmentFile {
        if (! $attachment->exists || $attachment->getKey() === null) {
            throw new LogicException('Message attachment must be persisted before storing a local file.');
        }

        if (! is_resource($stream)) {
            throw new LogicException('Message attachment stream must be a resource.');
        }

        $disk = MessageAttachment::storageDiskName();
        $path = $this->buildPreparedPath(
            $attachment,
            $extension ?? $attachment->extension,
            $expectedClaimToken,
        );
        $storageOptions = [];

        if ($onStorageProgress !== null) {
            $onStorageProgress();
            $storageOptions = $this->storageProgressOptions($onStorageProgress);
        }

        try {
            $stored = Storage::disk($disk)->put($path, $stream, $storageOptions);

            if ($stored === false) {
                throw new RuntimeException('Failed to prepare message attachment file.');
            }

            $storedFileSizeBytes = (int) Storage::disk($disk)->size($path);

            if ($onStorageProgress !== null) {
                $onStorageProgress();
            }

            if ($fileSizeBytes !== null && $storedFileSizeBytes !== $fileSizeBytes) {
                throw new MediaDownloadIntegrityException(
                    'Stored media size does not match the declared file size.',
                );
            }

            return new PreparedMessageAttachmentFile(
                attachmentId: (int) $attachment->getKey(),
                messageId: (int) $attachment->message_id,
                generation: max(1, (int) $attachment->media_download_generation),
                claimToken: $expectedClaimToken,
                disk: $disk,
                path: $path,
                sizeBytes: $storedFileSizeBytes,
            );
        } catch (\Throwable $throwable) {
            $this->deleteRolledBackInboundMediaFileAction->handlePrepared(
                (int) $attachment->getKey(),
                $disk,
                $path,
            );

            throw $throwable;
        }
    }

    public function prepareCopy(
        MessageAttachment $attachment,
        string $sourcePath,
        ?int $fileSizeBytes = null,
        mixed $extension = null,
        ?string $expectedClaimToken = null,
        ?callable $onStorageProgress = null,
    ): PreparedMessageAttachmentFile {
        if (! $attachment->exists || $attachment->getKey() === null) {
            throw new LogicException('Message attachment must be persisted before preparing a file copy.');
        }

        $disk = MessageAttachment::storageDiskName();
        $path = $this->buildPreparedPath(
            $attachment,
            $extension ?? $attachment->extension,
            $expectedClaimToken,
        );
        $storage = Storage::disk($disk);

        if ($onStorageProgress !== null) {
            $onStorageProgress();
        }

        if (! $storage->exists($sourcePath)) {
            throw new RuntimeException('Message attachment source file does not exist.');
        }

        try {
            if ($onStorageProgress !== null) {
                try {
                    if ($this->storageDiskUsesLocalDriver($disk, $storage)) {
                        $this->copyLocalFileWithProgress(
                            $storage,
                            $sourcePath,
                            $path,
                            $onStorageProgress,
                        );
                    } else {
                        $storage->getDriver()->copy(
                            $sourcePath,
                            $path,
                            $this->storageCopyProgressOptions($onStorageProgress),
                        );
                    }
                } catch (\Throwable $throwable) {
                    throw new RuntimeException(
                        'Failed to prepare message attachment file copy.',
                        previous: $throwable,
                    );
                }
            } elseif (! $storage->copy($sourcePath, $path)) {
                throw new RuntimeException('Failed to prepare message attachment file copy.');
            }

            if ($onStorageProgress !== null) {
                $onStorageProgress();
            }

            $storedFileSizeBytes = (int) $storage->size($path);

            if ($fileSizeBytes !== null && $storedFileSizeBytes !== $fileSizeBytes) {
                throw new MediaDownloadIntegrityException(
                    'Stored media size does not match the declared file size.',
                );
            }

            return new PreparedMessageAttachmentFile(
                attachmentId: (int) $attachment->getKey(),
                messageId: (int) $attachment->message_id,
                generation: max(1, (int) $attachment->media_download_generation),
                claimToken: $expectedClaimToken,
                disk: $disk,
                path: $path,
                sizeBytes: $storedFileSizeBytes,
            );
        } catch (\Throwable $throwable) {
            $this->deleteRolledBackInboundMediaFileAction->handlePossiblyLatePrepared(
                (int) $attachment->getKey(),
                $disk,
                $path,
            );

            throw $throwable;
        }
    }

    /**
     * @param  array<string, mixed>  $attachmentValues
     * @param  (callable(MessageAttachment): void)|null  $afterAttachmentSaved
     */
    public function adoptPreparedFile(
        MessageAttachment $locked,
        PreparedMessageAttachmentFile $prepared,
        array $attachmentValues = [],
        ?callable $afterAttachmentSaved = null,
        ?string $expectedClaimToken = null,
    ): MessageAttachment {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Prepared message attachment file must be adopted inside a transaction.');
        }

        if (
            (int) $locked->getKey() !== $prepared->attachmentId
            || (int) $locked->message_id !== $prepared->messageId
            || max(1, (int) $locked->media_download_generation) !== $prepared->generation
            || $prepared->disk !== MessageAttachment::storageDiskName()
        ) {
            throw new MediaDownloadLeaseLostException;
        }

        if (
            $expectedClaimToken !== null
            && (
                $prepared->claimToken === null
                || ! hash_equals($prepared->claimToken, $expectedClaimToken)
            )
        ) {
            throw new MediaDownloadLeaseLostException;
        }

        if ($prepared->claimToken !== null) {
            $currentClaimToken = trim((string) $locked->media_download_claim_token);

            if (
                $locked->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
                || $currentClaimToken === ''
                || ! hash_equals($currentClaimToken, $prepared->claimToken)
                || $locked->media_download_attempt_deadline_at === null
                || $locked->media_download_attempt_deadline_at->isPast()
            ) {
                throw new MediaDownloadLeaseLostException;
            }
        } elseif (filled($locked->media_download_claim_token)) {
            throw new MediaDownloadLeaseLostException;
        }

        $locked->forceFill([
            ...$attachmentValues,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => $prepared->disk,
            'local_path' => $prepared->path,
            'file_size_bytes' => $prepared->sizeBytes,
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

        return $locked;
    }

    public function discardPreparedFile(PreparedMessageAttachmentFile $prepared): bool
    {
        return $this->deleteRolledBackInboundMediaFileAction->handle(
            $prepared->attachmentId,
            $prepared->disk,
            $prepared->path,
        );
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

    private function buildPreparedPath(
        MessageAttachment $attachment,
        mixed $extension,
        ?string $expectedClaimToken,
    ): string {
        $safeExtension = MessageAttachment::sanitizeExtension($extension) ?: 'bin';
        $generation = max(1, (int) $attachment->media_download_generation);
        $claimSegment = $expectedClaimToken !== null
            ? $this->safeClaimToken($expectedClaimToken)
            : 'unclaimed';

        return MessageAttachment::LOCAL_PATH_PREFIX
            .'/'.$attachment->message_id
            .'/'.$attachment->getKey()
            .'.g'.$generation
            .'.'.$claimSegment
            .'.commit.'.Str::uuid()->toString()
            .'.'.$safeExtension;
    }

    /**
     * Keep the lease alive while the AWS SDK is uploading or copying an object.
     * The callback is throttled because cURL may report progress very often.
     *
     * @return array<string, mixed>
     */
    private function storageProgressOptions(callable $onStorageProgress): array
    {
        $heartbeatIntervalSeconds = max(
            1,
            min(
                30,
                intdiv(max(3, (int) config('inbound_media.lease_stale_seconds', 120)), 3),
            ),
        );
        $lastHeartbeatAt = now()->getTimestamp();
        $heartbeat = static function (mixed ...$unused) use (
            $onStorageProgress,
            $heartbeatIntervalSeconds,
            &$lastHeartbeatAt,
        ): void {
            $currentTimestamp = now()->getTimestamp();

            if ($currentTimestamp - $lastHeartbeatAt < $heartbeatIntervalSeconds) {
                return;
            }

            $onStorageProgress();
            $lastHeartbeatAt = $currentTimestamp;
        };

        return [
            'before_upload' => $heartbeat,
            'params' => [
                '@http' => [
                    'progress' => $heartbeat,
                ],
            ],
        ];
    }

    /**
     * Keep a server-side S3 copy inside the lease window without shortening
     * the attempt deadline. cURL progress reports long requests while a
     * multipart copy also checkpoints between individual parts.
     *
     * @return array<string, mixed>
     */
    private function storageCopyProgressOptions(callable $onStorageProgress): array
    {
        $options = $this->storageProgressOptions($onStorageProgress);
        $options['mup_threshold'] = self::S3_COPY_MULTIPART_PART_BYTES;
        $options['part_size'] = self::S3_COPY_MULTIPART_PART_BYTES;

        return $options;
    }

    private function storageDiskUsesLocalDriver(string $disk, mixed $storage): bool
    {
        return $storage instanceof FilesystemAdapter
            && config("filesystems.disks.{$disk}.driver") === 'local';
    }

    private function copyLocalFileWithProgress(
        FilesystemAdapter $storage,
        string $sourcePath,
        string $destinationPath,
        callable $onStorageProgress,
    ): void {
        $directory = dirname($destinationPath);

        if ($directory !== '.' && ! $storage->makeDirectory($directory)) {
            throw new RuntimeException('Failed to create the message attachment copy directory.');
        }

        $source = @fopen($storage->path($sourcePath), 'rb');

        if ($source === false) {
            throw new RuntimeException('Failed to open the message attachment source file.');
        }

        $destination = null;

        try {
            $destination = @fopen($storage->path($destinationPath), 'xb');

            if ($destination === false) {
                throw new RuntimeException('Failed to open the message attachment destination file.');
            }

            while (! feof($source)) {
                $copiedBytes = stream_copy_to_stream(
                    $source,
                    $destination,
                    self::LOCAL_COPY_CHUNK_BYTES,
                );

                if ($copiedBytes === false || ($copiedBytes === 0 && ! feof($source))) {
                    throw new RuntimeException('Failed while copying the message attachment file.');
                }

                if ($copiedBytes > 0) {
                    $onStorageProgress();
                }
            }

            if (! fflush($destination)) {
                throw new RuntimeException('Failed to flush the message attachment destination file.');
            }
        } finally {
            if (is_resource($destination)) {
                fclose($destination);
            }

            fclose($source);
        }

        if (! $storage->setVisibility($destinationPath, 'private')) {
            throw new RuntimeException('Failed to protect the message attachment destination file.');
        }
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
