<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Bots\DownloadBotMessageAttachmentsAction;
use Illuminate\Console\Command;

class DownloadPendingBotMediaAttachmentsCommand extends Command
{
    protected $signature = 'bot-media:download-pending-images
        {--force : Download and persist local files instead of dry-run}
        {--channel= : Limit to one channel ID}
        {--limit=50 : Maximum matching attachments to inspect}';

    protected $description = 'Download pending Telegram Bot and MAX media attachments into private local storage.';

    public function __construct(
        private readonly DownloadBotMessageAttachmentsAction $downloadBotMessageAttachmentsAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $limit = min(max((int) $this->option('limit'), 1), 500);
        $channelId = $this->option('channel');

        $query = MessageAttachment::query()
            ->where(function ($query): void {
                $query
                    ->where(function ($query): void {
                        $query
                            ->where('provider', MessageAttachment::PROVIDER_TELEGRAM_BOT)
                            ->whereIn('media_kind', [
                                MessageAttachment::MEDIA_KIND_IMAGE,
                                MessageAttachment::MEDIA_KIND_DOCUMENT,
                                MessageAttachment::MEDIA_KIND_VIDEO,
                                MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
                                MessageAttachment::MEDIA_KIND_AUDIO,
                                MessageAttachment::MEDIA_KIND_VOICE,
                                MessageAttachment::MEDIA_KIND_STICKER,
                                MessageAttachment::MEDIA_KIND_ANIMATION,
                            ]);
                    })
                    ->orWhere(function ($query): void {
                        $query
                            ->where('provider', MessageAttachment::PROVIDER_MAX_BOT)
                            ->whereIn('media_kind', [
                                MessageAttachment::MEDIA_KIND_IMAGE,
                                MessageAttachment::MEDIA_KIND_DOCUMENT,
                                MessageAttachment::MEDIA_KIND_VIDEO,
                                MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
                                MessageAttachment::MEDIA_KIND_AUDIO,
                                MessageAttachment::MEDIA_KIND_STICKER,
                            ]);
                    });
            })
            ->whereIn('download_status', [
                MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            ])
            ->when(filled($channelId), fn ($builder) => $builder->where('channel_id', (int) $channelId))
            ->orderBy('id');

        $candidateCount = (clone $query)->count();
        $candidateAttachments = (clone $query)
            ->limit($limit)
            ->get(['id', 'message_id', 'channel_id', 'provider', 'download_status']);

        $this->line($force
            ? 'Bot media download started.'
            : 'Bot media download dry-run.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['matching_attachments', (string) $candidateCount],
                ['inspected_attachments', (string) $candidateAttachments->count()],
                ['messages', (string) $candidateAttachments->pluck('message_id')->unique()->count()],
            ],
        );

        if (! $force || $candidateAttachments->isEmpty()) {
            return self::SUCCESS;
        }

        $downloadedBefore = MessageAttachment::query()
            ->whereIn('id', $candidateAttachments->pluck('id'))
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED)
            ->count();

        Message::query()
            ->whereKey($candidateAttachments->pluck('message_id')->unique()->values())
            ->with(['channel', 'attachments'])
            ->get()
            ->each(fn (Message $message) => $this->downloadBotMessageAttachmentsAction->handle($message));

        $processedAttachments = MessageAttachment::query()
            ->whereIn('id', $candidateAttachments->pluck('id'))
            ->get(['download_status']);

        $downloadedAfter = $processedAttachments
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED)
            ->count();
        $failedAfter = $processedAttachments
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED)
            ->count();

        $this->table(
            ['Result', 'Count'],
            [
                ['downloaded_now', (string) max(0, $downloadedAfter - $downloadedBefore)],
                ['downloaded_total_in_batch', (string) $downloadedAfter],
                ['failed_in_batch', (string) $failedAfter],
            ],
        );

        return $failedAfter > 0 ? self::FAILURE : self::SUCCESS;
    }
}
