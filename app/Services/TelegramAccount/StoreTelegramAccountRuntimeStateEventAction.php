<?php

namespace App\Services\TelegramAccount;

use App\Data\TelegramAccount\NormalizedRuntimeStateEvent;
use App\Models\Channel;
use App\Models\ChannelRuntimeState;

class StoreTelegramAccountRuntimeStateEventAction
{
    public function handle(Channel $channel, NormalizedRuntimeStateEvent $event): ChannelRuntimeState
    {
        $runtimeState = ChannelRuntimeState::query()->firstOrNew([
            'channel_id' => $channel->id,
        ]);

        $runtimeState->forceFill([
            'auth_status' => $event->authStatus,
            'authorization_state' => $event->authorizationState,
            'sync_status' => $event->syncStatus,
        ]);

        if ($event->hasLastGatewayHeartbeatAt) {
            $runtimeState->last_gateway_heartbeat_at = $event->lastGatewayHeartbeatAt;
        }

        if ($event->hasLastSyncStartedAt) {
            $runtimeState->last_sync_started_at = $event->lastSyncStartedAt;
        }

        if ($event->hasLastSyncCompletedAt) {
            $runtimeState->last_sync_completed_at = $event->lastSyncCompletedAt;
        }

        if ($event->hasLastErrorAt) {
            $runtimeState->last_error_at = $event->lastErrorAt;
        }

        if ($event->hasLastErrorMessage) {
            $runtimeState->last_error_message = $event->lastErrorMessage;
        }

        if ($event->hasRuntimePayload) {
            $runtimeState->runtime_payload = $event->runtimePayload;
        }

        $runtimeState->save();

        return $runtimeState;
    }
}
