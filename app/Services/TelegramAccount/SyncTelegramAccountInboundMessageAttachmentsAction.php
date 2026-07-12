<?php

namespace App\Services\TelegramAccount;

use App\Data\TelegramAccount\NormalizedInboundMessageEvent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Dialogs\DialogAutomationGate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SyncTelegramAccountInboundMessageAttachmentsAction
{
    public function __construct(
        private readonly DialogAutomationGate $dialogAutomationGate,
        private readonly TelegramAccountMediaDownloadPolicy $mediaDownloadPolicy,
    ) {}

    public function handle(Message $message, NormalizedInboundMessageEvent $event): void
    {
        if ($event->media === []) {
            return;
        }

        $metadataOnly = ! $this->dialogAutomationGate->acceptsMessage($message);
        $channel = Channel::query()->findOrFail($event->channelId);

        foreach (array_values($event->media) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->syncAttachment($message, $event, $item, $index, $metadataOnly, $channel);
        }

        $message->unsetRelation('attachments');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function syncAttachment(
        Message $message,
        NormalizedInboundMessageEvent $event,
        array $item,
        int $index,
        bool $metadataOnly,
        Channel $channel,
    ): void {
        $mediaKind = $this->resolveMediaKind($item);
        $fileSizeBytes = $this->resolveFileSizeBytes($item);
        $downloadStatus = $metadataOnly
            ? MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY
            : $this->mediaDownloadPolicy->initialDownloadStatus($channel, $fileSizeBytes);
        $automaticMaxBytes = $metadataOnly
            ? null
            : $this->mediaDownloadPolicy->automaticMaxBytes($channel);
        $identity = [
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'channel_id' => $event->channelId,
            'provider_event_key' => $event->messageKey,
            'provider_attachment_key' => $this->resolveProviderAttachmentKey($item, $index, $mediaKind),
        ];
        $metadataValues = [
            'message_id' => $message->id,
            'media_kind' => $mediaKind,
            'mime_type' => $this->normalizeScalar(data_get($item, 'mime_type')),
            'extension' => $this->resolveExtension($item),
            'original_filename' => $this->normalizeScalar(data_get($item, 'file_name'))
                ?? $this->normalizeScalar(data_get($item, 'original_filename')),
            'file_size_bytes' => $fileSizeBytes,
            'provider_file_id' => $this->normalizeScalar(data_get($item, 'provider_file_id'))
                ?? $this->normalizeScalar(data_get($item, 'telegram_file_id'))
                ?? $this->normalizeScalar(data_get($item, 'file_id')),
            'provider_file_unique_id' => $this->normalizeScalar(data_get($item, 'provider_file_unique_id'))
                ?? $this->normalizeScalar(data_get($item, 'file_unique_id')),
            'provider_file_reference' => $this->normalizeScalar(data_get($item, 'provider_file_reference'))
                ?? $this->normalizeScalar(data_get($item, 'file_reference')),
            'provider_metadata' => $this->resolveProviderMetadata($item),
            'raw_payload_excerpt' => $this->resolveRawPayloadExcerpt($item),
            'sort_order' => $index,
        ];
        $createValues = [
            ...$metadataValues,
            'download_status' => $downloadStatus,
            'media_download_next_retry_at' => null,
            'media_download_max_bytes' => $automaticMaxBytes,
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'local_disk' => null,
            'local_path' => null,
            'safe_error_code' => match ($downloadStatus) {
                MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY => DialogAutomationGate::REASON_BLACKLIST_STAGE,
                MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND => TelegramAccountMediaDownloadPolicy::ERROR_AUTO_DOWNLOAD_LIMIT_EXCEEDED,
                default => $this->normalizeScalar(data_get($item, 'download_error_code')),
            },
            'safe_error_message' => $downloadStatus === MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY
                ? 'Media download skipped because the dialog stage is blacklisted.'
                : null,
        ];

        $this->createOrUpdateAttachment($identity, $createValues, $metadataValues);
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $createValues
     * @param  array<string, mixed>  $metadataValues
     */
    private function createOrUpdateAttachment(array $identity, array $createValues, array $metadataValues): void
    {
        DB::transaction(function () use ($identity, $createValues, $metadataValues): void {
            $attachment = MessageAttachment::query()
                ->where($identity)
                ->lockForUpdate()
                ->first();

            if ($attachment instanceof MessageAttachment) {
                $this->updateExistingAttachment($attachment, $createValues, $metadataValues);

                return;
            }

            try {
                MessageAttachment::query()->create([
                    ...$identity,
                    ...$createValues,
                ]);
            } catch (QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                $attachment = MessageAttachment::query()
                    ->where($identity)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->updateExistingAttachment($attachment, $createValues, $metadataValues);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $createValues
     * @param  array<string, mixed>  $metadataValues
     */
    private function updateExistingAttachment(MessageAttachment $attachment, array $createValues, array $metadataValues): void
    {
        $values = $this->shouldPreserveDownloadState($attachment)
            ? $metadataValues
            : $createValues;

        $attachment->forceFill($values)->save();
    }

    private function shouldPreserveDownloadState(MessageAttachment $attachment): bool
    {
        return in_array($attachment->download_status, [
            MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
        ], true)
            || (
                $attachment->download_status === MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY
                && $attachment->safe_error_code === DialogAutomationGate::REASON_BLACKLIST_STAGE
            )
            || $attachment->manual_download_requested_at !== null
            || filled($attachment->local_disk)
            || filled($attachment->local_path);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveMediaKind(array $item): string
    {
        return MessageAttachment::mediaKindFromLegacyType(
            $this->normalizeScalar(data_get($item, 'media_kind'))
                ?? $this->normalizeScalar(data_get($item, 'type'))
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveProviderAttachmentKey(array $item, int $index, string $mediaKind): string
    {
        return $this->normalizeScalar(data_get($item, 'provider_attachment_key'))
            ?? $this->normalizeScalar(data_get($item, 'attachment_key'))
            ?? sprintf('%d:%s', $index, $mediaKind);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveExtension(array $item): ?string
    {
        $extension = $this->normalizeScalar(data_get($item, 'extension'));

        if ($extension !== null) {
            return $extension;
        }

        $filename = $this->normalizeScalar(data_get($item, 'file_name'))
            ?? $this->normalizeScalar(data_get($item, 'original_filename'));

        if ($filename === null) {
            return null;
        }

        $derivedExtension = pathinfo($filename, PATHINFO_EXTENSION);

        return $derivedExtension !== '' ? $derivedExtension : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function resolveProviderMetadata(array $item): ?array
    {
        $metadata = is_array(data_get($item, 'provider_metadata'))
            ? data_get($item, 'provider_metadata')
            : [];

        foreach ([
            'width',
            'height',
            'duration',
            'media_group_id',
            'caption',
            'thumbnail_file_id',
            'thumbnail_file_unique_id',
            'is_video_note',
        ] as $key) {
            $value = data_get($item, $key);

            if (is_scalar($value)) {
                $metadata[$key] = $value;
            }
        }

        return $metadata !== [] ? $metadata : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function resolveRawPayloadExcerpt(array $item): array
    {
        return array_filter([
            'type' => $this->normalizeScalar(data_get($item, 'type')),
            'media_kind' => $this->normalizeScalar(data_get($item, 'media_kind')),
            'file_name' => $this->normalizeScalar(data_get($item, 'file_name')),
            'mime_type' => $this->normalizeScalar(data_get($item, 'mime_type')),
            'extension' => $this->normalizeScalar(data_get($item, 'extension')),
            'file_size_bytes' => $this->resolveFileSizeBytes($item),
            'download_status' => $this->normalizeScalar(data_get($item, 'download_status')),
            'is_video_note' => $this->normalizeBoolean(data_get($item, 'is_video_note')),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function normalizeScalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveFileSizeBytes(array $item): ?int
    {
        return $this->normalizeInteger(data_get($item, 'file_size_bytes'))
            ?? $this->normalizeInteger(data_get($item, 'file_size'))
            ?? $this->normalizeInteger(data_get($item, 'size'));
    }

    private function normalizeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    private function normalizeBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : null);
        }

        if (! is_string($value)) {
            return null;
        }

        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };
    }
}
