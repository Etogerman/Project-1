<?php

namespace App\Services\Bots;

use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Messages\InboundMediaDownloadPolicy;
use App\Services\Messages\ResolvePinnedHttpsUrlAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ProbeMaxBotMediaMetadataAction
{
    public function __construct(
        private readonly ResolveMaxBotMediaSourceAction $resolveMaxBotMediaSourceAction,
        private readonly ResolvePinnedHttpsUrlAction $resolvePinnedHttpsUrlAction,
        private readonly InboundMediaDownloadPolicy $mediaDownloadPolicy,
    ) {}

    public function handle(
        int $attachmentId,
        bool $allowAutomaticDownload = true,
    ): ?MessageAttachment {
        $attachment = MessageAttachment::query()
            ->with(['message.channel'])
            ->find($attachmentId);

        if (! $this->shouldProbe($attachment)) {
            return $attachment;
        }

        $message = $attachment->message;
        $channel = $message?->channel;

        if (! $message instanceof Message || ! $channel instanceof Channel) {
            return $attachment;
        }

        $source = $this->resolveMaxBotMediaSourceAction->handle($channel, $message, $attachment);
        $url = $source?->downloadUrl;

        if ($source === null || $url === null) {
            throw new RuntimeException('MAX media metadata source is not ready yet.');
        }

        $sourceMetadata = $source->metadata();
        $sizeBytes = null;

        try {
            if (! is_int($attachment->file_size_bytes) || $attachment->file_size_bytes <= 0) {
                $sizeBytes = $this->probeContentLength($url);
            }
        } catch (Throwable $exception) {
            if ($sourceMetadata !== []) {
                $this->commitMetadata(
                    attachmentId: $attachmentId,
                    sizeBytes: null,
                    sourceMetadata: $sourceMetadata,
                    allowAutomaticDownload: $allowAutomaticDownload,
                );
            }

            throw $exception;
        }

        return $this->commitMetadata(
            attachmentId: $attachmentId,
            sizeBytes: $sizeBytes,
            sourceMetadata: $sourceMetadata,
            allowAutomaticDownload: $allowAutomaticDownload,
        );
    }

    private function shouldProbe(?MessageAttachment $attachment): bool
    {
        if (
            ! $attachment instanceof MessageAttachment
            || $attachment->provider !== MessageAttachment::PROVIDER_MAX_BOT
            || filled($attachment->local_disk)
            || filled($attachment->local_path)
            || $attachment->manual_download_requested_at !== null
            || ! in_array($attachment->download_status, [
                MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            ], true)
        ) {
            return false;
        }

        $sizeMissing = ! is_int($attachment->file_size_bytes) || $attachment->file_size_bytes <= 0;
        $durationMissing = in_array($attachment->media_kind, [
            MessageAttachment::MEDIA_KIND_VIDEO,
            MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
            MessageAttachment::MEDIA_KIND_AUDIO,
            MessageAttachment::MEDIA_KIND_VOICE,
        ], true) && $this->normalizeNonNegativeInteger(data_get($attachment->provider_metadata, 'duration')) === null;

        return $sizeMissing || $durationMissing;
    }

    private function probeContentLength(string $url): int
    {
        $trustedHosts = array_values(array_filter(
            (array) config('bots.max.trusted_media_hosts', config('bots.max.trusted_avatar_hosts', ['max.ru'])),
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
        $pinnedUrl = $this->resolvePinnedHttpsUrlAction->handle(
            $url,
            $trustedHosts,
            (array) config('bots.max.pinned_media_ips', []),
        );
        $response = Http::withOptions([
            'allow_redirects' => false,
            'connect_timeout' => 5,
            'read_timeout' => 5,
            'timeout' => 10,
            'curl' => $pinnedUrl->curlOptions,
        ])
            ->withoutRedirecting()
            ->head($pinnedUrl->url);

        if (! $response->successful()) {
            throw new RuntimeException("MAX media metadata probe failed with HTTP {$response->status()}.");
        }

        $contentLength = $this->normalizePositiveInteger($response->header('Content-Length'));

        if ($contentLength === null) {
            throw new RuntimeException('MAX media metadata probe did not return Content-Length.');
        }

        return $contentLength;
    }

    /**
     * @param  array<string, int>  $sourceMetadata
     */
    private function commitMetadata(
        int $attachmentId,
        ?int $sizeBytes,
        array $sourceMetadata,
        bool $allowAutomaticDownload,
    ): ?MessageAttachment {
        return DB::transaction(function () use (
            $attachmentId,
            $sizeBytes,
            $sourceMetadata,
            $allowAutomaticDownload,
        ): ?MessageAttachment {
            $attachment = MessageAttachment::query()
                ->with('channel')
                ->whereKey($attachmentId)
                ->lockForUpdate()
                ->first();

            if (! $this->shouldCommitProbe($attachment)) {
                return $attachment;
            }

            $attributes = [];
            $metadata = is_array($attachment->provider_metadata)
                ? $attachment->provider_metadata
                : [];

            foreach (['width', 'height', 'duration'] as $key) {
                if ($this->normalizeNonNegativeInteger(data_get($metadata, $key)) !== null) {
                    continue;
                }

                $value = $this->normalizeNonNegativeInteger(data_get($sourceMetadata, $key));

                if ($value !== null) {
                    $metadata[$key] = $value;
                }
            }

            if ($metadata !== ($attachment->provider_metadata ?? [])) {
                $attributes['provider_metadata'] = $metadata;
            }

            if (
                $sizeBytes !== null
                && (! is_int($attachment->file_size_bytes) || $attachment->file_size_bytes <= 0)
            ) {
                $attributes['file_size_bytes'] = $sizeBytes;
            }

            $effectiveSize = $attributes['file_size_bytes'] ?? $attachment->file_size_bytes;

            if (
                is_int($effectiveSize)
                && $effectiveSize > 0
                && $attachment->download_status === MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND
                && $attachment->safe_error_code === InboundMediaDownloadPolicy::REASON_SIZE_UNKNOWN
                && $attachment->manual_download_requested_at === null
                && $attachment->channel instanceof Channel
            ) {
                $decision = $this->mediaDownloadPolicy->initialDecision(
                    $attachment->channel,
                    MessageAttachment::PROVIDER_MAX_BOT,
                    (string) $attachment->media_kind,
                    $effectiveSize,
                );

                if (
                    $allowAutomaticDownload
                    || $decision['status'] === MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND
                ) {
                    $attributes['download_status'] = $decision['status'];
                    $attributes['safe_error_code'] = $decision['reason'];
                    $attributes['safe_error_message'] = $decision['message'];
                } else {
                    $attributes['safe_error_code'] = null;
                    $attributes['safe_error_message'] = null;
                }
            }

            if ($attributes !== []) {
                $attachment->forceFill($attributes)->save();
            }

            return $attachment->load('message');
        });
    }

    private function shouldCommitProbe(?MessageAttachment $attachment): bool
    {
        return $attachment instanceof MessageAttachment
            && $attachment->provider === MessageAttachment::PROVIDER_MAX_BOT
            && blank($attachment->local_disk)
            && blank($attachment->local_path)
            && $attachment->manual_download_requested_at === null
            && in_array($attachment->download_status, [
                MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            ], true);
    }

    private function normalizePositiveInteger(mixed $value): ?int
    {
        $normalized = $this->normalizeNonNegativeInteger($value);

        return $normalized !== null && $normalized > 0 ? $normalized : null;
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
