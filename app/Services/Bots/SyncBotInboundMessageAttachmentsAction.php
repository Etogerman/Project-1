<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Dialogs\DialogAutomationGate;
use App\Services\Messages\InboundMediaDownloadPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SyncBotInboundMessageAttachmentsAction
{
    public function __construct(
        private readonly InboundMediaDownloadPolicy $mediaDownloadPolicy,
        private readonly DialogAutomationGate $dialogAutomationGate,
    ) {}

    public function handle(Channel $channel, Message $message, IncomingBotMessage $incomingMessage): void
    {
        if ($incomingMessage->media === [] || ! filled($incomingMessage->providerEventKey)) {
            return;
        }

        $provider = $this->resolveProvider($channel);

        if ($provider === null) {
            return;
        }

        $message->loadMissing('dialog.dialogStage');
        $automaticDownloadAllowed = $this->dialogAutomationGate->acceptsMessage($message);

        foreach (array_values($incomingMessage->media) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->syncAttachment(
                $channel,
                $provider,
                $message,
                $incomingMessage,
                $item,
                $index,
                $automaticDownloadAllowed,
            );
        }

        $message->unsetRelation('attachments');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function syncAttachment(
        Channel $channel,
        string $provider,
        Message $message,
        IncomingBotMessage $incomingMessage,
        array $item,
        int $fallbackSortOrder,
        bool $automaticDownloadAllowed,
    ): void {
        $providerEventKey = $this->normalizeScalar($incomingMessage->providerEventKey);
        $providerAttachmentKey = $this->normalizeScalar(data_get($item, 'provider_attachment_key'));

        if ($providerEventKey === null || $providerAttachmentKey === null) {
            return;
        }

        $mediaKind = MessageAttachment::mediaKindFromLegacyType(
            $this->normalizeScalar(data_get($item, 'media_kind'))
                ?? $this->normalizeScalar(data_get($item, 'type'))
        );
        $fileSizeBytes = $this->normalizeInteger(data_get($item, 'file_size_bytes'))
            ?? $this->normalizeInteger(data_get($item, 'file_size'));
        $downloadDecision = $this->mediaDownloadPolicy->initialDecision(
            $channel,
            $provider,
            $mediaKind,
            $fileSizeBytes,
            $automaticDownloadAllowed,
        );

        $identity = [
            'provider' => $provider,
            'channel_id' => $incomingMessage->channelId,
            'provider_event_key' => $providerEventKey,
            'provider_attachment_key' => $providerAttachmentKey,
        ];
        $metadataValues = [
            'message_id' => $message->id,
            'media_kind' => $mediaKind,
            'mime_type' => $this->normalizeScalar(data_get($item, 'mime_type')),
            'extension' => $this->normalizeScalar(data_get($item, 'extension')),
            'original_filename' => $this->normalizeScalar(data_get($item, 'file_name'))
                ?? $this->normalizeScalar(data_get($item, 'original_filename')),
            'file_size_bytes' => $fileSizeBytes,
            'provider_file_id' => $this->normalizeScalar(data_get($item, 'provider_file_id')),
            'provider_file_unique_id' => $this->normalizeScalar(data_get($item, 'provider_file_unique_id')),
            'provider_file_reference' => $this->normalizeScalar(data_get($item, 'provider_file_reference')),
            'provider_metadata' => $this->resolveProviderMetadata($item),
            'raw_payload_excerpt' => $this->resolveRawPayloadExcerpt($item),
            'sort_order' => $this->normalizeInteger(data_get($item, 'sort_order')) ?? $fallbackSortOrder,
        ];
        $createValues = [
            ...$metadataValues,
            'download_status' => $downloadDecision['status'],
            'media_download_max_bytes' => $this->mediaDownloadPolicy->automaticRequestMaxBytes($channel, $provider),
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'local_disk' => null,
            'local_path' => null,
            'safe_error_code' => $downloadDecision['reason'],
            'safe_error_message' => $downloadDecision['message'],
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
        return $attachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY
            || $attachment->manual_download_requested_at !== null
            || filled($attachment->safe_error_code)
            || filled($attachment->local_disk)
            || filled($attachment->local_path);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true);
    }

    private function resolveProvider(Channel $channel): ?string
    {
        if ($channel->connection_type !== Channel::CONNECTION_TYPE_BOT) {
            return null;
        }

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            Channel::PLATFORM_MAX => MessageAttachment::PROVIDER_MAX_BOT,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function resolveProviderMetadata(array $item): ?array
    {
        $metadata = [];

        foreach ([
            'width',
            'height',
            'duration',
            'is_video_note',
            'media_group_id',
            'caption',
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
        $excerpt = data_get($item, 'raw_payload_excerpt');

        if (is_array($excerpt)) {
            return array_filter([
                'type' => $this->normalizeScalar(data_get($excerpt, 'type')),
                'media_kind' => $this->normalizeScalar(data_get($excerpt, 'media_kind')),
                'file_unique_id' => $this->normalizeScalar(data_get($excerpt, 'file_unique_id')),
                'photo_id' => $this->normalizeScalar(data_get($excerpt, 'photo_id')),
                'sticker_code' => $this->normalizeScalar(data_get($excerpt, 'sticker_code')),
                'emoji' => $this->normalizeScalar(data_get($excerpt, 'emoji')),
                'file_size_bytes' => $this->normalizeInteger(data_get($excerpt, 'file_size_bytes')),
                'duration' => $this->normalizeInteger(data_get($excerpt, 'duration')),
                'mime_type' => $this->normalizeScalar(data_get($excerpt, 'mime_type')),
                'width' => $this->normalizeInteger(data_get($excerpt, 'width')),
                'height' => $this->normalizeInteger(data_get($excerpt, 'height')),
                'is_video_note' => $this->normalizeBoolean(data_get($excerpt, 'is_video_note')),
                'is_animated' => $this->normalizeBoolean(data_get($excerpt, 'is_animated')),
                'is_video' => $this->normalizeBoolean(data_get($excerpt, 'is_video')),
                'thumbnail_file_id' => $this->normalizeScalar(data_get($excerpt, 'thumbnail_file_id')),
                'thumbnail_file_unique_id' => $this->normalizeScalar(data_get($excerpt, 'thumbnail_file_unique_id')),
                'thumbnail_width' => $this->normalizeInteger(data_get($excerpt, 'thumbnail_width')),
                'thumbnail_height' => $this->normalizeInteger(data_get($excerpt, 'thumbnail_height')),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return array_filter([
            'type' => $this->normalizeScalar(data_get($item, 'type')),
            'media_kind' => $this->normalizeScalar(data_get($item, 'media_kind')),
            'file_unique_id' => $this->normalizeScalar(data_get($item, 'provider_file_unique_id')),
            'photo_id' => $this->normalizeScalar(data_get($item, 'provider_file_reference')),
            'sticker_code' => $this->normalizeScalar(data_get($item, 'sticker_code')),
            'file_size_bytes' => $this->normalizeInteger(data_get($item, 'file_size_bytes')),
            'duration' => $this->normalizeInteger(data_get($item, 'duration')),
            'mime_type' => $this->normalizeScalar(data_get($item, 'mime_type')),
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
