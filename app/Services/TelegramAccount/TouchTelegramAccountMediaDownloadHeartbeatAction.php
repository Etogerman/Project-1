<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Services\Messages\InboundMediaDownloadPolicy;
use App\Services\Messages\InboundMediaQuotaExceededException;
use App\Services\Messages\InboundMediaQuotaLedger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TouchTelegramAccountMediaDownloadHeartbeatAction
{
    public function __construct(
        private readonly InboundMediaQuotaLedger $quotaLedger,
    ) {}

    public function handle(
        Channel $channel,
        MessageAttachment $attachment,
        string $claimToken,
        ?int $receivedBytes = null,
    ): MessageAttachment {
        return DB::transaction(function () use ($channel, $attachment, $claimToken, $receivedBytes): MessageAttachment {
            /** @var MessageAttachment $locked */
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $locked->channel_id !== (int) $channel->id
                || $locked->provider !== MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT
                || $locked->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
                || blank($locked->media_download_claim_token)
                || ! hash_equals((string) $locked->media_download_claim_token, $claimToken)
                || $locked->media_download_attempt_deadline_at === null
                || $locked->media_download_attempt_deadline_at->lte(now())
            ) {
                throw new InvalidArgumentException('Media download lease is no longer active.');
            }

            if ($receivedBytes !== null) {
                $limit = $locked->media_download_max_bytes;

                if (is_int($limit) && $limit > 0 && $receivedBytes > $limit) {
                    throw new InboundMediaQuotaExceededException(
                        $locked->manual_download_requested_at !== null
                            ? InboundMediaDownloadPolicy::REASON_MANUAL_HARD_LIMIT
                            : InboundMediaDownloadPolicy::REASON_SIZE_ABOVE_AUTO_LIMIT,
                        $receivedBytes,
                    );
                }

                $this->quotaLedger->checkpointTraffic(
                    $locked,
                    $locked->mediaDownloadLedgerAttemptNumber(),
                    $receivedBytes,
                );
            }

            $locked->forceFill([
                'media_download_heartbeat_at' => now(),
                'updated_at' => now(),
            ])->save();

            return $locked->fresh();
        }, 3);
    }
}
