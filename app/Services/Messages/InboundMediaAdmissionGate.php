<?php

namespace App\Services\Messages;

use App\Models\Channel;
use App\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

class InboundMediaAdmissionGate
{
    public const REASON_CHANNEL_CONCURRENCY = 'channel_concurrency_limit';

    public const REASON_GLOBAL_CONCURRENCY = 'global_concurrency_limit';

    public const REASON_IDENTITY_CONCURRENCY = 'identity_concurrency_limit';

    public const REASON_QUEUE_FAIRNESS = 'queue_fairness';

    private const LOCK_ID = 1;

    public function lock(): void
    {
        $lock = DB::table('inbound_media_admission_locks')
            ->where('id', self::LOCK_ID)
            ->lockForUpdate()
            ->first();

        if ($lock === null) {
            throw new LogicException('Inbound media admission lock is not initialized.');
        }
    }

    public function shouldPreferAutomatic(Channel $channel): bool
    {
        return $this->manualClaimStreak($channel) >= $this->manualToAutomaticRatio();
    }

    /**
     * @throws InboundMediaAdmissionDeniedException
     */
    public function assertCanClaim(
        Channel $channel,
        bool $manual,
        ?int $attachmentId = null,
    ): void {
        if (
            $manual
            && $this->shouldPreferAutomatic($channel)
            && $this->hasDueAutomaticCandidate($channel, $attachmentId)
        ) {
            $this->deny(self::REASON_QUEUE_FAIRNESS);
        }

        $activeQuery = $this->activeDownloadsQuery($attachmentId);
        $globalLimit = max(0, (int) config('inbound_media.admission.global_max_active', 4));

        if ($globalLimit > 0 && (clone $activeQuery)->count() >= $globalLimit) {
            $this->deny(self::REASON_GLOBAL_CONCURRENCY);
        }

        $channelLimit = max(0, (int) config('inbound_media.admission.channel_max_active', 2));

        if (
            $channelLimit > 0
            && (clone $activeQuery)->where('channel_id', $channel->id)->count() >= $channelLimit
        ) {
            $this->deny(self::REASON_CHANNEL_CONCURRENCY);
        }

        $identityLimit = max(0, (int) config('inbound_media.admission.identity_max_active', 2));

        if ($identityLimit === 0) {
            return;
        }

        $identityChannelIds = $this->identityChannelIds($channel);

        if (
            $identityChannelIds !== []
            && (clone $activeQuery)->whereIn('channel_id', $identityChannelIds)->count() >= $identityLimit
        ) {
            $this->deny(self::REASON_IDENTITY_CONCURRENCY);
        }
    }

    public function recordClaim(Channel $channel, bool $manual): void
    {
        $streak = $manual
            ? min(255, $this->manualClaimStreak($channel) + 1)
            : 0;

        DB::table('inbound_media_queue_cursors')
            ->where('channel_id', $channel->id)
            ->update([
                'manual_claim_streak' => $streak,
                'updated_at' => now(),
            ]);
    }

    private function deny(string $reason): never
    {
        throw new InboundMediaAdmissionDeniedException(
            $reason,
            max(1, (int) config('inbound_media.admission.retry_after_seconds', 5)),
        );
    }

    /**
     * @return Builder<MessageAttachment>
     */
    private function activeDownloadsQuery(?int $attachmentId): Builder
    {
        $staleBefore = now()->subSeconds(
            max(1, (int) config('inbound_media.lease_stale_seconds', 120)),
        );

        return MessageAttachment::query()
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING)
            ->when(
                $attachmentId !== null,
                static fn (Builder $query): Builder => $query->whereKeyNot($attachmentId),
            )
            ->where(function (Builder $query) use ($staleBefore): void {
                $query
                    ->where('media_download_heartbeat_at', '>=', $staleBefore)
                    ->orWhere(function (Builder $legacyQuery) use ($staleBefore): void {
                        $legacyQuery
                            ->whereNull('media_download_heartbeat_at')
                            ->where(function (Builder $timestampQuery) use ($staleBefore): void {
                                $timestampQuery
                                    ->where('media_download_claimed_at', '>=', $staleBefore)
                                    ->orWhere(function (Builder $updatedQuery) use ($staleBefore): void {
                                        $updatedQuery
                                            ->whereNull('media_download_claimed_at')
                                            ->where('updated_at', '>=', $staleBefore);
                                    });
                            });
                    });
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('media_download_attempt_deadline_at')
                    ->orWhere('media_download_attempt_deadline_at', '>', now());
            });
    }

    private function hasDueAutomaticCandidate(Channel $channel, ?int $attachmentId): bool
    {
        return MessageAttachment::query()
            ->where('channel_id', $channel->id)
            ->whereIn('provider', [
                MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
                MessageAttachment::PROVIDER_TELEGRAM_BOT,
                MessageAttachment::PROVIDER_MAX_BOT,
            ])
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD)
            ->whereNull('manual_download_requested_at')
            ->when(
                $attachmentId !== null,
                static fn (Builder $query): Builder => $query->whereKeyNot($attachmentId),
            )
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('media_download_next_retry_at')
                    ->orWhere('media_download_next_retry_at', '<=', now());
            })
            ->exists();
    }

    /**
     * @return list<int>
     */
    private function identityChannelIds(Channel $channel): array
    {
        $query = Channel::query()
            ->where('platform', $channel->platform)
            ->where('connection_type', $channel->connection_type);

        if (filled($channel->bot_external_id)) {
            $query->where('bot_external_id', $channel->bot_external_id);
        } elseif (filled($channel->bot_username)) {
            $query->whereRaw('LOWER(bot_username) = ?', [mb_strtolower((string) $channel->bot_username)]);
        } else {
            $query->whereKey($channel->id);
        }

        return $query
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function manualClaimStreak(Channel $channel): int
    {
        DB::table('inbound_media_queue_cursors')->insertOrIgnore([
            'channel_id' => $channel->id,
            'manual_claim_streak' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cursor = DB::table('inbound_media_queue_cursors')
            ->where('channel_id', $channel->id)
            ->lockForUpdate()
            ->first();

        return max(0, (int) ($cursor->manual_claim_streak ?? 0));
    }

    private function manualToAutomaticRatio(): int
    {
        return max(1, (int) config('inbound_media.admission.manual_to_automatic_ratio', 3));
    }
}
