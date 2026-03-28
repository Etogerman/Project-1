<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class StoreOutboundAutoReplyMessageAction
{
    public function handle(Channel $channel, Message $inboundMessage, AutoReplyDeliveryResult $deliveryResult): Message
    {
        return DB::transaction(function () use ($channel, $inboundMessage, $deliveryResult): Message {
            $inboundMessage->forceFill([
                'auto_reply_sent_at' => now(),
            ])->save();

            return Message::query()->create([
                'contact_id' => $inboundMessage->contact_id,
                'contact_identity_id' => $inboundMessage->contact_identity_id,
                'channel_id' => $channel->id,
                'direction' => Message::DIRECTION_OUTBOUND,
                'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
                'reply_to_message_id' => $inboundMessage->id,
                'provider_event_key' => null,
                'external_chat_id' => $inboundMessage->external_chat_id,
                'external_message_id' => $deliveryResult->externalMessageId,
                'text' => $deliveryResult->text,
                'raw_payload' => $deliveryResult->rawPayload,
                'received_at' => now(),
            ]);
        });
    }
}
