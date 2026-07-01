<?php

namespace App\Services\Messages;

use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ResolveMessageMediaItemsAction
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(Message $message): array
    {
        $attachments = $this->resolveAttachments($message);

        if ($attachments->isNotEmpty()) {
            return $attachments
                ->map(fn (MessageAttachment $attachment): array => $this->normalizeAttachment($attachment))
                ->values()
                ->all();
        }

        return $this->normalizeLegacyMedia($message);
    }

    /**
     * @return EloquentCollection<int, MessageAttachment>
     */
    private function resolveAttachments(Message $message): EloquentCollection
    {
        if ($message->relationLoaded('attachments')) {
            /** @var EloquentCollection<int, MessageAttachment> $attachments */
            $attachments = $message->attachments;

            return $attachments;
        }

        /** @var EloquentCollection<int, MessageAttachment> $attachments */
        $attachments = $message->attachments()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $attachments;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAttachment(MessageAttachment $attachment): array
    {
        $mediaKind = MessageAttachment::normalizeMediaKind($attachment->media_kind);

        return [
            'source' => 'attachment',
            'attachment_id' => $attachment->id,
            'provider' => $attachment->provider,
            'media_kind' => $mediaKind,
            'type' => $mediaKind,
            'file_name' => $attachment->original_filename,
            'mime_type' => $attachment->mime_type,
            'extension' => $attachment->extension,
            'file_size_bytes' => $attachment->file_size_bytes,
            'duration' => $this->normalizeInteger(data_get($attachment->provider_metadata, 'duration')),
            'is_video_note' => $mediaKind === MessageAttachment::MEDIA_KIND_VIDEO_NOTE
                || data_get($attachment->provider_metadata, 'is_video_note') === true,
            'download_status' => MessageAttachment::normalizeDownloadStatus($attachment->download_status),
            'send_status' => MessageAttachment::normalizeSendStatus($attachment->send_status),
            'is_locally_downloadable' => $attachment->isLocallyDownloadable(),
            'is_inline_previewable' => $attachment->isInlinePreviewable(),
            'preview_kind' => $attachment->previewKind(),
            'safe_error_code' => $attachment->safe_error_code,
            'safe_error_message' => $attachment->safe_error_message,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeLegacyMedia(Message $message): array
    {
        $media = data_get($message->raw_payload, 'media');

        if (! is_array($media) || $media === []) {
            return [];
        }

        $items = [];

        foreach (array_values($media) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = $this->normalizeLegacyMediaItem($message, $item, $index);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeLegacyMediaItem(Message $message, array $item, int $index): array
    {
        $legacyType = $this->normalizeScalar(data_get($item, 'type'));
        $mediaKind = MessageAttachment::mediaKindFromLegacyType($legacyType);

        return [
            'source' => 'legacy_raw_payload',
            'legacy_index' => $index,
            'media_kind' => $mediaKind,
            'type' => $mediaKind,
            'legacy_type' => $legacyType,
            'file_name' => $this->normalizeScalar(data_get($item, 'file_name')),
            'mime_type' => $this->normalizeScalar(data_get($item, 'mime_type')),
            'extension' => $this->normalizeScalar(data_get($item, 'extension')),
            'file_size_bytes' => $this->normalizeInteger(data_get($item, 'file_size_bytes')),
            'download_status' => $this->resolveLegacyDownloadStatus($message, $item),
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'is_locally_downloadable' => false,
            'is_inline_previewable' => false,
            'preview_kind' => null,
            'safe_error_code' => $this->normalizeScalar(data_get($item, 'download_error_code')),
            'safe_error_message' => $this->normalizeScalar(data_get($item, 'download_error_message')),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveLegacyDownloadStatus(Message $message, array $item): ?string
    {
        $status = MessageAttachment::downloadStatusFromLegacyStatus(
            $this->normalizeScalar(data_get($item, 'download_status'))
        );

        if ($status !== null) {
            return $status;
        }

        return $this->usesTelegramAccountMediaPlaceholderContract($message)
            ? MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD
            : null;
    }

    private function usesTelegramAccountMediaPlaceholderContract(Message $message): bool
    {
        $channel = $message->channel ?? $message->dialog?->channel;

        return $channel instanceof Channel
            && $channel->platform === Channel::PLATFORM_TELEGRAM
            && $channel->isAccountConnection();
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
}
