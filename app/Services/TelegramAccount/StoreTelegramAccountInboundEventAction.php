<?php

namespace App\Services\TelegramAccount;

use App\Data\Bots\StoredInboundMessageResult;
use App\Data\TelegramAccount\NormalizedInboundMessageEvent;
use App\Jobs\ProcessAutoReplyJob;
use App\Models\Channel;
use App\Models\Message;
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

            $result = $this->storeInboundMessageAction->handle($channel, $event->toIncomingBotMessage());

            $this->queueAutoReplyIfNeeded($channel, $event, $result);

            return $result;
        });
    }

    private function queueAutoReplyIfNeeded(
        Channel $channel,
        NormalizedInboundMessageEvent $event,
        ?StoredInboundMessageResult $result,
    ): void {
        if ($event->historySource !== NormalizedInboundMessageEvent::HISTORY_SOURCE_LIVE) {
            return;
        }

        if (! $result instanceof StoredInboundMessageResult) {
            return;
        }

        $message = $result->message;

        if (! $message->wasRecentlyCreated) {
            return;
        }

        if ($message->direction !== Message::DIRECTION_INBOUND) {
            return;
        }

        if ($message->message_kind !== Message::KIND_INBOUND_USER) {
            return;
        }

        ProcessAutoReplyJob::dispatch($message->id)->afterCommit();

        $this->channelActivityLogger->info(
            $channel,
            'bot.reply_queued',
            'Автоответ поставлен в очередь.',
            [
                'platform' => $channel->platform,
                'connection_type' => $channel->connection_type,
                'message_id' => $message->id,
                'provider_event_key' => $message->provider_event_key,
                'external_message_id' => $message->external_message_id,
                'history_source' => $event->historySource,
            ],
        );
    }
}
