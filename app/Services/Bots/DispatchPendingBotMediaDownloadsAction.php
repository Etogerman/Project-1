<?php

namespace App\Services\Bots;

use App\Jobs\DownloadBotMessageAttachmentJob;
use App\Models\Message;
use App\Models\MessageAttachment;

class DispatchPendingBotMediaDownloadsAction
{
    public function handle(Message $message): void
    {
        $attachmentIds = MessageAttachment::query()
            ->where('message_id', $message->id)
            ->whereIn('provider', [
                MessageAttachment::PROVIDER_TELEGRAM_BOT,
                MessageAttachment::PROVIDER_MAX_BOT,
            ])
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD)
            ->whereNull('manual_download_requested_at')
            ->orderBy('id')
            ->pluck('id');

        foreach ($attachmentIds as $attachmentId) {
            DownloadBotMessageAttachmentJob::dispatch((int) $attachmentId, manual: false)
                ->afterCommit();
        }
    }
}
