<?php

namespace App\Services\TelegramAccount;

use App\Data\Bots\StoredInboundMessageResult;
use App\Data\TelegramAccount\NormalizedInboundMessageEvent;
use App\Models\Channel;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Bots\StoreInboundMessageAction;
use Illuminate\Support\Facades\DB;

class StoreTelegramAccountInboundEventAction
{
    public function __construct(
        private readonly TouchTelegramAccountRuntimeStateFromInboundEventAction $touchTelegramAccountRuntimeStateFromInboundEventAction,
        private readonly UpsertChannelPeerSyncStateFromInboundEventAction $upsertChannelPeerSyncStateFromInboundEventAction,
        private readonly StoreInboundMessageAction $storeInboundMessageAction,
        private readonly ChannelActivityLogger $channelActivityLogger,
    ) {}

    public function handle(Channel $channel, NormalizedInboundMessageEvent $event): ?StoredInboundMessageResult
    {
        return DB::transaction(function () use ($channel, $event): ?StoredInboundMessageResult {
            $this->touchTelegramAccountRuntimeStateFromInboundEventAction->handle($channel, $event);

            if (! $event->isPrivatePeer()) {
                $this->channelActivityLogger->info(
                    $channel,
                    'telegram_account_gateway.non_private_peer_skipped',
                    'Gateway event пропущен: peer вне private 1:1 scope.',
                    [
                        'gateway_event_id' => $event->gatewayEventId,
                        'peer_type' => $event->peerType,
                        'peer_key' => $event->peerKey,
                        'message_key' => $event->messageKey,
                        'history_source' => $event->historySource,
                    ],
                );

                return null;
            }

            $this->upsertChannelPeerSyncStateFromInboundEventAction->handle($channel, $event);

            if ($event->isArchivedPrivatePeer()) {
                $this->channelActivityLogger->info(
                    $channel,
                    'telegram_account_gateway.archived_private_peer_skipped',
                    'Gateway event пропущен: archived private peer вне текущего import visibility contract.',
                    [
                        'gateway_event_id' => $event->gatewayEventId,
                        'peer_key' => $event->peerKey,
                        'message_key' => $event->messageKey,
                        'history_source' => $event->historySource,
                        'is_archived' => true,
                    ],
                );

                return null;
            }

            return $this->storeInboundMessageAction->handle($channel, $event->toIncomingBotMessage());
        });
    }
}
