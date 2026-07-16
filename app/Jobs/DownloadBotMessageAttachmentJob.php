<?php

namespace App\Jobs;

use App\Models\MessageAttachment;
use App\Services\Bots\DownloadBotMessageAttachmentsAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DownloadBotMessageAttachmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 21600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly int $attachmentId,
        public readonly bool $manual = true,
    ) {}

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
