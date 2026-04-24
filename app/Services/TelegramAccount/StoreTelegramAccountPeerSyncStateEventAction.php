<?php

namespace App\Services\TelegramAccount;

use App\Data\TelegramAccount\NormalizedPeerSyncStateEvent;
use App\Models\Channel;
use App\Models\ChannelPeerSyncState;

class StoreTelegramAccountPeerSyncStateEventAction
{
    public function handle(Channel $channel, NormalizedPeerSyncStateEvent $event): ChannelPeerSyncState
    {
        $peerSyncState = ChannelPeerSyncState::query()->firstOrNew([
            'channel_id' => $channel->id,
            'peer_key' => $event->peerKey,
        ]);

        $peerSyncState->external_chat_id = $event->externalChatId;
        $peerSyncState->backfill_status = $event->backfillStatus;

        if ($event->hasOldestImportedMessageId) {
            $peerSyncState->oldest_imported_message_id = $event->oldestImportedMessageId;
        }

        if ($event->hasLatestObservedMessageId) {
            $peerSyncState->latest_observed_message_id = $event->latestObservedMessageId;
        }

        if ($event->hasHistoryCompleteAt) {
            $peerSyncState->history_complete_at = $event->historyCompleteAt;
        }

        if ($event->hasLastSyncError) {
            $peerSyncState->last_sync_error = $event->lastSyncError;
        }

        if ($event->backfillStatus !== ChannelPeerSyncState::BACKFILL_STATUS_COMPLETE) {
            $peerSyncState->history_complete_at = null;
        }

        if ($event->backfillStatus === ChannelPeerSyncState::BACKFILL_STATUS_COMPLETE) {
            $peerSyncState->last_sync_error = null;
        }

        $peerSyncState->save();

        return $peerSyncState;
    }
}
