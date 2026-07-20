<?php

namespace App\Console\Commands;

use App\Jobs\ProbeMaxBotMediaMetadataJob;
use App\Models\MessageAttachment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ProbeMaxBotMediaMetadataCommand extends Command
{
    protected $signature = 'bot-media:probe-max-metadata
        {--force : Dispatch metadata probes instead of dry-run}
        {--channel= : Limit to one channel ID}
        {--attachment=* : Limit to one or more attachment IDs}
        {--limit=100 : Maximum matching attachments to inspect}';

    protected $description = 'Probe size and duration metadata for stored MAX media without downloading file bodies.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $limit = min(max((int) $this->option('limit'), 1), 1000);
        $channelId = $this->option('channel');
        $attachmentOptions = collect((array) $this->option('attachment'));

        if ($attachmentOptions->contains(
            static function (mixed $value): bool {
                if (is_int($value)) {
                    return $value <= 0;
                }

                if (! is_string($value)) {
                    return true;
                }

                $value = trim($value);

                return $value === '' || ! ctype_digit($value) || (int) $value <= 0;
            },
        )) {
            $this->error('Each --attachment value must be a positive integer.');

            return self::INVALID;
        }

        $attachmentIds = $attachmentOptions
            ->map(static fn (mixed $value): int => (int) $value)
            ->unique()
            ->values();

        $query = MessageAttachment::query()
            ->where('provider', MessageAttachment::PROVIDER_MAX_BOT)
            ->whereIn('download_status', [
                MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            ])
            ->whereNull('manual_download_requested_at')
            ->whereNull('local_path')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('file_size_bytes')
                    ->orWhere('file_size_bytes', '<=', 0)
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->whereIn('media_kind', [
                                MessageAttachment::MEDIA_KIND_VIDEO,
                                MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
                                MessageAttachment::MEDIA_KIND_AUDIO,
                                MessageAttachment::MEDIA_KIND_VOICE,
                            ])
                            ->whereNull('provider_metadata->duration');
                    });
            })
            ->when(filled($channelId), fn (Builder $query): Builder => $query->where('channel_id', (int) $channelId))
            ->when($attachmentIds->isNotEmpty(), fn (Builder $query): Builder => $query->whereKey($attachmentIds))
            ->orderBy('id');

        $matchingCount = (clone $query)->count();
        $attachments = (clone $query)
            ->limit($limit)
            ->get(['id', 'message_id', 'channel_id', 'media_kind', 'download_status']);

        $this->line($force
            ? 'MAX media metadata probe dispatch started.'
            : 'MAX media metadata probe dry-run.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['matching_attachments', (string) $matchingCount],
                ['inspected_attachments', (string) $attachments->count()],
                ['messages', (string) $attachments->pluck('message_id')->unique()->count()],
            ],
        );

        if (! $force || $attachments->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($attachments as $attachment) {
            ProbeMaxBotMediaMetadataJob::dispatch(
                (int) $attachment->id,
                allowAutomaticDownload: false,
            )->afterCommit();
        }

        $this->info("Metadata probes dispatched: {$attachments->count()}.");

        return self::SUCCESS;
    }
}
