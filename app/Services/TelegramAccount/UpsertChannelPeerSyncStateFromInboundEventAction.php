<?php

namespace App\Services\TelegramAccount;

use App\Data\TelegramAccount\NormalizedInboundMessageEvent;
use App\Models\Channel;
use App\Models\ChannelPeerSyncState;

class UpsertChannelPeerSyncStateFromInboundEventAction
{
    public function handle(Channel $channel, NormalizedInboundMessageEvent $event): ChannelPeerSyncState
    {
        $peerSyncState = ChannelPeerSyncState::query()->firstOrNew([
            'channel_id' => $channel->id,
            'peer_key' => $event->peerKey,
        ]);

        $peerSyncState->external_chat_id = $event->externalChatId;
        $peerSyncState->latest_observed_message_id = $this->resolveCanonicalMaxId(
            $peerSyncState->latest_observed_message_id,
            $event->externalMessageId,
        );

        if ($event->historySource === NormalizedInboundMessageEvent::HISTORY_SOURCE_BACKFILL) {
            $peerSyncState->backfill_status = ChannelPeerSyncState::BACKFILL_STATUS_IN_PROGRESS;
            $peerSyncState->oldest_imported_message_id = $this->resolveCanonicalMinId(
                $peerSyncState->oldest_imported_message_id,
                $event->externalMessageId,
            );
        } elseif (! filled($peerSyncState->backfill_status)) {
            $peerSyncState->backfill_status = ChannelPeerSyncState::BACKFILL_STATUS_NOT_STARTED;
        }

        $peerSyncState->save();

        return $peerSyncState;
    }

    private function resolveCanonicalMinId(?string $current, string $candidate): string
    {
        if (! filled($current)) {
            return $candidate;
        }

        return $this->compareMessageIds($current, $candidate) <= 0
            ? $current
            : $candidate;
    }

    private function resolveCanonicalMaxId(?string $current, string $candidate): string
    {
        if (! filled($current)) {
            return $candidate;
        }

        return $this->compareMessageIds($current, $candidate) >= 0
            ? $current
            : $candidate;
    }

    private function compareMessageIds(string $left, string $right): int
    {
        if (ctype_digit($left) && ctype_digit($right)) {
            $left = ltrim($left, '0');
            $right = ltrim($right, '0');

            $left = $left === '' ? '0' : $left;
            $right = $right === '' ? '0' : $right;

            $lengthComparison = strlen($left) <=> strlen($right);

            if ($lengthComparison !== 0) {
                return $lengthComparison;
            }
        }

        return strcmp($left, $right);
    }
}
