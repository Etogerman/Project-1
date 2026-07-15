<?php

namespace App\Services\Messages;

use App\Models\MessageAttachment;
use InvalidArgumentException;

class ValidateInboundMediaIntegrityAction
{
    private const DETECTION_SAMPLE_BYTES = 64 * 1024;

    /**
     * @param  resource  $stream
     */
    public function inspectStream(
        MessageAttachment $attachment,
        mixed $stream,
        int $actualSizeBytes,
        ?int $providerSizeBytes = null,
        ?string $declaredMimeType = null,
    ): ?string {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Inbound media integrity inspection requires a readable stream.');
        }

        $position = ftell($stream);

        if ($position === false || fseek($stream, 0) !== 0) {
            throw new InvalidArgumentException('Failed to inspect inbound media stream.');
        }

        try {
            $sample = fread($stream, self::DETECTION_SAMPLE_BYTES);
        } finally {
            if (fseek($stream, $position) !== 0) {
                throw new InvalidArgumentException('Failed to restore inbound media stream position.');
            }
        }

        return $this->inspectContents(
            attachment: $attachment,
            sample: is_string($sample) && $sample !== '' ? $sample : null,
            actualSizeBytes: $actualSizeBytes,
            providerSizeBytes: $providerSizeBytes,
            declaredMimeType: $declaredMimeType,
        );
    }

    public function inspectContents(
        MessageAttachment $attachment,
        ?string $sample,
        int $actualSizeBytes,
        ?int $providerSizeBytes = null,
        ?string $declaredMimeType = null,
    ): ?string {
        if ($actualSizeBytes < 1) {
            throw new MediaDownloadIntegrityException('Downloaded media is empty.');
        }

        if ($providerSizeBytes !== null && $providerSizeBytes !== $actualSizeBytes) {
            throw new MediaDownloadIntegrityException('Downloaded media size does not match the provider size.');
        }

        $detectedMimeType = $this->detectMimeType($sample);
        $normalizedDeclaredMimeType = $this->normalizeMimeType($declaredMimeType);

        if ($this->isClearlyProviderErrorPayload(
            $sample,
            $detectedMimeType,
            $attachment->media_kind,
            $normalizedDeclaredMimeType,
        )) {
            throw new MediaDownloadIntegrityException('Downloaded media contains a provider error payload.');
        }

        if ($this->isKnownMediaTypeMismatch($attachment->media_kind, $detectedMimeType)) {
            throw new MediaDownloadIntegrityException('Downloaded media type does not match the provider metadata.');
        }

        if (
            $normalizedDeclaredMimeType !== null
            && $detectedMimeType !== null
            && $this->isKnownBinaryMediaMimeType($normalizedDeclaredMimeType)
            && $this->isKnownBinaryMediaMimeType($detectedMimeType)
            && $this->mimeFamily($normalizedDeclaredMimeType) !== $this->mimeFamily($detectedMimeType)
        ) {
            throw new MediaDownloadIntegrityException('Downloaded media MIME type does not match the response metadata.');
        }

        return $detectedMimeType;
    }

    private function detectMimeType(?string $sample): ?string
    {
        if ($sample === null || $sample === '') {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        try {
            $detected = finfo_buffer($finfo, $sample);
        } finally {
            finfo_close($finfo);
        }

        return $this->normalizeMimeType(is_string($detected) ? $detected : null);
    }

    private function isClearlyProviderErrorPayload(
        ?string $sample,
        ?string $detectedMimeType,
        string $mediaKind,
        ?string $declaredMimeType,
    ): bool {
        if ($sample === null) {
            return false;
        }

        if (
            $mediaKind === MessageAttachment::MEDIA_KIND_DOCUMENT
            && ($declaredMimeType === null || $this->isTextDocumentMimeType($declaredMimeType))
        ) {
            return false;
        }

        if (in_array($detectedMimeType, [
            'text/html',
            'application/xhtml+xml',
            'application/json',
            'application/xml',
            'text/xml',
        ], true)) {
            return true;
        }

        $prefix = mb_strtolower(ltrim(substr($sample, 0, 256)));

        return str_starts_with($prefix, '<!doctype html')
            || str_starts_with($prefix, '<html')
            || str_starts_with($prefix, '<?xml')
            || str_starts_with($prefix, '{')
            || str_starts_with($prefix, '[');
    }

    private function isTextDocumentMimeType(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'text/')
            || in_array($mimeType, [
                'application/json',
                'application/ld+json',
                'application/xml',
                'application/xhtml+xml',
                'application/javascript',
                'application/x-ndjson',
            ], true)
            || str_ends_with($mimeType, '+json')
            || str_ends_with($mimeType, '+xml');
    }

    private function isKnownMediaTypeMismatch(string $mediaKind, ?string $detectedMimeType): bool
    {
        if ($detectedMimeType === null || ! $this->isKnownBinaryMediaMimeType($detectedMimeType)) {
            return false;
        }

        $family = $this->mimeFamily($detectedMimeType);

        return match ($mediaKind) {
            MessageAttachment::MEDIA_KIND_IMAGE => $family !== 'image',
            MessageAttachment::MEDIA_KIND_VIDEO,
            MessageAttachment::MEDIA_KIND_VIDEO_NOTE => $family !== 'video',
            MessageAttachment::MEDIA_KIND_AUDIO,
            MessageAttachment::MEDIA_KIND_VOICE => $family !== 'audio',
            MessageAttachment::MEDIA_KIND_ANIMATION => ! in_array($family, ['image', 'video'], true),
            default => false,
        };
    }

    private function isKnownBinaryMediaMimeType(string $mimeType): bool
    {
        return in_array($this->mimeFamily($mimeType), ['image', 'video', 'audio'], true)
            || $mimeType === 'application/pdf';
    }

    private function mimeFamily(string $mimeType): string
    {
        return strstr($mimeType, '/', true) ?: $mimeType;
    }

    private function normalizeMimeType(?string $mimeType): ?string
    {
        if ($mimeType === null) {
            return null;
        }

        $normalized = mb_strtolower(trim(strtok($mimeType, ';') ?: ''));

        return preg_match('/\A[a-z0-9][a-z0-9!#$&^_.+-]{0,126}\/[a-z0-9][a-z0-9!#$&^_.+-]{0,126}\z/', $normalized) === 1
            ? $normalized
            : null;
    }
}
