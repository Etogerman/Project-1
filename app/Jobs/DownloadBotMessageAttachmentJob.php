<?php

namespace App\Jobs;

use App\Models\MessageAttachment;
use App\Services\Bots\DownloadBotMessageAttachmentsAction;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DownloadBotMessageAttachmentJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly int $attachmentId,
        public readonly bool $manual = true,
    ) {
        $this->timeout = max(30, (int) config('inbound_media.attempt_deadline_seconds', 6 * 60 * 60));
        $this->uniqueFor = $this->timeout + 30;
        $this->onConnection((string) config('inbound_media.queue.connection', 'inbound-media'));
        $this->onQueue((string) config('inbound_media.queue.name', 'inbound-media'));
    }

    public function uniqueId(): string
    {
        return $this->attachmentId.':'.($this->manual ? 'manual' : 'automatic');
    }

    public function handle(DownloadBotMessageAttachmentsAction $downloadAction): void
    {
        $attachment = MessageAttachment::query()
            ->with('message')
            ->find($this->attachmentId);

        if (! $attachment instanceof MessageAttachment || $attachment->message === null) {
            return;
        }

        $downloadAction->handle(
            $attachment->message,
            attachmentIds: [$attachment->id],
            manual: $this->manual,
        );
    }
}
