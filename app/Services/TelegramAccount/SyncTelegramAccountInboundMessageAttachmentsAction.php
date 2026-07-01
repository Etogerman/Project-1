<?php

namespace App\Services\TelegramAccount;

use App\Data\TelegramAccount\NormalizedInboundMessageEvent;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SyncTelegramAccountInboundMessageAttachmentsAction
{
    public function handle(Message $message, NormalizedInboundMessageEvent $event): void
    {
        if ($event->media === []) {
            return;
        }

        foreach (array_values($event->media) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->syncAttachment($message, $event, $item, $index);
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
    ): void {
        $mediaKind = $this->resolveMediaKind($item);
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
            'file_size_bytes' => $this->normalizeInteger(data_get($item, 'file_size_bytes'))
                ?? $this->normalizeInteger(data_get($item, 'file_size')),
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
            'download_status' => $this->resolveDownloadStatus($item),
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'local_disk' => null,
            'local_path' => null,
            'safe_error_code' => $this->normalizeScalar(data_get($item, 'download_error_code')),
            'safe_error_message' => $this->normalizeScalar(data_get($item, 'download_error_message')),
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
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
        ], true)
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
     */
    private function resolveDownloadStatus(array $item): string
    {
        return MessageAttachment::downloadStatusFromLegacyStatus(
            $this->normalizeScalar(data_get($item, 'download_status'))
        ) ?? MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD;
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
            'file_size_bytes' => $this->normalizeInteger(data_get($item, 'file_size_bytes')),
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
