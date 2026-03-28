<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Models\Message;

class StoreManualOutboundMessageAction
{
    public function handle(Message $routeSourceMessage, AutoReplyDeliveryResult $deliveryResult, ?Message $replyToMessage = null): Message
    {
        return Message::query()->create([
            'contact_id' => $routeSourceMessage->contact_id,
            'contact_identity_id' => $routeSourceMessage->contact_identity_id,
            'channel_id' => $routeSourceMessage->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'reply_to_message_id' => $replyToMessage?->id,
            'provider_event_key' => null,
            'external_chat_id' => $routeSourceMessage->external_chat_id,
            'external_message_id' => $deliveryResult->externalMessageId,
            'text' => $deliveryResult->text,
            'raw_payload' => $deliveryResult->rawPayload,
            'received_at' => now(),
        ]);
    }
}
