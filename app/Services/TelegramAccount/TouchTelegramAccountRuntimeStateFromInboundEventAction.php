<?php

namespace App\Services\TelegramAccount;

use App\Data\TelegramAccount\NormalizedInboundMessageEvent;
use App\Models\Channel;
use App\Models\ChannelRuntimeState;

class TouchTelegramAccountRuntimeStateFromInboundEventAction
{
    public function handle(Channel $channel, NormalizedInboundMessageEvent $event): ChannelRuntimeState
    {
        $runtimeState = ChannelRuntimeState::query()->firstOrNew([
            'channel_id' => $channel->id,
        ]);

        $now = now();
        $previousSyncStatus = $runtimeState->sync_status;

        $runtimeState->forceFill([
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'last_gateway_heartbeat_at' => $now,
        ]);

        if ($event->historySource === NormalizedInboundMessageEvent::HISTORY_SOURCE_BACKFILL) {
            if ($runtimeState->sync_status !== ChannelRuntimeState::SYNC_STATUS_BACKFILL_IN_PROGRESS) {
                $runtimeState->last_sync_started_at = $now;
            }

            $runtimeState->sync_status = ChannelRuntimeState::SYNC_STATUS_BACKFILL_IN_PROGRESS;
        } else {
            $runtimeState->sync_status = ChannelRuntimeState::SYNC_STATUS_LIVE;

            if ($previousSyncStatus === ChannelRuntimeState::SYNC_STATUS_BACKFILL_IN_PROGRESS) {
                $runtimeState->last_sync_completed_at = $now;
            }
        }

        $runtimeState->save();

        return $runtimeState;
    }
}
