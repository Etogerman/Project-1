<?php

namespace App\Services\Bots;

use App\Data\Bots\MaxVideoAttachmentDownloadData;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use RuntimeException;

class ResolveMaxBotMediaSourceAction
{
    public function __construct(
        private readonly MaxBotApiService $maxBotApiService,
    ) {}

    public function handle(
        Channel $channel,
        Message $message,
        MessageAttachment $attachment,
    ): ?MaxVideoAttachmentDownloadData {
        $reference = $this->normalizeScalar($attachment->provider_file_reference)
            ?? $this->normalizeScalar($attachment->provider_attachment_key);

        if ($reference === null) {
            return null;
        }

        foreach ($this->maxAttachmentCandidates($message) as $index => $candidate) {
            if ($this->resolveMaxAttachmentReference($candidate, $index) !== $reference) {
                continue;
            }

            // payload.url у MAX одноразовый/короткоживущий, поэтому для
            // видео и аудио предпочитаем свежий URL из videos API.
            if (in_array($attachment->media_kind, [
                MessageAttachment::MEDIA_KIND_VIDEO,
                MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
                MessageAttachment::MEDIA_KIND_AUDIO,
                MessageAttachment::MEDIA_KIND_VOICE,
            ], true)) {
                $mediaToken = $this->resolveMaxAttachmentToken($candidate);

                if ($mediaToken !== null) {
                    try {
                        $providerData = $this->maxBotApiService->fetchVideoAttachmentDownloadData($channel, $mediaToken);
                    } catch (ConnectionException|InvalidArgumentException|RequestException $exception) {
                        $webhookUrl = $this->resolveMaxAttachmentUrl($candidate);

                        if ($webhookUrl === null) {
                            throw $exception;
                        }

                        return $this->maxAttachmentDownloadData($webhookUrl, $candidate);
                    }

                    if ($providerData->downloadUrl !== null) {
                        return $providerData;
                    }

                    $webhookUrl = $this->resolveMaxAttachmentUrl($candidate);

                    if ($webhookUrl !== null) {
                        $webhookData = $this->maxAttachmentDownloadData($webhookUrl, $candidate);

                        return new MaxVideoAttachmentDownloadData(
                            downloadUrl: $webhookUrl,
                            width: $providerData->width ?? $webhookData->width,
                            height: $providerData->height ?? $webhookData->height,
                            duration: $providerData->duration ?? $webhookData->duration,
                        );
                    }

                    throw new RuntimeException('MAX media download URL is not ready yet.');
                }
            }

            if ($attachment->media_kind === MessageAttachment::MEDIA_KIND_STICKER) {
                return $this->resolveMaxStickerDownloadData($channel, $message, $reference, $candidate);
            }

            $url = $this->resolveMaxAttachmentUrl($candidate);

            if ($url !== null) {
                return $this->maxAttachmentDownloadData($url, $candidate);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $webhookCandidate
     */
    private function resolveMaxStickerDownloadData(
        Channel $channel,
        Message $message,
        string $reference,
        array $webhookCandidate,
    ): ?MaxVideoAttachmentDownloadData {
        $webhookUrl = $this->resolveMaxAttachmentUrl($webhookCandidate);

        if ($webhookUrl !== null && ! $this->isMaxStickerStubUrl($webhookUrl)) {
            return $this->maxAttachmentDownloadData($webhookUrl, $webhookCandidate);
        }

        $messageId = $this->normalizeScalar($message->external_message_id)
            ?? $this->normalizeScalar($message->provider_event_key);

        if ($messageId === null) {
            return null;
        }

        $messagePayload = $this->maxBotApiService->fetchMessage($channel, $messageId);

        foreach ($this->maxAttachmentCandidatesFromPayload($messagePayload) as $index => $candidate) {
            if ($this->resolveMaxAttachmentReference($candidate, $index) !== $reference) {
                continue;
            }

            $url = $this->resolveMaxAttachmentUrl($candidate);

            if ($url !== null && ! $this->isMaxStickerStubUrl($url)) {
                return $this->maxAttachmentDownloadData($url, $candidate);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function maxAttachmentDownloadData(string $url, array $attachment): MaxVideoAttachmentDownloadData
    {
        return new MaxVideoAttachmentDownloadData(
            downloadUrl: $url,
            width: $this->normalizeNonNegativeInteger(
                data_get($attachment, 'payload.width')
                    ?? data_get($attachment, 'width')
            ),
            height: $this->normalizeNonNegativeInteger(
                data_get($attachment, 'payload.height')
                    ?? data_get($attachment, 'height')
            ),
            duration: $this->normalizeNonNegativeInteger(
                data_get($attachment, 'payload.duration')
                    ?? data_get($attachment, 'duration')
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function resolveMaxAttachmentReference(array $attachment, int $index): ?string
    {
        $type = $this->normalizeScalar(data_get($attachment, 'type'));

        if ($type === 'image') {
            return $this->normalizeScalar(
                data_get($attachment, 'payload.photo_id')
                    ?? data_get($attachment, 'photo_id')
            ) ?? $this->hashSensitiveReference(
                data_get($attachment, 'payload.token') ?? data_get($attachment, 'token'),
                'token',
            );
        }

        if ($type === 'sticker') {
            $stickerCode = $this->normalizeScalar(
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

        $fileId = $this->normalizeScalar(
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

        return $urlReference ?? ($type !== null ? "{$index}:{$type}" : null);
    }

    private function hashSensitiveReference(mixed $value, string $prefix): ?string
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
    private function resolveMaxAttachmentUrl(array $attachment): ?string
    {
        return $this->normalizeScalar(
            data_get($attachment, 'payload.url')
                ?? data_get($attachment, 'url')
        );
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function resolveMaxAttachmentToken(array $attachment): ?string
    {
        return $this->normalizeScalar(
            data_get($attachment, 'payload.token')
                ?? data_get($attachment, 'token')
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function maxAttachmentCandidates(Message $message): array
    {
        if (! is_array($message->raw_payload)) {
            return [];
        }

        return $this->maxAttachmentCandidatesFromPayload($message->raw_payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function maxAttachmentCandidatesFromPayload(array $payload): array
    {
        $candidates = [];

        // link.message.* читаем без forward-гейта: это lookup для уже
        // сохранённого reference и совместимости со старыми reply-записями.
        foreach ([
            data_get($payload, 'attachments'),
            data_get($payload, 'body.attachments'),
            data_get($payload, 'message.attachments'),
            data_get($payload, 'message.body.attachments'),
            data_get($payload, 'message.link.message.body.attachments'),
            data_get($payload, 'message.link.message.attachments'),
        ] as $attachments) {
            if (! is_array($attachments)) {
                continue;
            }

            foreach ($attachments as $attachment) {
                if (is_array($attachment)) {
                    $candidates[] = $attachment;
                }
            }
        }

        return $candidates;
    }

    private function isMaxStickerStubUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path)
            && str_contains($path, '/static/messages/res/images/stub/sticker_');
    }

    private function normalizeScalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeNonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value)) {
            return $value >= 0 ? (int) $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }
}
