<?php

namespace App\Services\Bots;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Messages\InboundMediaDownloadPolicy;
use App\Services\Messages\InboundMediaQuotaLedger;
use Illuminate\Support\Facades\DB;

class CancelRemovedMessageMediaDownloadsAction
{
    public function __construct(
        private readonly InboundMediaQuotaLedger $quotaLedger,
    ) {}

    public function handle(Message $message): void
    {
        $attachmentIds = MessageAttachment::query()
            ->where('message_id', $message->id)
            ->whereIn('provider', [
                MessageAttachment::PROVIDER_TELEGRAM_BOT,
                MessageAttachment::PROVIDER_MAX_BOT,
            ])
            ->where(function ($query): void {
                $query
                    ->where('download_status', '!=', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED)
                    ->orWhereNull('download_status');
            })
            ->pluck('id');

        foreach ($attachmentIds as $attachmentId) {
            DB::transaction(function () use ($attachmentId): void {
                $attachment = MessageAttachment::query()
                    ->whereKey($attachmentId)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $attachment instanceof MessageAttachment
                    || $attachment->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
                    || filled($attachment->local_disk)
                    || filled($attachment->local_path)
                ) {
                    return;
                }

                $this->quotaLedger->failAttempt(
                    $attachment,
                    $attachment->mediaDownloadLedgerAttemptNumber(),
                    0,
                    InboundMediaDownloadPolicy::REASON_SOURCE_UNAVAILABLE,
                );

                $attachment->forceFill([
                    'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                    'manual_download_requested_at' => null,
                    'manual_download_requested_by_user_id' => null,
                    'media_download_claim_token' => null,
                    'media_download_upload_size_bytes' => null,
                    'media_download_next_retry_at' => null,
                    'media_download_claimed_at' => null,
                    'media_download_heartbeat_at' => null,
                    'media_download_attempt_deadline_at' => null,
                    'safe_error_code' => InboundMediaDownloadPolicy::REASON_SOURCE_UNAVAILABLE,
                    'safe_error_message' => 'Источник больше недоступен.',
                ])->save();
            }, 3);
        }
    }
}
