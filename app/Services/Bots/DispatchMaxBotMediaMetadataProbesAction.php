<?php

namespace App\Services\Bots;

use App\Jobs\ProbeMaxBotMediaMetadataJob;
use App\Models\Message;
use App\Models\MessageAttachment;

class DispatchMaxBotMediaMetadataProbesAction
{
    public function handle(Message $message, bool $allowAutomaticDownload = true): void
    {
        $attachments = MessageAttachment::query()
            ->where('message_id', $message->id)
            ->where('provider', MessageAttachment::PROVIDER_MAX_BOT)
            ->whereIn('download_status', [
                MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            ])
            ->whereNull('manual_download_requested_at')
            ->whereNull('local_path')
            ->orderBy('id')
            ->get(['id', 'media_kind', 'file_size_bytes', 'provider_metadata']);

        foreach ($attachments as $attachment) {
            $sizeMissing = ! is_int($attachment->file_size_bytes)
                || $attachment->file_size_bytes <= 0;
            $durationMissing = in_array($attachment->media_kind, [
                MessageAttachment::MEDIA_KIND_VIDEO,
                MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
                MessageAttachment::MEDIA_KIND_AUDIO,
                MessageAttachment::MEDIA_KIND_VOICE,
            ], true) && $this->normalizeNonNegativeInteger(
                data_get($attachment->provider_metadata, 'duration')
            ) === null;

            if (! $sizeMissing && ! $durationMissing) {
                continue;
            }

            ProbeMaxBotMediaMetadataJob::dispatch(
                (int) $attachment->id,
                $allowAutomaticDownload,
            )->afterCommit();
        }
    }

    private function normalizeNonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
