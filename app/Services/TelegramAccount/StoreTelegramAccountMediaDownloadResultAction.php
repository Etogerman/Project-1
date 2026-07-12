<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\Dialog;
use App\Models\MessageAttachment;
use App\Services\Dialogs\DialogAutomationGate;
use App\Services\Messages\StoreMessageAttachmentLocalFileAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class StoreTelegramAccountMediaDownloadResultAction
{
    public const ERROR_GATEWAY_DOWNLOAD_FAILED = 'gateway_download_failed';

    public function __construct(
        private readonly StoreMessageAttachmentLocalFileAction $storeMessageAttachmentLocalFileAction,
        private readonly DialogAutomationGate $dialogAutomationGate,
    ) {}

    public function acknowledgeHandledResult(
        Channel $channel,
        MessageAttachment $attachment,
    ): ?MessageAttachment {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        return DB::transaction(function () use ($attachment): ?MessageAttachment {
            /** @var MessageAttachment $locked */
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (filled($locked->media_download_claim_token)) {
                return null;
            }

            if (! in_array($locked->download_status, [
                MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
                MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
                MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            ], true)) {
                return null;
            }

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markDownloaded(
        Channel $channel,
        MessageAttachment $attachment,
        string $contents,
        ?string $claimToken,
        array $metadata = [],
    ): MessageAttachment {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new InvalidArgumentException('Failed to open temporary attachment stream.');
        }

        try {
            $writtenBytes = fwrite($stream, $contents);

            if ($writtenBytes === false || $writtenBytes !== strlen($contents)) {
                throw new InvalidArgumentException('Failed to write attachment contents to temporary stream.');
            }

            rewind($stream);

            return $this->markDownloadedFromStream($channel, $attachment, $stream, $claimToken, [
                ...$metadata,
                'file_size_bytes' => strlen($contents),
                'detected_contents' => $contents,
            ]);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $metadata
     */
    public function markDownloadedFromStream(
        Channel $channel,
        MessageAttachment $attachment,
        mixed $stream,
        ?string $claimToken,
        array $metadata = [],
    ): MessageAttachment {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Telegram Account media result must provide a stream.');
        }

        return DB::transaction(function () use ($attachment, $stream, $claimToken, $metadata): MessageAttachment {
            /** @var MessageAttachment $locked */
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertClaimToken($locked, $claimToken);

            if ($locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                return $locked;
            }

            $this->assertResultIsExpected($locked);

            if ($this->shouldSuppressAutomaticDownload($locked)) {
                return $this->markLockedMetadataOnlyBecauseBlacklisted($locked, $claimToken);
            }

            $originalFilename = $this->normalizeNullableScalar($metadata['original_filename'] ?? null) ?? $locked->original_filename;
            $detectedContents = is_string($metadata['detected_contents'] ?? null)
                ? $metadata['detected_contents']
                : $this->readDetectionSample($stream);
            $mimeType = $this->resolveDownloadedMimeType($locked, $detectedContents, $metadata['mime_type'] ?? null);
            $extension = $this->resolveDownloadedExtension($locked, $mimeType, $originalFilename);
            $fileSizeBytes = $this->normalizeFileSize($metadata['file_size_bytes'] ?? null)
                ?? $locked->file_size_bytes;

            $locked->forceFill([
                'mime_type' => $mimeType ?? $locked->mime_type,
                'extension' => $extension ?? $locked->extension,
                'original_filename' => $originalFilename,
                'file_size_bytes' => $fileSizeBytes,
            ])->save();

            return $this->storeMessageAttachmentLocalFileAction->handleStream(
                $locked,
                $stream,
                $fileSizeBytes,
                $extension,
            );
        });
    }

    public function markAvailableOnDemand(
        Channel $channel,
        MessageAttachment $attachment,
        ?string $claimToken,
    ): MessageAttachment {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        return DB::transaction(function () use ($attachment, $claimToken): MessageAttachment {
            /** @var MessageAttachment $locked */
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $locked->download_status === MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND
                && blank($locked->media_download_claim_token)
                && filled($claimToken)
            ) {
                return $locked;
            }

            $this->assertClaimToken($locked, $claimToken);

            if ($locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                return $locked;
            }

            $this->assertResultIsExpected($locked);

            if ($this->shouldSuppressAutomaticDownload($locked)) {
                return $this->markLockedMetadataOnlyBecauseBlacklisted($locked, $claimToken);
            }

            $this->deleteDirectUploadOrFail($locked, $claimToken);

            $locked->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
                'media_download_claim_token' => null,
                'local_disk' => null,
                'local_path' => null,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => null,
                'safe_error_code' => TelegramAccountMediaDownloadPolicy::ERROR_AUTO_DOWNLOAD_LIMIT_EXCEEDED,
                'safe_error_message' => null,
            ])->save();

            return $locked->fresh();
        });
    }

    public function directUploadSize(Channel $channel, MessageAttachment $attachment, string $claimToken): int
    {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        if ($attachment->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
            $completedPath = $this->storeMessageAttachmentLocalFileAction->buildDirectUploadPath($attachment, $claimToken);

            if ($attachment->local_path === $completedPath) {
                return max(0, (int) $attachment->file_size_bytes);
            }
        }

        $this->assertClaimToken($attachment, $claimToken);

        $this->assertResultIsExpected($attachment);

        $disk = MessageAttachment::storageDiskName();
        $path = $this->storeMessageAttachmentLocalFileAction->buildDirectUploadPath($attachment, $claimToken);

        if (! Storage::disk($disk)->exists($path)) {
            throw new InvalidArgumentException('Direct Telegram Account media upload was not found.');
        }

        $storedSize = (int) Storage::disk($disk)->size($path);
        $expectedSize = $attachment->media_download_upload_size_bytes;

        if ($expectedSize !== null && (int) $expectedSize !== $storedSize) {
            throw new InvalidArgumentException('Direct media upload is incomplete.');
        }

        return $storedSize;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markDownloadedFromDirectUpload(
        Channel $channel,
        MessageAttachment $attachment,
        string $claimToken,
        array $metadata = [],
    ): MessageAttachment {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        return DB::transaction(function () use ($attachment, $claimToken, $metadata): MessageAttachment {
            /** @var MessageAttachment $locked */
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                $completedPath = $this->storeMessageAttachmentLocalFileAction->buildDirectUploadPath($locked, $claimToken);

                if ($locked->local_path === $completedPath) {
                    return $locked;
                }
            }

            $this->assertClaimToken($locked, $claimToken);

            if ($locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                return $locked;
            }

            $this->assertResultIsExpected($locked);

            if ($this->shouldSuppressAutomaticDownload($locked)) {
                return $this->markLockedMetadataOnlyBecauseBlacklisted($locked, $claimToken);
            }

            $disk = MessageAttachment::storageDiskName();
            $uploadedPath = $this->storeMessageAttachmentLocalFileAction->buildDirectUploadPath($locked, $claimToken);

            if (! Storage::disk($disk)->exists($uploadedPath)) {
                throw new InvalidArgumentException('Direct Telegram Account media upload was not found.');
            }

            $stream = Storage::disk($disk)->readStream($uploadedPath);

            if (! is_resource($stream)) {
                throw new InvalidArgumentException('Direct Telegram Account media upload cannot be read.');
            }

            try {
                $detectedContents = $this->readDetectionSample($stream);
            } finally {
                fclose($stream);
            }

            $fileSizeBytes = (int) Storage::disk($disk)->size($uploadedPath);
            $reportedFileSizeBytes = $this->normalizeFileSize($metadata['file_size_bytes'] ?? null);
            $expectedUploadSizeBytes = $locked->media_download_upload_size_bytes;

            if ($reportedFileSizeBytes === null || $reportedFileSizeBytes !== $fileSizeBytes) {
                throw new InvalidArgumentException('Direct media upload size does not match reported file size.');
            }

            if ($expectedUploadSizeBytes !== null && (int) $expectedUploadSizeBytes !== $fileSizeBytes) {
                throw new InvalidArgumentException('Direct media upload is incomplete.');
            }

            $originalFilename = $this->normalizeNullableScalar($metadata['original_filename'] ?? null) ?? $locked->original_filename;
            $mimeType = $this->resolveDownloadedMimeType($locked, $detectedContents, $metadata['mime_type'] ?? null);
            $extension = $this->resolveDownloadedExtension($locked, $mimeType, $originalFilename);

            $locked->forceFill([
                'mime_type' => $mimeType ?? $locked->mime_type,
                'extension' => $extension ?? $locked->extension,
                'original_filename' => $originalFilename,
                'file_size_bytes' => $fileSizeBytes,
                'media_download_claim_token' => null,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => null,
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
                'local_disk' => $disk,
                'local_path' => $uploadedPath,
                'safe_error_code' => null,
                'safe_error_message' => null,
            ])->save();

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function markFailed(
        Channel $channel,
        MessageAttachment $attachment,
        ?string $claimToken,
        array $payload,
    ): MessageAttachment {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        return DB::transaction(function () use ($attachment, $claimToken, $payload): MessageAttachment {
            /** @var MessageAttachment $locked */
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $errorCode = $this->normalizeErrorCode($payload['error_code'] ?? null) ?? self::ERROR_GATEWAY_DOWNLOAD_FAILED;

            if (
                blank($locked->media_download_claim_token)
                && filled($claimToken)
                && in_array($locked->download_status, [
                    MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
                    MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                ], true)
                && $locked->safe_error_code === $errorCode
            ) {
                return $locked;
            }

            $this->assertClaimToken($locked, $claimToken);

            if ($locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                return $locked;
            }

            $this->assertResultIsExpected($locked);

            if ($this->shouldSuppressAutomaticDownload($locked)) {
                return $this->markLockedMetadataOnlyBecauseBlacklisted($locked, $claimToken);
            }

            $retryable = (bool) ($payload['retryable'] ?? false);
            $errorMessage = $this->normalizeSafeErrorMessage($payload['error_message'] ?? null)
                ?? 'Telegram Account Gateway failed to download media file.';

            $this->deleteDirectUploadOrFail($locked, $claimToken);

            if (
                $errorCode === ClaimTelegramAccountMediaDownloadAction::ERROR_FILE_TOO_LARGE
                && $locked->manual_download_requested_at === null
            ) {
                $locked->forceFill([
                    'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
                    'media_download_claim_token' => null,
                    'local_disk' => null,
                    'local_path' => null,
                    'media_download_upload_size_bytes' => null,
                    'media_download_next_retry_at' => null,
                    'safe_error_code' => TelegramAccountMediaDownloadPolicy::ERROR_AUTO_DOWNLOAD_LIMIT_EXCEEDED,
                    'safe_error_message' => null,
                ])->save();

                return $locked->fresh();
            }

            $locked->forceFill([
                'download_status' => $retryable
                    ? MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD
                    : MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                'media_download_claim_token' => null,
                'local_disk' => null,
                'local_path' => null,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => $retryable ? $this->nextRetryAt() : null,
                'safe_error_code' => $errorCode,
                'safe_error_message' => $errorMessage,
            ])->save();

            return $locked->fresh();
        });
    }

    private function assertAttachmentBelongsToChannel(Channel $channel, MessageAttachment $attachment): void
    {
        if (
            (int) $attachment->channel_id !== (int) $channel->id
            || $attachment->provider !== MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT
        ) {
            throw new InvalidArgumentException('Message attachment does not belong to Telegram Account route channel.');
        }
    }

    private function assertResultIsExpected(MessageAttachment $attachment): void
    {
        if ($attachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING) {
            throw new InvalidArgumentException('Media download result is not expected for current attachment state.');
        }
    }

    private function assertClaimToken(MessageAttachment $attachment, ?string $claimToken): void
    {
        $expected = trim((string) $attachment->media_download_claim_token);
        $provided = trim((string) $claimToken);

        if ($expected === '' && $provided === '') {
            return;
        }

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            throw new InvalidArgumentException('Media download claim token is no longer current.');
        }
    }

    private function shouldSuppressAutomaticDownload(MessageAttachment $attachment): bool
    {
        if ($attachment->manual_download_requested_at !== null) {
            return false;
        }

        $attachment->loadMissing('message');
        $dialogId = $attachment->message?->dialog_id;

        if ($dialogId === null) {
            return false;
        }

        $dialog = Dialog::query()
            ->with('dialogStage')
            ->whereKey($dialogId)
            ->lockForUpdate()
            ->first();

        return ! $this->dialogAutomationGate->accepts($dialog);
    }

    private function markLockedMetadataOnlyBecauseBlacklisted(
        MessageAttachment $attachment,
        ?string $claimToken,
    ): MessageAttachment {
        $this->deleteDirectUploadOrFail($attachment, $claimToken);

        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
            'media_download_claim_token' => null,
            'local_disk' => null,
            'local_path' => null,
            'media_download_upload_size_bytes' => null,
            'media_download_next_retry_at' => null,
            'safe_error_code' => DialogAutomationGate::REASON_BLACKLIST_STAGE,
            'safe_error_message' => 'Media download skipped because the dialog stage is blacklisted.',
        ])->save();

        return $attachment->fresh();
    }

    private function normalizeNullableScalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function nextRetryAt(): Carbon
    {
        $delaySeconds = max(
            1,
            (int) config('bots.telegram_account.media_download_retry_delay_seconds', 60),
        );

        return now()->addSeconds($delaySeconds);
    }

    private function resolveDownloadedMimeType(MessageAttachment $attachment, ?string $contents, mixed $mimeType): ?string
    {
        $normalized = $this->normalizeNullableScalar($mimeType);

        if (! MessageAttachment::isGenericMimeType($normalized)) {
            return $normalized;
        }

        if (! in_array($attachment->media_kind, [
            MessageAttachment::MEDIA_KIND_IMAGE,
            MessageAttachment::MEDIA_KIND_STICKER,
        ], true)) {
            return $normalized;
        }

        return $contents === null
            ? $normalized
            : (MessageAttachment::previewMimeTypeFromContents($contents) ?? $normalized);
    }

    private function normalizeFileSize(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  resource  $stream
     */
    private function readDetectionSample(mixed $stream): ?string
    {
        $position = ftell($stream);

        if ($position === false || fseek($stream, 0) !== 0) {
            return null;
        }

        $sample = fread($stream, 64 * 1024);

        if (fseek($stream, $position) !== 0) {
            throw new InvalidArgumentException('Failed to restore Telegram Account media stream position.');
        }

        return is_string($sample) && $sample !== '' ? $sample : null;
    }

    private function deleteDirectUploadOrFail(MessageAttachment $attachment, ?string $claimToken): void
    {
        if (! filled($claimToken)) {
            return;
        }

        $disk = MessageAttachment::storageDiskName();
        $path = $this->storeMessageAttachmentLocalFileAction->buildDirectUploadPath($attachment, (string) $claimToken);

        if (Storage::disk($disk)->exists($path) && ! Storage::disk($disk)->delete($path)) {
            throw new InvalidArgumentException('Temporary media upload could not be removed.');
        }
    }

    private function resolveDownloadedExtension(
        MessageAttachment $attachment,
        ?string $mimeType,
        ?string $originalFilename,
    ): ?string {
        $currentExtension = MessageAttachment::sanitizeExtension($attachment->extension);

        if ($currentExtension !== '') {
            return $currentExtension;
        }

        $filenameExtension = MessageAttachment::sanitizeExtension(pathinfo((string) $originalFilename, PATHINFO_EXTENSION));

        if ($filenameExtension !== '') {
            return $filenameExtension;
        }

        if (! in_array($attachment->media_kind, [
            MessageAttachment::MEDIA_KIND_IMAGE,
            MessageAttachment::MEDIA_KIND_STICKER,
        ], true)) {
            return null;
        }

        return MessageAttachment::previewExtensionForMimeType($mimeType);
    }

    private function normalizeErrorCode(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableScalar($value);

        if ($normalized === null) {
            return null;
        }

        $normalized = mb_strtolower($normalized);
        $normalized = preg_replace('/[^a-z0-9_:-]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_:-');

        return $normalized !== '' ? mb_substr($normalized, 0, 64) : null;
    }

    private function normalizeSafeErrorMessage(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableScalar($value);

        if ($normalized === null) {
            return null;
        }

        $normalized = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $normalized) ?? '';
        $normalized = preg_replace(
            '~(["\'])(/(?!/)[^"\'\r\n]*)\1~u',
            '$1[redacted-path]$1',
            $normalized,
        ) ?? '';
        $normalized = preg_replace(
            '~(["\'])([A-Za-z]:\\\\[^"\'\r\n]*)\1~u',
            '$1[redacted-path]$1',
            $normalized,
        ) ?? '';
        $normalized = preg_replace(
            '~(?<![A-Za-z0-9:/])/(?!/)[^\s"\'<>()[\]{};,!?]+~u',
            '[redacted-path]',
            $normalized,
        ) ?? '';
        $normalized = preg_replace(
            '~\b[A-Za-z]:\\\\[^\s"\'<>()[\]{};,!?]+~u',
            '[redacted-path]',
            $normalized,
        ) ?? '';

        return mb_substr(trim($normalized), 0, 1000) ?: null;
    }
}
