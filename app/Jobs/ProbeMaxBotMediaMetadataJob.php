<?php

namespace App\Jobs;

use App\Models\MessageAttachment;
use App\Services\Bots\ProbeMaxBotMediaMetadataAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProbeMaxBotMediaMetadataJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public int $uniqueFor = 300;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function uniqueId(): string
    {
        return $this->attachmentId.':'.($this->allowAutomaticDownload ? 'automatic' : 'backfill');
    }

    public function __construct(
        public readonly int $attachmentId,
        public readonly bool $allowAutomaticDownload = true,
    ) {}

    public function handle(
        ProbeMaxBotMediaMetadataAction $probeAction,
    ): void {
        $attachment = $probeAction->handle(
            $this->attachmentId,
            $this->allowAutomaticDownload,
        );

        if (
            ! $this->allowAutomaticDownload
            || ! $attachment instanceof MessageAttachment
            || $attachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD
            || ! $attachment->wasChanged('download_status')
            || $attachment->message === null
        ) {
            return;
        }

        DownloadBotMessageAttachmentJob::dispatch($attachment->id, manual: false)
            ->afterCommit();
    }
}
