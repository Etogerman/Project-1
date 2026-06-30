<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Services\Messages\StoreMessageAttachmentLocalFileAction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StoreTelegramAccountMediaDownloadResultAction
{
    public const ERROR_GATEWAY_DOWNLOAD_FAILED = 'gateway_download_failed';

    public function __construct(
        private readonly StoreMessageAttachmentLocalFileAction $storeMessageAttachmentLocalFileAction,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markDownloaded(
        Channel $channel,
        MessageAttachment $attachment,
        string $contents,
        array $metadata = [],
    ): MessageAttachment {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        if (strlen($contents) > ClaimTelegramAccountMediaDownloadAction::maxBytes()) {
            return $this->markFailed($channel, $attachment, [
                'error_code' => ClaimTelegramAccountMediaDownloadAction::ERROR_FILE_TOO_LARGE,
                'error_message' => 'Telegram Account media file is larger than the local download limit.',
                'retryable' => false,
            ]);
        }

        return DB::transaction(function () use ($attachment, $contents, $metadata): MessageAttachment {
            /** @var MessageAttachment $locked */
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                return $locked;
            }

            $this->assertResultIsExpected($locked);

            $originalFilename = $this->normalizeNullableScalar($metadata['original_filename'] ?? null) ?? $locked->original_filename;
            $mimeType = $this->resolveDownloadedMimeType($locked, $contents, $metadata['mime_type'] ?? null);
            $extension = $this->resolveDownloadedExtension($locked, $mimeType, $originalFilename);

            $locked->forceFill([
                'mime_type' => $mimeType ?? $locked->mime_type,
                'extension' => $extension ?? $locked->extension,
                'original_filename' => $originalFilename,
                'file_size_bytes' => strlen($contents),
            ])->save();

            return $this->storeMessageAttachmentLocalFileAction->handle($locked, $contents);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function markFailed(
        Channel $channel,
        MessageAttachment $attachment,
        array $payload,
    ): MessageAttachment {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        return DB::transaction(function () use ($attachment, $payload): MessageAttachment {
            /** @var MessageAttachment $locked */
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                return $locked;
            }

            $this->assertResultIsExpected($locked);

            $retryable = (bool) ($payload['retryable'] ?? false);
            $errorCode = $this->normalizeErrorCode($payload['error_code'] ?? null) ?? self::ERROR_GATEWAY_DOWNLOAD_FAILED;
            $errorMessage = $this->normalizeSafeErrorMessage($payload['error_message'] ?? null)
                ?? 'Telegram Account Gateway failed to download media file.';

            $locked->forceFill([
                'download_status' => $retryable
                    ? MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD
                    : MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                'local_disk' => null,
                'local_path' => null,
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

    private function normalizeNullableScalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function resolveDownloadedMimeType(MessageAttachment $attachment, string $contents, mixed $mimeType): ?string
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

        return MessageAttachment::previewMimeTypeFromContents($contents) ?? $normalized;
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

        return mb_substr(trim($normalized), 0, 1000) ?: null;
    }
}
