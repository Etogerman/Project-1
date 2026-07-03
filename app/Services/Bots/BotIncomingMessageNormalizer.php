<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessage;
use App\Data\Bots\IncomingBotMessageEdit;
use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Services\TelegramAccount\NormalizeTelegramAccountRichTextAction;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

class BotIncomingMessageNormalizer
{
    public function __construct(
        private readonly NormalizeTelegramAccountRichTextAction $normalizeTelegramRichTextAction,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalize(Channel $channel, array $payload): ?IncomingBotMessage
    {
        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->normalizeTelegram($channel, $payload),
            Channel::PLATFORM_MAX => $this->normalizeMax($channel, $payload),
            default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalizeEdit(Channel $channel, array $payload): ?IncomingBotMessageEdit
    {
        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->normalizeTelegramEdit($channel, $payload),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function normalizeTelegram(Channel $channel, array $payload): ?IncomingBotMessage
    {
        $callbackQuery = $payload['callback_query'] ?? null;

        if (is_array($callbackQuery)) {
            return $this->normalizeTelegramCallbackQuery($channel, $payload, $callbackQuery);
        }

        $chatMemberUpdate = $payload['my_chat_member'] ?? null;

        if (is_array($chatMemberUpdate)) {
            return $this->normalizeTelegramChatMemberUpdate($channel, $payload, $chatMemberUpdate);
        }

        $message = $payload['message'] ?? null;

        if (! is_array($message) || data_get($message, 'from.is_bot') === true) {
            return null;
        }

        if (data_get($message, 'chat.type') !== 'private') {
            return null;
        }

        $chatId = $this->normalizeExternalId(data_get($message, 'chat.id'));
        $userId = $this->normalizeExternalId(data_get($message, 'from.id'));

        if (! filled($chatId) || ! filled($userId)) {
            return null;
        }

        [$normalizedText, $richText] = $this->resolveTelegramTextAndRichText($message);
        $providerGroupKey = $this->normalizeProviderGroupKey(data_get($message, 'media_group_id'));

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            providerEventKey: $this->normalizeExternalId($payload['update_id'] ?? null),
            externalMessageId: $this->normalizeExternalId(data_get($message, 'message_id')),
            externalUsername: $this->normalizeUsername(data_get($message, 'from.username')),
            contactName: $this->resolvePersonName(data_get($message, 'from')),
            text: $normalizedText,
            inboundKind: filled(data_get($message, 'contact.phone_number'))
                ? IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE
                : IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: $this->normalizeText(data_get($message, 'contact.phone_number')),
            sharedContactUserId: $this->normalizeExternalId(data_get($message, 'contact.user_id')),
            rawPayload: $payload,
            receivedAt: $this->resolveReceivedAt([
                data_get($message, 'date'),
            ]),
            messageParameter: $this->resolveTelegramStartMessageParameter($normalizedText),
            media: $this->normalizeTelegramMedia($message),
            providerGroupKey: $providerGroupKey,
            richText: $richText,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function normalizeTelegramEdit(Channel $channel, array $payload): ?IncomingBotMessageEdit
    {
        $message = $payload['edited_message'] ?? null;

        if (! is_array($message) || data_get($message, 'from.is_bot') === true) {
            return null;
        }

        if (data_get($message, 'chat.type') !== 'private') {
            return null;
        }

        $chatId = $this->normalizeExternalId(data_get($message, 'chat.id'));
        $userId = $this->normalizeExternalId(data_get($message, 'from.id'));
        $externalMessageId = $this->normalizeExternalId(data_get($message, 'message_id'));

        if (! filled($chatId) || ! filled($userId) || ! filled($externalMessageId)) {
            return null;
        }

        $hasTextContent = array_key_exists('text', $message) || array_key_exists('caption', $message);
        [$normalizedText, $richText] = $this->resolveTelegramTextAndRichText($message);

        return new IncomingBotMessageEdit(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            providerEventKey: $this->normalizeExternalId($payload['update_id'] ?? null),
            externalMessageId: $externalMessageId,
            externalUsername: $this->normalizeUsername(data_get($message, 'from.username')),
            contactName: $this->resolvePersonName(data_get($message, 'from')),
            text: $normalizedText,
            richText: $richText,
            hasTextContent: $hasTextContent,
            rawPayload: $payload,
            editedAt: $this->resolveReceivedAt([
                data_get($message, 'edit_date'),
                data_get($message, 'date'),
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{0: ?string, 1: array<string, mixed>|null}
     */
    protected function resolveTelegramTextAndRichText(array $message): array
    {
        if (array_key_exists('text', $message)) {
            $plainText = $this->normalizeText(data_get($message, 'text'));

            return [
                $plainText,
                $this->normalizeTelegramRichText($plainText, data_get($message, 'entities')),
            ];
        }

        if (array_key_exists('caption', $message)) {
            $plainText = $this->normalizeText(data_get($message, 'caption'));

            return [
                $plainText,
                $this->normalizeTelegramRichText($plainText, data_get($message, 'caption_entities')),
            ];
        }

        return [
            $this->resolveTelegramLocationDisplayText($message)
                ?? $this->resolveTelegramSpecialDisplayText($message),
            null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function normalizeTelegramRichText(?string $plainText, mixed $entities): ?array
    {
        if (! is_string($plainText) || ! is_array($entities)) {
            return null;
        }

        return $this->normalizeTelegramRichTextAction->handle($plainText, [
            'text' => $plainText,
            'entities' => $entities,
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    protected function normalizeTelegramMedia(array $message): array
    {
        return [
            ...$this->normalizeTelegramPhotoMedia($message),
            ...$this->normalizeTelegramAnimationMedia($message),
            ...$this->normalizeTelegramStickerMedia($message),
            ...$this->normalizeTelegramDocumentMedia($message),
            ...$this->normalizeTelegramVideoNoteMedia($message),
            ...$this->normalizeTelegramVideoMedia($message),
            ...$this->normalizeTelegramAudioMedia($message),
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    protected function normalizeTelegramPhotoMedia(array $message): array
    {
        $photos = data_get($message, 'photo');

        if (! is_array($photos) || $photos === []) {
            return [];
        }

        $bestPhoto = null;
        $bestScore = -1;

        foreach ($photos as $photo) {
            if (! is_array($photo)) {
                continue;
            }

            $fileId = $this->normalizeExternalId(data_get($photo, 'file_id'));
            $fileUniqueId = $this->normalizeExternalId(data_get($photo, 'file_unique_id'));

            if (! filled($fileId) || ! filled($fileUniqueId)) {
                continue;
            }

            $fileSize = $this->normalizeNonNegativeInteger(data_get($photo, 'file_size'));
            $width = $this->normalizeNonNegativeInteger(data_get($photo, 'width'));
            $height = $this->normalizeNonNegativeInteger(data_get($photo, 'height'));
            $score = $fileSize ?? (($width ?? 0) * ($height ?? 0));

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPhoto = [
                    'file_id' => $fileId,
                    'file_unique_id' => $fileUniqueId,
                    'file_size_bytes' => $fileSize,
                    'width' => $width,
                    'height' => $height,
                ];
            }
        }

        if ($bestPhoto === null) {
            return [];
        }

        $providerGroupKey = $this->normalizeProviderGroupKey(data_get($message, 'media_group_id'));

        return [array_filter([
            'media_kind' => 'image',
            'type' => 'photo',
            'provider_attachment_key' => $bestPhoto['file_unique_id'],
            'provider_file_id' => $bestPhoto['file_id'],
            'provider_file_unique_id' => $bestPhoto['file_unique_id'],
            'file_size_bytes' => $bestPhoto['file_size_bytes'],
            'width' => $bestPhoto['width'],
            'height' => $bestPhoto['height'],
            'media_group_id' => $providerGroupKey,
            'raw_payload_excerpt' => array_filter([
                'type' => 'photo',
                'file_unique_id' => $bestPhoto['file_unique_id'],
                'file_size_bytes' => $bestPhoto['file_size_bytes'],
                'width' => $bestPhoto['width'],
                'height' => $bestPhoto['height'],
            ], static fn (mixed $value): bool => $value !== null),
        ], static fn (mixed $value): bool => $value !== null)];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    protected function normalizeTelegramAnimationMedia(array $message): array
    {
        $payload = data_get($message, 'animation');

        if (! is_array($payload)) {
            return [];
        }

        $fileId = $this->normalizeExternalId(data_get($payload, 'file_id'));
        $fileUniqueId = $this->normalizeExternalId(data_get($payload, 'file_unique_id'));

        if (! filled($fileId) || ! filled($fileUniqueId)) {
            return [];
        }

        $mimeType = $this->normalizeMimeType(data_get($payload, 'mime_type'));
        $fileName = $this->normalizeText(data_get($payload, 'file_name'));
        $duration = $this->normalizeNonNegativeInteger(data_get($payload, 'duration'));
        $fileSize = $this->normalizeNonNegativeInteger(data_get($payload, 'file_size'));
        $width = $this->normalizeNonNegativeInteger(data_get($payload, 'width'));
        $height = $this->normalizeNonNegativeInteger(data_get($payload, 'height'));
        $providerGroupKey = $this->normalizeProviderGroupKey(data_get($message, 'media_group_id'));

        return [array_filter([
            'media_kind' => MessageAttachment::MEDIA_KIND_ANIMATION,
            'type' => 'animation',
            'provider_attachment_key' => $fileUniqueId,
            'provider_file_id' => $fileId,
            'provider_file_unique_id' => $fileUniqueId,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'extension' => $this->extensionFromTelegramMimeType($mimeType)
                ?? MessageAttachment::sanitizeExtension(pathinfo((string) $fileName, PATHINFO_EXTENSION)),
            'file_size_bytes' => $fileSize,
            'duration' => $duration,
            'width' => $width,
            'height' => $height,
            'media_group_id' => $providerGroupKey,
            'raw_payload_excerpt' => array_filter([
                'type' => 'animation',
                'media_kind' => MessageAttachment::MEDIA_KIND_ANIMATION,
                'file_unique_id' => $fileUniqueId,
                'file_size_bytes' => $fileSize,
                'duration' => $duration,
                'mime_type' => $mimeType,
                'width' => $width,
                'height' => $height,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ], static fn (mixed $value): bool => $value !== null && $value !== '')];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    protected function normalizeTelegramStickerMedia(array $message): array
    {
        $payload = data_get($message, 'sticker');

        if (! is_array($payload)) {
            return [];
        }

        $fileId = $this->normalizeExternalId(data_get($payload, 'file_id'));
        $fileUniqueId = $this->normalizeExternalId(data_get($payload, 'file_unique_id'));

        if (! filled($fileId) || ! filled($fileUniqueId)) {
            return [];
        }

        $mimeType = $this->normalizeMimeType(data_get($payload, 'mime_type'));
        $fileSize = $this->normalizeNonNegativeInteger(data_get($payload, 'file_size'));
        $width = $this->normalizeNonNegativeInteger(data_get($payload, 'width'));
        $height = $this->normalizeNonNegativeInteger(data_get($payload, 'height'));
        $isAnimated = data_get($payload, 'is_animated') === true;
        $isVideo = data_get($payload, 'is_video') === true;
        $extension = $this->extensionFromTelegramMimeType($mimeType)
            ?? ($isVideo ? 'webm' : null)
            ?? ($isAnimated ? 'tgs' : null)
            ?? 'webp';

        return [array_filter([
            'media_kind' => MessageAttachment::MEDIA_KIND_STICKER,
            'type' => 'sticker',
            'provider_attachment_key' => $fileUniqueId,
            'provider_file_id' => $fileId,
            'provider_file_unique_id' => $fileUniqueId,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'file_size_bytes' => $fileSize,
            'width' => $width,
            'height' => $height,
            'raw_payload_excerpt' => array_filter([
                'type' => 'sticker',
                'media_kind' => MessageAttachment::MEDIA_KIND_STICKER,
                'emoji' => $this->normalizeText(data_get($payload, 'emoji')),
                'file_unique_id' => $fileUniqueId,
                'file_size_bytes' => $fileSize,
                'mime_type' => $mimeType,
                'width' => $width,
                'height' => $height,
                'is_animated' => $isAnimated,
                'is_video' => $isVideo,
                'thumbnail_file_id' => $this->normalizeExternalId(
                    data_get($payload, 'thumbnail.file_id')
                        ?? data_get($payload, 'thumb.file_id')
                ),
                'thumbnail_file_unique_id' => $this->normalizeExternalId(
                    data_get($payload, 'thumbnail.file_unique_id')
                        ?? data_get($payload, 'thumb.file_unique_id')
                ),
                'thumbnail_width' => $this->normalizeNonNegativeInteger(
                    data_get($payload, 'thumbnail.width')
                        ?? data_get($payload, 'thumb.width')
                ),
                'thumbnail_height' => $this->normalizeNonNegativeInteger(
                    data_get($payload, 'thumbnail.height')
                        ?? data_get($payload, 'thumb.height')
                ),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ], static fn (mixed $value): bool => $value !== null && $value !== '')];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    protected function normalizeTelegramDocumentMedia(array $message): array
    {
        if (is_array(data_get($message, 'animation'))) {
            return [];
        }

        $payload = data_get($message, 'document');

        if (! is_array($payload)) {
            return [];
        }

        $fileId = $this->normalizeExternalId(data_get($payload, 'file_id'));
        $fileUniqueId = $this->normalizeExternalId(data_get($payload, 'file_unique_id'));

        if (! filled($fileId) || ! filled($fileUniqueId)) {
            return [];
        }

        $mimeType = $this->normalizeMimeType(data_get($payload, 'mime_type'));
        $fileName = $this->normalizeText(data_get($payload, 'file_name'));
        $fileSize = $this->normalizeNonNegativeInteger(data_get($payload, 'file_size'));
        $providerGroupKey = $this->normalizeProviderGroupKey(data_get($message, 'media_group_id'));

        return [array_filter([
            'media_kind' => 'document',
            'type' => 'document',
            'provider_attachment_key' => $fileUniqueId,
            'provider_file_id' => $fileId,
            'provider_file_unique_id' => $fileUniqueId,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'extension' => $this->extensionFromTelegramMimeType($mimeType)
                ?? MessageAttachment::sanitizeExtension(pathinfo((string) $fileName, PATHINFO_EXTENSION)),
            'file_size_bytes' => $fileSize,
            'media_group_id' => $providerGroupKey,
            'raw_payload_excerpt' => array_filter([
                'type' => 'document',
                'media_kind' => 'document',
                'file_unique_id' => $fileUniqueId,
                'file_size_bytes' => $fileSize,
                'mime_type' => $mimeType,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ], static fn (mixed $value): bool => $value !== null && $value !== '')];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    protected function normalizeTelegramVideoMedia(array $message): array
    {
        $payload = data_get($message, 'video');

        if (! is_array($payload)) {
            return [];
        }

        $fileId = $this->normalizeExternalId(data_get($payload, 'file_id'));
        $fileUniqueId = $this->normalizeExternalId(data_get($payload, 'file_unique_id'));

        if (! filled($fileId) || ! filled($fileUniqueId)) {
            return [];
        }

        $mimeType = $this->normalizeMimeType(data_get($payload, 'mime_type'));
        $fileName = $this->normalizeText(data_get($payload, 'file_name'));
        $duration = $this->normalizeNonNegativeInteger(data_get($payload, 'duration'));
        $fileSize = $this->normalizeNonNegativeInteger(data_get($payload, 'file_size'));
        $width = $this->normalizeNonNegativeInteger(data_get($payload, 'width'));
        $height = $this->normalizeNonNegativeInteger(data_get($payload, 'height'));
        $providerGroupKey = $this->normalizeProviderGroupKey(data_get($message, 'media_group_id'));

        return [array_filter([
            'media_kind' => 'video',
            'type' => 'video',
            'provider_attachment_key' => $fileUniqueId,
            'provider_file_id' => $fileId,
            'provider_file_unique_id' => $fileUniqueId,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'extension' => $this->extensionFromTelegramMimeType($mimeType)
                ?? MessageAttachment::sanitizeExtension(pathinfo((string) $fileName, PATHINFO_EXTENSION)),
            'file_size_bytes' => $fileSize,
            'duration' => $duration,
            'width' => $width,
            'height' => $height,
            'media_group_id' => $providerGroupKey,
            'raw_payload_excerpt' => array_filter([
                'type' => 'video',
                'media_kind' => 'video',
                'file_unique_id' => $fileUniqueId,
                'file_size_bytes' => $fileSize,
                'duration' => $duration,
                'mime_type' => $mimeType,
                'width' => $width,
                'height' => $height,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ], static fn (mixed $value): bool => $value !== null && $value !== '')];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    protected function normalizeTelegramVideoNoteMedia(array $message): array
    {
        $payload = data_get($message, 'video_note');

        if (! is_array($payload)) {
            return [];
        }

        $fileId = $this->normalizeExternalId(data_get($payload, 'file_id'));
        $fileUniqueId = $this->normalizeExternalId(data_get($payload, 'file_unique_id'));

        if (! filled($fileId) || ! filled($fileUniqueId)) {
            return [];
        }

        $duration = $this->normalizeNonNegativeInteger(data_get($payload, 'duration'));
        $length = $this->normalizeNonNegativeInteger(data_get($payload, 'length'));
        $fileSize = $this->normalizeNonNegativeInteger(data_get($payload, 'file_size'));

        return [array_filter([
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
            'type' => 'video_note',
            'provider_attachment_key' => $fileUniqueId,
            'provider_file_id' => $fileId,
            'provider_file_unique_id' => $fileUniqueId,
            'extension' => 'mp4',
            'file_size_bytes' => $fileSize,
            'duration' => $duration,
            'width' => $length,
            'height' => $length,
            'is_video_note' => true,
            'raw_payload_excerpt' => array_filter([
                'type' => 'video_note',
                'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
                'file_unique_id' => $fileUniqueId,
                'file_size_bytes' => $fileSize,
                'duration' => $duration,
                'width' => $length,
                'height' => $length,
                'is_video_note' => true,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ], static fn (mixed $value): bool => $value !== null && $value !== '')];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    protected function normalizeTelegramAudioMedia(array $message): array
    {
        foreach ([
            'voice' => 'voice',
            'audio' => 'audio',
        ] as $payloadKey => $mediaKind) {
            $payload = data_get($message, $payloadKey);

            if (! is_array($payload)) {
                continue;
            }

            $fileId = $this->normalizeExternalId(data_get($payload, 'file_id'));
            $fileUniqueId = $this->normalizeExternalId(data_get($payload, 'file_unique_id'));

            if (! filled($fileId) || ! filled($fileUniqueId)) {
                continue;
            }

            $mimeType = $this->normalizeMimeType(data_get($payload, 'mime_type'));
            $fileName = $this->normalizeText(data_get($payload, 'file_name'));
            $duration = $this->normalizeNonNegativeInteger(data_get($payload, 'duration'));
            $fileSize = $this->normalizeNonNegativeInteger(data_get($payload, 'file_size'));

            return [array_filter([
                'media_kind' => $mediaKind,
                'type' => $payloadKey,
                'provider_attachment_key' => $fileUniqueId,
                'provider_file_id' => $fileId,
                'provider_file_unique_id' => $fileUniqueId,
                'file_name' => $fileName,
                'mime_type' => $mimeType,
                'extension' => $this->extensionFromTelegramMimeType($mimeType)
                    ?? MessageAttachment::sanitizeExtension(pathinfo((string) $fileName, PATHINFO_EXTENSION)),
                'file_size_bytes' => $fileSize,
                'duration' => $duration,
                'raw_payload_excerpt' => array_filter([
                    'type' => $payloadKey,
                    'media_kind' => $mediaKind,
                    'file_unique_id' => $fileUniqueId,
                    'file_size_bytes' => $fileSize,
                    'duration' => $duration,
                    'mime_type' => $mimeType,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ], static fn (mixed $value): bool => $value !== null && $value !== '')];
        }

        return [];
    }

    protected function normalizeProviderGroupKey(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' && $normalized !== '0' ? $normalized : null;
    }

    protected function extensionFromTelegramMimeType(?string $mimeType): ?string
    {
        return match ($mimeType) {
            'application/json' => 'json',
            'application/msword' => 'doc',
            'application/pdf' => 'pdf',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/zip' => 'zip',
            'application/x-tgsticker' => 'tgs',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/aac' => 'aac',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/webm' => 'webm',
            'text/csv' => 'csv',
            'text/plain' => 'txt',
            'video/mp4' => 'mp4',
            'video/ogg' => 'ogv',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function resolveTelegramLocationDisplayText(array $message): ?string
    {
        $venue = data_get($message, 'venue');

        if (is_array($venue)) {
            $title = $this->normalizeText(data_get($venue, 'title'));
            $address = $this->normalizeText(data_get($venue, 'address'));
            $location = $this->formatTelegramLocationCoordinates(data_get($venue, 'location'));
            $parts = array_values(array_filter([
                $title !== null ? 'Локация: '.$title : 'Локация',
                $address,
                $location,
            ], static fn (?string $value): bool => $value !== null));

            return implode("\n", $parts);
        }

        $location = $this->formatTelegramLocationCoordinates(data_get($message, 'location'));

        return $location !== null ? 'Геопозиция: '.$location : null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function resolveTelegramSpecialDisplayText(array $message): ?string
    {
        $poll = data_get($message, 'poll');

        if (is_array($poll)) {
            return $this->formatPollDisplayText($poll);
        }

        $dice = data_get($message, 'dice');

        if (! is_array($dice)) {
            return null;
        }

        $emoji = $this->normalizeText(data_get($dice, 'emoji'));
        $value = $this->normalizeNonNegativeInteger(data_get($dice, 'value'));

        if ($emoji === null && $value === null) {
            return 'Бросок';
        }

        return trim('Бросок '.implode(': ', array_values(array_filter([
            $emoji,
            $value === null ? null : (string) $value,
        ], static fn (?string $value): bool => $value !== null))));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function resolveMaxSpecialDisplayText(array $message): ?string
    {
        foreach ($this->maxPollCandidates($message) as $poll) {
            $text = $this->formatPollDisplayText($poll);

            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    protected function maxPollCandidates(array $message): array
    {
        $candidates = [];

        foreach ([
            data_get($message, 'poll'),
            data_get($message, 'body.poll'),
            data_get($message, 'payload.poll'),
            data_get($message, 'body.payload.poll'),
        ] as $poll) {
            if (is_array($poll)) {
                $candidates[] = $poll;
            }
        }

        $body = data_get($message, 'body');

        if (is_array($body) && $this->isPollLikeType(data_get($body, 'type'))) {
            $candidates[] = $body;
        }

        foreach ([
            data_get($message, 'attachments'),
            data_get($message, 'body.attachments'),
            data_get($message, 'link.message.body.attachments'),
            data_get($message, 'link.message.attachments'),
        ] as $attachments) {
            if (! is_array($attachments)) {
                continue;
            }

            foreach ($attachments as $attachment) {
                if (! is_array($attachment) || ! $this->isPollLikeType(data_get($attachment, 'type'))) {
                    continue;
                }

                $payload = data_get($attachment, 'payload');
                $poll = data_get($attachment, 'poll');

                $candidates[] = is_array($payload) ? $payload : $attachment;

                if (is_array($poll)) {
                    $candidates[] = $poll;
                }
            }
        }

        return $candidates;
    }

    protected function isPollLikeType(mixed $type): bool
    {
        if (! is_scalar($type)) {
            return false;
        }

        return in_array(mb_strtolower(trim((string) $type)), ['poll', 'vote', 'quiz'], true);
    }

    /**
     * @param  array<string, mixed>  $poll
     */
    protected function formatPollDisplayText(array $poll): ?string
    {
        $question = $this->normalizeText(
            data_get($poll, 'question')
                ?? data_get($poll, 'title')
                ?? data_get($poll, 'text')
                ?? data_get($poll, 'payload.question')
                ?? data_get($poll, 'payload.title')
                ?? data_get($poll, 'payload.text')
        );
        $options = $this->extractPollOptionTexts(
            data_get($poll, 'options')
                ?? data_get($poll, 'answers')
                ?? data_get($poll, 'choices')
                ?? data_get($poll, 'payload.options')
                ?? data_get($poll, 'payload.answers')
                ?? data_get($poll, 'payload.choices')
        );

        if ($question === null && $options === []) {
            return null;
        }

        $lines = [$question === null ? 'Опрос' : 'Опрос: '.$question];

        foreach ($options as $option) {
            $lines[] = '• '.$option;
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    protected function extractPollOptionTexts(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $result = [];

        foreach ($options as $option) {
            $text = is_array($option)
                ? $this->normalizeText(
                    data_get($option, 'text')
                        ?? data_get($option, 'title')
                        ?? data_get($option, 'label')
                        ?? data_get($option, 'answer')
                )
                : $this->normalizeText($option);

            if ($text !== null) {
                $result[] = $text;
            }
        }

        return $result;
    }

    protected function formatTelegramLocationCoordinates(mixed $location): ?string
    {
        if (! is_array($location)) {
            return null;
        }

        $latitude = $this->normalizeCoordinate(data_get($location, 'latitude'));
        $longitude = $this->normalizeCoordinate(data_get($location, 'longitude'));

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return $latitude.', '.$longitude;
    }

    protected function normalizeCoordinate(mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $chatMemberUpdate
     */
    protected function normalizeTelegramChatMemberUpdate(Channel $channel, array $payload, array $chatMemberUpdate): ?IncomingBotMessage
    {
        if (data_get($chatMemberUpdate, 'from.is_bot') === true) {
            return null;
        }

        if (data_get($chatMemberUpdate, 'chat.type') !== 'private') {
            return null;
        }

        $systemEventCode = $this->resolveTelegramChatMemberSystemEventCode(
            $this->normalizeText(data_get($chatMemberUpdate, 'old_chat_member.status')),
            $this->normalizeText(data_get($chatMemberUpdate, 'new_chat_member.status')),
        );

        if ($systemEventCode === null) {
            return null;
        }

        $chatId = $this->normalizeExternalId(data_get($chatMemberUpdate, 'chat.id'));
        $userId = $this->normalizeExternalId(data_get($chatMemberUpdate, 'from.id'));

        if (! filled($chatId) || ! filled($userId) || $chatId !== $userId) {
            return null;
        }

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            providerEventKey: $this->normalizeExternalId($payload['update_id'] ?? null),
            externalMessageId: null,
            externalUsername: $this->normalizeUsername(data_get($chatMemberUpdate, 'from.username')),
            contactName: $this->resolvePersonName(data_get($chatMemberUpdate, 'from')),
            text: null,
            inboundKind: IncomingBotMessage::KIND_INBOUND_SYSTEM_EVENT,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: $payload,
            receivedAt: $this->resolveReceivedAt([
                data_get($chatMemberUpdate, 'date'),
            ]),
            systemEventCode: $systemEventCode,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $callbackQuery
     */
    protected function normalizeTelegramCallbackQuery(Channel $channel, array $payload, array $callbackQuery): ?IncomingBotMessage
    {
        if (data_get($callbackQuery, 'from.is_bot') === true) {
            return null;
        }

        if (data_get($callbackQuery, 'message.chat.type') !== 'private') {
            return null;
        }

        $normalizedCallbackData = $this->normalizeTelegramCallbackData(
            $this->normalizeText(data_get($callbackQuery, 'data')),
        );

        if (! filled($normalizedCallbackData)) {
            return null;
        }

        $chatId = $this->normalizeExternalId(data_get($callbackQuery, 'message.chat.id'));
        $userId = $this->normalizeExternalId(data_get($callbackQuery, 'from.id'));

        if (! filled($chatId) || ! filled($userId)) {
            return null;
        }

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            providerEventKey: $this->normalizeExternalId($payload['update_id'] ?? null),
            externalMessageId: $this->normalizeExternalId($callbackQuery['id'] ?? null),
            externalUsername: $this->normalizeUsername(data_get($callbackQuery, 'from.username')),
            contactName: $this->resolvePersonName(data_get($callbackQuery, 'from')),
            text: $normalizedCallbackData,
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: $payload,
            receivedAt: $this->resolveReceivedAt([
                data_get($callbackQuery, 'message.date'),
            ]),
        );
    }

    protected function normalizeTelegramCallbackData(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        if (str_starts_with($value, 'age_range:')) {
            $normalized = trim(substr($value, strlen('age_range:')));

            return $normalized !== '' ? $normalized : null;
        }

        if (str_starts_with($value, 'russian_region_confirm:')) {
            $normalized = trim(substr($value, strlen('russian_region_confirm:')));

            return $normalized !== '' ? 'russian_region_confirm:'.$normalized : null;
        }

        if (preg_match('/^scenario:warmup:(\d+):([a-z_]+)$/', $value, $matches)) {
            $action = trim((string) ($matches[2] ?? ''));

            return $action !== '' ? 'warmup:'.$action : null;
        }

        if (preg_match('/^scenario:(\d+):([A-Za-z0-9_-]{1,32})$/', $value, $matches)) {
            $key = trim((string) ($matches[2] ?? ''));

            return $key !== '' ? 'scenario:'.$key : null;
        }

        if (preg_match('/^v3b:([A-Za-z0-9_-]{1,64}):([A-Za-z0-9_-]{1,64})$/', $value, $matches)) {
            $blockId = trim((string) ($matches[1] ?? ''));
            $outputId = trim((string) ($matches[2] ?? ''));

            return $blockId !== '' && $outputId !== '' ? 'v3b:'.$blockId.':'.$outputId : null;
        }

        return null;
    }

    protected function resolveTelegramChatMemberSystemEventCode(
        ?string $oldStatus,
        ?string $newStatus,
    ): ?string {
        return match ([$oldStatus, $newStatus]) {
            ['member', 'kicked'] => IncomingBotMessage::SYSTEM_EVENT_BOT_BLOCKED_BY_USER,
            ['kicked', 'member'] => IncomingBotMessage::SYSTEM_EVENT_BOT_UNBLOCKED_BY_USER,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function normalizeMax(Channel $channel, array $payload): ?IncomingBotMessage
    {
        $updateType = (string) ($payload['update_type'] ?? '');

        if ($updateType === 'bot_started') {
            return $this->normalizeMaxBotStarted($channel, $payload);
        }

        if ($updateType !== 'message_created') {
            return null;
        }

        $message = $payload['message'] ?? null;

        if (! is_array($message) || data_get($message, 'sender.is_bot') === true) {
            return null;
        }

        $isDialog = filled($payload['user_locale'] ?? null)
            || filled(data_get($message, 'recipient.user_id'))
            || ! filled(data_get($message, 'recipient.chat_id'));

        if (! $isDialog) {
            return null;
        }

        $userId = $this->normalizeExternalId(data_get($message, 'sender.user_id'));
        $chatId = $this->normalizeExternalId(data_get($message, 'recipient.chat_id'));

        if (! filled($userId) || ! filled($chatId)) {
            return null;
        }

        $externalMessageId = $this->resolveMaxMessageId($message);
        $sharedContact = $this->extractMaxSharedContact($message);
        $specialText = $this->resolveMaxSpecialDisplayText($message);
        [$text, $richText] = $this->resolveMaxTextAndRichText($message);

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            providerEventKey: $externalMessageId,
            externalMessageId: $externalMessageId,
            externalUsername: $this->normalizeUsername(data_get($message, 'sender.username')),
            contactName: $this->resolvePersonName(data_get($message, 'sender')),
            text: $sharedContact['is_contact_share']
                ? null
                : ($specialText ?? $text),
            inboundKind: $sharedContact['is_contact_share']
                ? IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE
                : IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: $sharedContact['phone_number'],
            sharedContactUserId: $sharedContact['shared_contact_user_id'],
            rawPayload: $payload,
            receivedAt: $this->resolveReceivedAt([
                data_get($message, 'timestamp'),
                data_get($message, 'created_at'),
                data_get($payload, 'timestamp'),
                data_get($payload, 'created_at'),
            ]),
            avatarUrl: $this->resolveMaxAvatarUrl(data_get($message, 'sender')),
            media: $this->normalizeMaxMedia($message),
            richText: $sharedContact['is_contact_share'] || $specialText !== null
                ? null
                : $richText,
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{0: ?string, 1: array{version: 1, plain_text: string, runs: list<array{text: string, marks: list<array<string, mixed>>}>}|null}
     */
    protected function resolveMaxTextAndRichText(array $message): array
    {
        foreach ([
            [
                'text' => data_get($message, 'body.text'),
                'markup' => data_get($message, 'body.markup'),
            ],
            [
                'text' => data_get($message, 'text'),
                'markup' => data_get($message, 'markup'),
            ],
            [
                'text' => data_get($message, 'link.message.body.text'),
                'markup' => data_get($message, 'link.message.body.markup'),
            ],
            [
                'text' => data_get($message, 'link.message.text'),
                'markup' => data_get($message, 'link.message.markup'),
            ],
        ] as $candidate) {
            $text = $this->normalizeText($candidate['text'] ?? null);

            if ($text === null) {
                continue;
            }

            return [
                $text,
                $this->normalizeMaxRichText($text, $candidate['markup'] ?? null),
            ];
        }

        return [null, null];
    }

    /**
     * @return array{version: 1, plain_text: string, runs: list<array{text: string, marks: list<array<string, mixed>>}>}|null
     */
    protected function normalizeMaxRichText(string $plainText, mixed $markup): ?array
    {
        if (! is_array($markup) || ! array_is_list($markup) || $markup === []) {
            return null;
        }

        $entities = [];

        foreach ($markup as $element) {
            if (! is_array($element)) {
                continue;
            }

            $type = $this->mapMaxMarkupType(data_get($element, 'type'));
            $from = $this->normalizeNonNegativeInteger(data_get($element, 'from'));
            $length = $this->normalizeNonNegativeInteger(data_get($element, 'length'));

            if ($type === null || $from === null || $length === null || $length <= 0) {
                continue;
            }

            $range = $this->maxMarkupRangeToUtf16Range($plainText, $from, $length);

            if ($range === null) {
                continue;
            }

            $entity = [
                'type' => $type,
                'offset' => $range['offset'],
                'length' => $range['length'],
            ];

            if ($type === 'text_link') {
                $href = $this->resolveMaxMarkupHref($element);

                if ($href === null) {
                    continue;
                }

                $entity['url'] = $href;
            }

            $entities[] = $entity;
        }

        if ($entities === []) {
            return null;
        }

        return $this->normalizeTelegramRichTextAction->handle($plainText, [
            'text' => $plainText,
            'entities' => $entities,
        ]);
    }

    protected function mapMaxMarkupType(mixed $type): ?string
    {
        if (! is_string($type)) {
            return null;
        }

        return match (mb_strtolower(trim($type))) {
            'strong', 'bold', 'b' => 'bold',
            'emphasized', 'emphasis', 'italic', 'em', 'i' => 'italic',
            'monospaced', 'monospace', 'mono', 'code', 'inline_code', 'code_inline' => 'code',
            'link', 'text_link' => 'text_link',
            'url' => 'url',
            'strikethrough', 'strike', 'strikethru', 's', 'del' => 'strikethrough',
            'underline', 'underlined', 'u' => 'underline',
            'spoiler', 'hidden' => 'spoiler',
            'quote', 'blockquote', 'block_quote', 'quoted' => 'blockquote',
            'highlighted', 'highlight', 'mark', 'marked' => 'highlight',
            'heading', 'header', 'title', 'h1', 'h2', 'h3' => 'heading',
            'list' => 'list',
            default => null,
        };
    }

    /**
     * MAX markup offsets are character offsets. AB rich text normalizer expects UTF-16 units.
     *
     * @return array{offset: int, length: int}|null
     */
    protected function maxMarkupRangeToUtf16Range(string $plainText, int $from, int $length): ?array
    {
        preg_match_all('/./us', $plainText, $matches);
        $characters = $matches[0] ?? [];
        $end = $from + $length;

        if ($from < 0 || $length <= 0 || $from >= count($characters) || $end > count($characters)) {
            return null;
        }

        $offset = 0;
        $utf16Length = 0;

        foreach ($characters as $index => $character) {
            $units = intdiv(strlen(mb_convert_encoding($character, 'UTF-16LE', 'UTF-8')), 2);

            if ($index < $from) {
                $offset += $units;

                continue;
            }

            if ($index < $end) {
                $utf16Length += $units;

                continue;
            }

            break;
        }

        return $utf16Length > 0
            ? ['offset' => $offset, 'length' => $utf16Length]
            : null;
    }

    /**
     * @param  array<string, mixed>  $element
     */
    protected function resolveMaxMarkupHref(array $element): ?string
    {
        foreach (['url', 'href', 'link', 'payload.url'] as $key) {
            $href = $this->normalizeText(data_get($element, $key));

            if ($href !== null) {
                return $href;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    protected function normalizeMaxMedia(array $message): array
    {
        $attachments = [];

        foreach ([
            data_get($message, 'attachments'),
            data_get($message, 'body.attachments'),
            data_get($message, 'link.message.body.attachments'),
            data_get($message, 'link.message.attachments'),
        ] as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            foreach ($candidate as $attachment) {
                if (is_array($attachment)) {
                    $attachments[] = $attachment;
                }
            }
        }

        $media = [];

        foreach ($attachments as $index => $attachment) {
            $type = $this->normalizeText(data_get($attachment, 'type'));
            $mediaKind = $this->mediaKindFromMaxAttachment($attachment, $type);

            if ($type === null || $mediaKind === null) {
                continue;
            }

            $providerReference = $this->resolveMaxAttachmentReference($attachment, $type, $index);

            if ($providerReference === null) {
                continue;
            }

            $fileName = $this->resolveMaxAttachmentFilename($attachment);
            $mimeType = $this->normalizeMimeType(
                data_get($attachment, 'payload.mime_type')
                    ?? data_get($attachment, 'mime_type')
            );
            $width = $this->normalizeNonNegativeInteger(
                data_get($attachment, 'payload.width')
                    ?? data_get($attachment, 'width')
            );
            $height = $this->normalizeNonNegativeInteger(
                data_get($attachment, 'payload.height')
                    ?? data_get($attachment, 'height')
            );
            $duration = $this->normalizeNonNegativeInteger(
                data_get($attachment, 'payload.duration')
                    ?? data_get($attachment, 'duration')
            );
            $fileSize = $this->normalizeNonNegativeInteger(
                data_get($attachment, 'payload.file_size')
                    ?? data_get($attachment, 'payload.size')
                    ?? data_get($attachment, 'file_size')
                    ?? data_get($attachment, 'size')
            );

            $media[] = array_filter([
                'media_kind' => $mediaKind,
                'type' => $type,
                'provider_attachment_key' => $providerReference,
                'provider_file_reference' => $providerReference,
                'file_name' => $fileName,
                'mime_type' => $mimeType,
                'extension' => $this->extensionFromTelegramMimeType($mimeType)
                    ?? MessageAttachment::sanitizeExtension(pathinfo((string) $fileName, PATHINFO_EXTENSION)),
                'file_size_bytes' => $fileSize,
                'width' => $width,
                'height' => $height,
                'duration' => $duration,
                'is_video_note' => $mediaKind === MessageAttachment::MEDIA_KIND_VIDEO_NOTE ? true : null,
                'sort_order' => $index,
                'raw_payload_excerpt' => array_filter([
                    'type' => $type,
                    'media_kind' => $mediaKind,
                    'photo_id' => $type === 'image' ? $this->normalizeExternalId(data_get($attachment, 'payload.photo_id') ?? data_get($attachment, 'photo_id')) : null,
                    'sticker_code' => $type === 'sticker' ? $this->normalizeExternalId(data_get($attachment, 'payload.code') ?? data_get($attachment, 'code')) : null,
                    'file_size_bytes' => $fileSize,
                    'duration' => $duration,
                    'mime_type' => $mimeType,
                    'width' => $width,
                    'height' => $height,
                    'is_video_note' => $mediaKind === MessageAttachment::MEDIA_KIND_VIDEO_NOTE ? true : null,
                ], static fn (mixed $value): bool => $value !== null),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $media;
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    protected function mediaKindFromMaxAttachment(array $attachment, ?string $type): ?string
    {
        if ($type === 'video' && $this->isMaxRoundVideoAttachment($attachment)) {
            return MessageAttachment::MEDIA_KIND_VIDEO_NOTE;
        }

        return $this->mediaKindFromMaxAttachmentType($type);
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    protected function isMaxRoundVideoAttachment(array $attachment): bool
    {
        foreach ([
            data_get($attachment, 'payload.video_note'),
            data_get($attachment, 'video_note'),
            data_get($attachment, 'payload.is_video_note'),
            data_get($attachment, 'is_video_note'),
            data_get($attachment, 'payload.is_round'),
            data_get($attachment, 'is_round'),
            data_get($attachment, 'payload.round_video'),
            data_get($attachment, 'round_video'),
            data_get($attachment, 'payload.is_circle'),
            data_get($attachment, 'is_circle'),
        ] as $flag) {
            if ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true') {
                return true;
            }
        }

        foreach ([
            data_get($attachment, 'payload.media_kind'),
            data_get($attachment, 'media_kind'),
            data_get($attachment, 'payload.subtype'),
            data_get($attachment, 'subtype'),
            data_get($attachment, 'payload.kind'),
            data_get($attachment, 'kind'),
        ] as $value) {
            $normalized = is_scalar($value) ? mb_strtolower(trim((string) $value)) : '';

            if (in_array($normalized, ['video_note', 'round_video', 'circle_video', 'video_message'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function mediaKindFromMaxAttachmentType(?string $type): ?string
    {
        return match ($type) {
            'image' => MessageAttachment::MEDIA_KIND_IMAGE,
            'video' => MessageAttachment::MEDIA_KIND_VIDEO,
            'audio' => MessageAttachment::MEDIA_KIND_AUDIO,
            'file' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'sticker' => MessageAttachment::MEDIA_KIND_STICKER,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    protected function resolveMaxAttachmentReference(array $attachment, string $type, int $index): ?string
    {
        if ($type === 'image') {
            return $this->normalizeExternalId(
                data_get($attachment, 'payload.photo_id')
                    ?? data_get($attachment, 'photo_id')
            ) ?? $this->hashSensitiveReference(data_get($attachment, 'payload.token') ?? data_get($attachment, 'token'), 'token');
        }

        if ($type === 'sticker') {
            $stickerCode = $this->normalizeExternalId(
                data_get($attachment, 'payload.code')
                    ?? data_get($attachment, 'code')
            );

            if ($stickerCode !== null) {
                return $stickerCode;
            }
        }

        $tokenReference = $this->hashSensitiveReference(
            data_get($attachment, 'payload.token')
                ?? data_get($attachment, 'token'),
            'token',
        );

        if ($tokenReference !== null) {
            return $tokenReference;
        }

        $fileId = $this->normalizeExternalId(
            data_get($attachment, 'payload.file_id')
                ?? data_get($attachment, 'file_id')
                ?? data_get($attachment, 'payload.id')
                ?? data_get($attachment, 'id')
        );

        if ($fileId !== null) {
            return $fileId;
        }

        $urlReference = $this->hashSensitiveReference(
            data_get($attachment, 'payload.url')
                ?? data_get($attachment, 'url'),
            'url',
        );

        return $urlReference ?? "{$index}:{$type}";
    }

    protected function hashSensitiveReference(mixed $value, string $prefix): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $prefix.':'.sha1($normalized) : null;
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    protected function resolveMaxAttachmentFilename(array $attachment): ?string
    {
        return $this->normalizeText(
            data_get($attachment, 'filename')
                ?? data_get($attachment, 'file_name')
                ?? data_get($attachment, 'name')
                ?? data_get($attachment, 'payload.filename')
                ?? data_get($attachment, 'payload.file_name')
                ?? data_get($attachment, 'payload.name')
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function normalizeMaxBotStarted(Channel $channel, array $payload): ?IncomingBotMessage
    {
        $chatId = $this->normalizeExternalId($payload['chat_id'] ?? null);
        $userId = $this->normalizeExternalId(data_get($payload, 'user.user_id'));
        $messageParameter = $this->normalizeText($payload['payload'] ?? null);

        if (! filled($chatId) || ! filled($userId)) {
            return null;
        }

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            providerEventKey: $this->resolveMaxBotStartedEventKey($payload, $chatId, $userId),
            externalMessageId: null,
            externalUsername: $this->normalizeUsername(data_get($payload, 'user.username')),
            contactName: $this->resolvePersonName(data_get($payload, 'user')),
            text: null,
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: $payload,
            receivedAt: $this->resolveReceivedAt([
                data_get($payload, 'timestamp'),
                data_get($payload, 'created_at'),
            ]),
            messageParameter: $messageParameter,
            avatarUrl: $this->resolveMaxAvatarUrl(data_get($payload, 'user')),
        );
    }

    protected function normalizeExternalId(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return trim((string) $value);
    }

    protected function normalizeUsername(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $username = ltrim(trim((string) $value), '@');

        return $username !== '' ? $username : null;
    }

    protected function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    protected function normalizeNonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value)) {
            return $value >= 0 && floor($value) === $value ? (int) $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    protected function normalizeMimeType(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $mimeType = mb_strtolower(trim(explode(';', (string) $value)[0] ?? ''));

        return preg_match('/\A[a-z0-9][a-z0-9!#$&^_.+-]{0,126}\/[a-z0-9][a-z0-9!#$&^_.+-]{0,126}\z/', $mimeType) === 1
            ? $mimeType
            : null;
    }

    protected function resolveTelegramStartMessageParameter(?string $text): ?string
    {
        if (! filled($text)) {
            return null;
        }

        if (! preg_match('/^\/start\s+(.+)$/u', $text, $matches)) {
            return null;
        }

        return $this->normalizeText($matches[1] ?? null);
    }

    /**
     * @param  array<string, mixed>|mixed  $person
     */
    protected function resolvePersonName(mixed $person): ?string
    {
        if (! is_array($person)) {
            return null;
        }

        $name = trim(implode(' ', array_filter([
            $this->normalizeText(data_get($person, 'first_name')),
            $this->normalizeText(data_get($person, 'last_name')),
        ], fn (?string $value): bool => filled($value))));

        if ($name !== '') {
            return $name;
        }

        return $this->normalizeText(data_get($person, 'name'));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function resolveMaxMessageId(array $message): ?string
    {
        return $this->normalizeExternalId(
            data_get($message, 'body.mid')
                ?? data_get($message, 'body.seq')
                ?? data_get($message, 'message_id')
                ?? data_get($message, 'id')
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveMaxBotStartedEventKey(array $payload, string $chatId, string $userId): string
    {
        $fingerprint = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'payload' => $this->normalizeText($payload['payload'] ?? null),
            'timestamp' => $this->normalizeExternalId($payload['timestamp'] ?? null),
            'created_at' => $this->normalizeExternalId($payload['created_at'] ?? null),
        ];

        return 'max-bot-started:'.sha1((string) json_encode($fingerprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{is_contact_share: bool, phone_number: ?string, shared_contact_user_id: ?string}
     */
    protected function extractMaxSharedContact(array $message): array
    {
        $candidateContainers = [];

        foreach ([
            data_get($message, 'contact'),
            data_get($message, 'body.contact'),
        ] as $container) {
            if (is_array($container)) {
                $candidateContainers[] = $container;
            }
        }

        $body = data_get($message, 'body');

        if (
            is_array($body)
            && in_array((string) data_get($body, 'type'), ['contact', 'request_contact', 'shared_contact'], true)
        ) {
            $candidateContainers[] = $body;
        }

        foreach ([
            data_get($message, 'attachments'),
            data_get($message, 'body.attachments'),
        ] as $attachments) {
            if (! is_array($attachments)) {
                continue;
            }

            foreach ($attachments as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }

                $type = (string) data_get($attachment, 'type');
                $contact = data_get($attachment, 'contact');
                $payloadContact = data_get($attachment, 'payload.contact');

                if (is_array($contact)) {
                    $candidateContainers[] = $contact;
                }

                if (is_array($payloadContact)) {
                    $candidateContainers[] = $payloadContact;
                }

                if (in_array($type, ['contact', 'request_contact', 'shared_contact'], true)) {
                    $candidateContainers[] = $attachment;
                    $candidateContainers[] = (array) data_get($attachment, 'payload', []);
                }
            }
        }

        $phoneNumber = null;
        $sharedContactUserId = null;

        foreach ($candidateContainers as $container) {
            if (! is_array($container)) {
                continue;
            }

            $phoneNumber = $phoneNumber ?? $this->extractPhoneNumberFromMaxContainer($container);

            $sharedContactUserId = $sharedContactUserId ?? $this->extractSharedContactUserIdFromMaxContainer($container);
        }

        return [
            'is_contact_share' => $candidateContainers !== [],
            'phone_number' => $phoneNumber,
            'shared_contact_user_id' => $sharedContactUserId,
        ];
    }

    /**
     * @param  array<string, mixed>  $container
     */
    protected function extractPhoneNumberFromMaxContainer(array $container): ?string
    {
        $phoneNumber = $this->normalizeText(
            data_get($container, 'phone_number')
            ?? data_get($container, 'phone')
            ?? data_get($container, 'number')
            ?? data_get($container, 'contact.phone_number')
            ?? data_get($container, 'contact.phone')
            ?? data_get($container, 'payload.contact.phone_number')
            ?? data_get($container, 'payload.contact.phone')
        );

        if (filled($phoneNumber)) {
            return $phoneNumber;
        }

        $vcfInfo = $this->normalizeText(
            data_get($container, 'vcf_info')
            ?? data_get($container, 'payload.vcf_info')
        );

        if (! filled($vcfInfo)) {
            return null;
        }

        if (! preg_match('/^TEL(?:;[^:\r\n]+)*:([^\r\n]+)/mi', $vcfInfo, $matches)) {
            return null;
        }

        return $this->normalizeText($matches[1] ?? null);
    }

    /**
     * @param  array<string, mixed>  $container
     */
    protected function extractSharedContactUserIdFromMaxContainer(array $container): ?string
    {
        return $this->normalizeExternalId(
            data_get($container, 'user_id')
            ?? data_get($container, 'contact.user_id')
            ?? data_get($container, 'payload.contact.user_id')
            ?? data_get($container, 'max_info.user_id')
            ?? data_get($container, 'payload.max_info.user_id')
        );
    }

    /**
     * @param  array<string, mixed>|mixed  $person
     */
    protected function resolveMaxAvatarUrl(mixed $person): ?string
    {
        if (! is_array($person)) {
            return null;
        }

        $fullAvatarUrl = $this->normalizeText(data_get($person, 'full_avatar_url'));

        if (filled($fullAvatarUrl)) {
            return $fullAvatarUrl;
        }

        return $this->normalizeText(data_get($person, 'avatar_url'));
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    protected function resolveReceivedAt(array $candidates): Carbon
    {
        foreach ($candidates as $candidate) {
            if (! filled($candidate)) {
                continue;
            }

            if (is_numeric($candidate)) {
                return $this->createDateTimeFromNumericTimestamp($candidate);
            }

            try {
                return Carbon::parse((string) $candidate);
            } catch (Throwable) {
                continue;
            }
        }

        return now();
    }

    protected function createDateTimeFromNumericTimestamp(int|float|string $value): Carbon
    {
        $timestamp = (string) $value;
        $timestamp = ltrim(trim($timestamp), '+');
        $absoluteTimestamp = ltrim($timestamp, '-');

        return match (strlen($absoluteTimestamp)) {
            16 => Carbon::createFromTimestampUTC((float) $timestamp / 1_000_000),
            13 => Carbon::createFromTimestampMsUTC((int) $timestamp),
            default => Carbon::createFromTimestampUTC((int) $timestamp),
        };
    }
}
