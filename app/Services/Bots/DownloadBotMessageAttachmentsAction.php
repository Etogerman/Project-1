<?php

namespace App\Services\Bots;

use App\Data\Bots\DownloadedAvatarData;
use App\Data\Bots\MaxVideoAttachmentDownloadData;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Messages\StoreMessageAttachmentLocalFileAction;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class DownloadBotMessageAttachmentsAction
{
    public function __construct(
        private readonly TelegramBotApiService $telegramBotApiService,
        private readonly MaxBotApiService $maxBotApiService,
        private readonly StoreMessageAttachmentLocalFileAction $storeMessageAttachmentLocalFileAction,
    ) {}

    public function handle(Message $message): void
    {
        $message->loadMissing(['channel', 'attachments']);

        $channel = $message->channel;

        if (! $channel instanceof Channel) {
            return;
        }

        foreach ($message->attachments as $attachment) {
            if (! $attachment instanceof MessageAttachment || ! $this->shouldDownload($attachment)) {
                continue;
            }

            $this->download($channel, $message, $attachment);
        }

        $message->unsetRelation('attachments');
    }

    private function shouldDownload(MessageAttachment $attachment): bool
    {
        if (
            $attachment->provider === MessageAttachment::PROVIDER_TELEGRAM_BOT
            && ! in_array($attachment->media_kind, [
                MessageAttachment::MEDIA_KIND_IMAGE,
                MessageAttachment::MEDIA_KIND_DOCUMENT,
                MessageAttachment::MEDIA_KIND_VIDEO,
                MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
                MessageAttachment::MEDIA_KIND_AUDIO,
                MessageAttachment::MEDIA_KIND_VOICE,
                MessageAttachment::MEDIA_KIND_STICKER,
                MessageAttachment::MEDIA_KIND_ANIMATION,
            ], true)
        ) {
            return false;
        }

        if (
            $attachment->provider === MessageAttachment::PROVIDER_MAX_BOT
            && ! in_array($attachment->media_kind, [
                MessageAttachment::MEDIA_KIND_IMAGE,
                MessageAttachment::MEDIA_KIND_DOCUMENT,
                MessageAttachment::MEDIA_KIND_VIDEO,
                MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
                MessageAttachment::MEDIA_KIND_AUDIO,
                MessageAttachment::MEDIA_KIND_STICKER,
            ], true)
        ) {
            return false;
        }

        if (! in_array($attachment->provider, [
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::PROVIDER_MAX_BOT,
        ], true)) {
            return false;
        }

        return in_array($attachment->download_status, [
            MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
            MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
        ], true);
    }

    private function download(Channel $channel, Message $message, MessageAttachment $attachment): ?MessageAttachment
    {
        $claimedAttachment = $this->claimDownload($attachment);

        if (! $claimedAttachment instanceof MessageAttachment) {
            return $attachment->fresh();
        }

        $attachment = $claimedAttachment;

        try {
            $downloaded = match ($attachment->provider) {
                MessageAttachment::PROVIDER_TELEGRAM_BOT => $this->downloadTelegramBotFile($channel, $message, $attachment),
                MessageAttachment::PROVIDER_MAX_BOT => $this->downloadMaxBotMedia($channel, $message, $attachment),
                default => null,
            };

            if (! $downloaded instanceof DownloadedAvatarData) {
                return $this->markFailed(
                    $attachment,
                    'bot_media_download_unsupported_provider',
                    'Media download is not supported for this provider.',
                );
            }

            return $this->storeDownloadedAttachment($attachment, $downloaded);
        } catch (Throwable $throwable) {
            return $this->markFailed(
                $attachment,
                $this->resolveSafeErrorCode($throwable),
                $this->resolveSafeErrorMessage($attachment),
            );
        }
    }

    private function downloadTelegramBotFile(Channel $channel, Message $message, MessageAttachment $attachment): DownloadedAvatarData
    {
        $fileId = $this->resolveTelegramBotDownloadFileId($message, $attachment);

        if (! filled($fileId)) {
            throw new InvalidArgumentException('Telegram Bot media download requires provider_file_id.');
        }

        $downloaded = $this->telegramBotApiService->downloadFile(
            $channel,
            (string) $fileId,
            $this->maxBytes(),
        );

        if ($fileId === $this->normalizeScalar($attachment->provider_file_id)) {
            return $downloaded;
        }

        return new DownloadedAvatarData(
            contents: $downloaded->contents,
            contentType: $downloaded->contentType,
            filenameHint: $downloaded->filenameHint,
            metadata: [
                ...$downloaded->metadata,
                'telegram_preview_source' => 'thumbnail',
                'telegram_preview_file_id' => $fileId,
                'telegram_original_file_id' => $this->normalizeScalar($attachment->provider_file_id),
                'width' => $this->normalizeNonNegativeInteger(data_get($attachment->raw_payload_excerpt, 'thumbnail_width')),
                'height' => $this->normalizeNonNegativeInteger(data_get($attachment->raw_payload_excerpt, 'thumbnail_height')),
            ],
        );
    }

    private function downloadMaxBotMedia(
        Channel $channel,
        Message $message,
        MessageAttachment $attachment,
    ): DownloadedAvatarData {
        $downloadData = $this->resolveMaxMediaDownloadData($channel, $message, $attachment);
        $url = $downloadData?->downloadUrl;

        if ($url === null) {
            throw new InvalidArgumentException('MAX media URL is missing from raw payload.');
        }

        $trustedUrl = $this->validateTrustedMaxMediaUrl($url);

        $response = Http::withOptions(['stream' => true])
            ->withoutRedirecting()
            ->timeout(30)
            ->get($trustedUrl)
            ->throw();

        $this->assertContentLengthWithinLimit($response->header('Content-Length'));

        $contents = $this->readResponseBodyWithinLimit($response);

        if ($contents === '') {
            throw new InvalidArgumentException('MAX media download returned an empty body.');
        }

        if (strlen($contents) > $this->maxBytes()) {
            throw new InvalidArgumentException('MAX media file is larger than the local download limit.');
        }

        return new DownloadedAvatarData(
            contents: $contents,
            contentType: $response->header('Content-Type'),
            filenameHint: $this->filenameHintFromUrl($trustedUrl),
            metadata: $downloadData->metadata(),
        );
    }

    private function assertContentLengthWithinLimit(?string $contentLength): void
    {
        if ($contentLength === null) {
            return;
        }

        $normalized = trim(strtok($contentLength, ',') ?: '');

        if ($normalized === '' || ! ctype_digit($normalized)) {
            return;
        }

        if ((int) $normalized > $this->maxBytes()) {
            throw new InvalidArgumentException('MAX media file is larger than the local download limit.');
        }
    }

    private function readResponseBodyWithinLimit(HttpResponse $response): string
    {
        $body = $response->toPsrResponse()->getBody();
        $maxBytes = $this->maxBytes();
        $expectedLength = $this->normalizeNonNegativeInteger($response->header('Content-Length'));
        $contents = '';

        while (! $body->eof()) {
            $remainingBytes = $maxBytes - strlen($contents) + 1;

            try {
                $chunk = $body->read(min(8192, $remainingBytes));
            } catch (\RuntimeException $exception) {
                // CDN MAX (okcdn) закрывает соединение без EOF-сигнала: psr7 кидает
                // «Unable to read from stream» УЖЕ ПОСЛЕ полного тела. Если получено
                // ровно столько, сколько обещал Content-Length — тело полное.
                if ($expectedLength !== null && strlen($contents) >= $expectedLength) {
                    break;
                }

                throw $exception;
            }

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;

            if (strlen($contents) > $maxBytes) {
                throw new InvalidArgumentException('MAX media file is larger than the local download limit.');
            }
        }

        return $contents;
    }

    private function storeDownloadedAttachment(
        MessageAttachment $attachment,
        DownloadedAvatarData $downloaded,
    ): MessageAttachment {
        $filename = $this->filenameFromHint($downloaded->filenameHint);
        $headerMimeType = $this->normalizeMimeType($downloaded->contentType);
        $attachmentExtension = MessageAttachment::sanitizeExtension($attachment->extension);
        $providerMetadata = $this->mergedProviderMetadata($attachment, $downloaded->metadata);
        $isTelegramStickerThumbnail = $this->isTelegramStickerThumbnailDownload($attachment, $providerMetadata);
        $extension = $this->extensionFromMimeType($headerMimeType)
            ?? ($isTelegramStickerThumbnail ? $this->extensionFromFilename($filename) : null)
            ?? ($attachmentExtension !== '' ? $attachmentExtension : null)
            ?? $this->extensionFromFilename($filename);
        $mimeType = $headerMimeType
            ?? ($isTelegramStickerThumbnail ? $this->mimeTypeFromExtension($extension) : null)
            ?? $attachment->mime_type
            ?? $this->mimeTypeFromExtension($extension);

        $values = [
            'mime_type' => $mimeType,
            'extension' => $extension,
            'original_filename' => $isTelegramStickerThumbnail
                ? 'sticker-preview.'.($extension ?: 'bin')
                : ($attachment->original_filename ?? $filename),
            'file_size_bytes' => strlen($downloaded->contents),
        ];

        $isMaxVideoNote = $this->shouldTreatMaxVideoAsVideoNote($attachment, $providerMetadata);

        if ($isMaxVideoNote) {
            $values['media_kind'] = MessageAttachment::MEDIA_KIND_VIDEO_NOTE;
            $providerMetadata['is_video_note'] = true;
        }

        if ($providerMetadata !== []) {
            $values['provider_metadata'] = $providerMetadata;
        }

        $rawPayloadExcerpt = $this->mergedRawPayloadExcerpt($attachment, $providerMetadata, $isMaxVideoNote);

        if ($rawPayloadExcerpt !== []) {
            $values['raw_payload_excerpt'] = $rawPayloadExcerpt;
        }

        $attachment->forceFill($values)->save();

        return $this->storeMessageAttachmentLocalFileAction->handle(
            $attachment,
            $downloaded->contents,
            $extension,
        );
    }

    private function resolveTelegramBotDownloadFileId(Message $message, MessageAttachment $attachment): ?string
    {
        if ($this->shouldUseTelegramStickerThumbnail($attachment)) {
            return $this->resolveTelegramStickerThumbnailFileId($message, $attachment)
                ?? $this->normalizeScalar($attachment->provider_file_id);
        }

        return $this->normalizeScalar($attachment->provider_file_id);
    }

    private function shouldUseTelegramStickerThumbnail(MessageAttachment $attachment): bool
    {
        if (
            $attachment->provider !== MessageAttachment::PROVIDER_TELEGRAM_BOT
            || $attachment->media_kind !== MessageAttachment::MEDIA_KIND_STICKER
        ) {
            return false;
        }

        return data_get($attachment->raw_payload_excerpt, 'is_animated') === true
            || MessageAttachment::sanitizeExtension($attachment->extension) === 'tgs'
            || $attachment->downloadMimeType() === 'application/x-tgsticker';
    }

    private function resolveTelegramStickerThumbnailFileId(Message $message, MessageAttachment $attachment): ?string
    {
        $excerptThumbnailFileId = $this->normalizeScalar(data_get($attachment->raw_payload_excerpt, 'thumbnail_file_id'));

        if ($excerptThumbnailFileId !== null) {
            return $excerptThumbnailFileId;
        }

        $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $sticker = data_get($payload, 'message.sticker');

        if (! is_array($sticker)) {
            return null;
        }

        $stickerUniqueId = $this->normalizeScalar(data_get($sticker, 'file_unique_id'));
        $attachmentUniqueId = $this->normalizeScalar($attachment->provider_file_unique_id)
            ?? $this->normalizeScalar($attachment->provider_attachment_key);

        if ($stickerUniqueId !== null && $attachmentUniqueId !== null && $stickerUniqueId !== $attachmentUniqueId) {
            return null;
        }

        return $this->normalizeScalar(data_get($sticker, 'thumbnail.file_id'))
            ?? $this->normalizeScalar(data_get($sticker, 'thumb.file_id'));
    }

    /**
     * @param  array<string, mixed>  $providerMetadata
     */
    private function isTelegramStickerThumbnailDownload(MessageAttachment $attachment, array $providerMetadata): bool
    {
        return $attachment->provider === MessageAttachment::PROVIDER_TELEGRAM_BOT
            && $attachment->media_kind === MessageAttachment::MEDIA_KIND_STICKER
            && data_get($providerMetadata, 'telegram_preview_source') === 'thumbnail';
    }

    private function claimDownload(MessageAttachment $attachment): ?MessageAttachment
    {
        return DB::transaction(function () use ($attachment): ?MessageAttachment {
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof MessageAttachment || ! $this->shouldDownload($locked) || $this->hasLocalFile($locked)) {
                return null;
            }

            $locked->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
                'safe_error_code' => null,
                'safe_error_message' => null,
            ])->save();

            return $locked->fresh();
        });
    }

    private function markFailed(MessageAttachment $attachment, string $errorCode, string $errorMessage): MessageAttachment
    {
        return DB::transaction(function () use ($attachment, $errorCode, $errorMessage): MessageAttachment {
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof MessageAttachment) {
                return $attachment;
            }

            if (
                $locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
                || $this->hasLocalFile($locked)
                || $locked->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
            ) {
                return $locked;
            }

            $locked->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                'local_disk' => null,
                'local_path' => null,
                'safe_error_code' => $this->normalizeErrorCode($errorCode),
                'safe_error_message' => $errorMessage,
            ])->save();

            return $locked->refresh();
        });
    }

    private function hasLocalFile(MessageAttachment $attachment): bool
    {
        return filled($attachment->local_disk) && filled($attachment->local_path);
    }

    private function resolveMaxMediaDownloadData(
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

            // payload.url у MAX одноразовый/короткоживущий (живой QA 04.07: 400 при
            // повторном GET) — для видео, кружков, аудио и голосовых берём свежий URL
            // через videos-API (он универсален для всей медиа-фермы okcdn, включая audio).
            if (in_array($attachment->media_kind, [
                MessageAttachment::MEDIA_KIND_VIDEO,
                MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
                MessageAttachment::MEDIA_KIND_AUDIO,
                MessageAttachment::MEDIA_KIND_VOICE,
            ], true)) {
                $mediaToken = $this->resolveMaxAttachmentToken($candidate);

                if ($mediaToken !== null) {
                    return $this->maxBotApiService->fetchVideoAttachmentDownloadData($channel, $mediaToken);
                }

                // Токена нет — падаем обратно на прямой URL из payload (лучше попытка,
                // чем гарантированный отказ).
            }

            if ($attachment->media_kind === MessageAttachment::MEDIA_KIND_STICKER) {
                return $this->resolveMaxStickerDownloadData($channel, $message, $reference, $candidate);
            }

            $url = $this->resolveMaxAttachmentUrl($candidate);

            if ($url !== null) {
                return new MaxVideoAttachmentDownloadData(downloadUrl: $url);
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
     * @param  array<string, mixed>  $downloadMetadata
     * @return array<string, mixed>
     */
    private function mergedProviderMetadata(MessageAttachment $attachment, array $downloadMetadata): array
    {
        $metadata = is_array($attachment->provider_metadata) ? $attachment->provider_metadata : [];

        foreach ([
            'width',
            'height',
            'duration',
            'telegram_preview_source',
            'telegram_preview_file_id',
            'telegram_original_file_id',
        ] as $key) {
            $value = $this->normalizeNonNegativeInteger(data_get($downloadMetadata, $key));

            if (str_starts_with($key, 'telegram_')) {
                $value = $this->normalizeScalar(data_get($downloadMetadata, $key));
            }

            if ($value !== null) {
                $metadata[$key] = $value;
            }
        }

        return array_filter($metadata, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function shouldTreatMaxVideoAsVideoNote(MessageAttachment $attachment, array $metadata): bool
    {
        if (
            $attachment->provider !== MessageAttachment::PROVIDER_MAX_BOT
            || ! in_array($attachment->media_kind, [MessageAttachment::MEDIA_KIND_VIDEO, MessageAttachment::MEDIA_KIND_VIDEO_NOTE], true)
        ) {
            return false;
        }

        $width = $this->normalizeNonNegativeInteger(data_get($metadata, 'width'));
        $height = $this->normalizeNonNegativeInteger(data_get($metadata, 'height'));

        if ($width === null || $height === null || $width === 0 || $height === 0) {
            return false;
        }

        $maxSide = max($width, $height);
        $minSide = min($width, $height);
        $duration = $this->normalizeNonNegativeInteger(data_get($metadata, 'duration'));

        return (($maxSide - $minSide) / $maxSide) <= 0.03
            && $maxSide <= 720
            && ($duration === null || $duration <= 60);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function mergedRawPayloadExcerpt(
        MessageAttachment $attachment,
        array $metadata,
        bool $isVideoNote,
    ): array {
        $excerpt = is_array($attachment->raw_payload_excerpt) ? $attachment->raw_payload_excerpt : [];

        foreach (['width', 'height', 'duration'] as $key) {
            $value = $this->normalizeNonNegativeInteger(data_get($metadata, $key));

            if ($value !== null) {
                $excerpt[$key] = $value;
            }
        }

        if ($isVideoNote) {
            $excerpt['media_kind'] = MessageAttachment::MEDIA_KIND_VIDEO_NOTE;
            $excerpt['is_video_note'] = true;
        }

        return array_filter($excerpt, static fn (mixed $value): bool => $value !== null && $value !== '');
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
            ) ?? $this->hashSensitiveReference(data_get($attachment, 'payload.token') ?? data_get($attachment, 'token'), 'token');
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

        // Здесь link.message.* читается НАМЕРЕННО без forward-гейта (в отличие от
        // BotIncomingMessageNormalizer): это lookup-стог для матчинга по уже
        // сохранённому provider_file_reference, свои вложения идут в стоге раньше
        // link-овских, а строки MessageAttachment, созданные из reply-цитат до
        // f207b891, должны оставаться скачиваемыми (грандфазеринг).
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

    private function validateTrustedMaxMediaUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('MAX media URL is malformed.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            throw new InvalidArgumentException('MAX media URL must use HTTPS and a trusted host.');
        }

        if (array_key_exists('port', $parts) || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            throw new InvalidArgumentException('MAX media URL contains unsupported connection parts.');
        }

        $trustedHosts = array_values(array_filter(
            (array) config('bots.max.trusted_media_hosts', config('bots.max.trusted_avatar_hosts', ['max.ru'])),
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));

        foreach ($trustedHosts as $trustedHost) {
            $normalizedTrustedHost = strtolower(trim($trustedHost));

            if ($host === $normalizedTrustedHost || str_ends_with($host, '.'.$normalizedTrustedHost)) {
                return $url;
            }
        }

        throw new InvalidArgumentException('MAX media URL host is not trusted.');
    }

    private function filenameHintFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && trim($path) !== '' ? $path : null;
    }

    private function filenameFromHint(?string $filenameHint): ?string
    {
        if (! filled($filenameHint)) {
            return null;
        }

        $basename = basename(str_replace('\\', '/', (string) $filenameHint));
        $basename = trim(str_replace("\0", '', $basename), " \t\n\r\0\x0B.");

        return $basename !== '' ? mb_substr($basename, 0, 180) : null;
    }

    private function extensionFromFilename(?string $filename): ?string
    {
        if (! filled($filename)) {
            return null;
        }

        $extension = MessageAttachment::sanitizeExtension(pathinfo((string) $filename, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : null;
    }

    private function extensionFromMimeType(?string $mimeType): ?string
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
            'application/x-tgsticker' => 'tgs',
            'application/zip' => 'zip',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/aac' => 'aac',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
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

    private function mimeTypeFromExtension(mixed $extension): ?string
    {
        return match (MessageAttachment::sanitizeExtension($extension)) {
            'csv' => 'text/csv',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'json' => 'application/json',
            'pdf' => 'application/pdf',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'tgs' => 'application/x-tgsticker',
            'zip' => 'application/zip',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'wav' => 'audio/wav',
            'oga', 'ogg', 'opus' => 'audio/ogg',
            'weba' => 'audio/webm',
            'm4v', 'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'ogv' => 'video/ogg',
            'webm' => 'video/webm',
            default => null,
        };
    }

    private function normalizeMimeType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $mimeType = mb_strtolower(trim(explode(';', $value)[0] ?? ''));

        return in_array($mimeType, [
            'application/json',
            'application/msword',
            'application/pdf',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/x-tgsticker',
            'application/zip',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'audio/mpeg',
            'audio/mp4',
            'audio/aac',
            'audio/wav',
            'audio/x-wav',
            'audio/ogg',
            'audio/webm',
            'text/csv',
            'text/plain',
            'video/mp4',
            'video/ogg',
            'video/quicktime',
            'video/webm',
        ], true) ? $mimeType : null;
    }

    private function normalizeScalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
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

    private function normalizeErrorCode(string $value): string
    {
        $normalized = mb_strtolower($value);
        $normalized = preg_replace('/[^a-z0-9_:-]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_:-');

        return $normalized !== '' ? mb_substr($normalized, 0, 64) : 'bot_media_download_failed';
    }

    private function resolveSafeErrorCode(Throwable $throwable): string
    {
        if ($throwable instanceof InvalidArgumentException) {
            return 'bot_media_download_invalid_payload';
        }

        return 'bot_media_download_failed';
    }

    private function resolveSafeErrorMessage(MessageAttachment $attachment): string
    {
        return match ($attachment->provider) {
            MessageAttachment::PROVIDER_TELEGRAM_BOT => 'Не удалось скачать медиафайл из Telegram Bot.',
            MessageAttachment::PROVIDER_MAX_BOT => 'Не удалось скачать медиафайл из MAX.',
            default => 'Не удалось скачать медиафайл.',
        };
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('bots.media.download_max_bytes', 20 * 1024 * 1024));
    }
}
