<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class StoreDataCollectionOutboundMessageAction
{
    public function handle(Message $inboundMessage, AutoReplyDeliveryResult $deliveryResult, string $messageKind): Message
    {
        return DB::transaction(function () use ($inboundMessage, $deliveryResult, $messageKind): Message {
            $inboundMessage->forceFill([
                'auto_reply_sent_at' => now(),
            ])->save();

            return Message::query()->create([
                'contact_id' => $inboundMessage->contact_id,
                'contact_identity_id' => $inboundMessage->contact_identity_id,
                'channel_id' => $inboundMessage->channel_id,
                'direction' => Message::DIRECTION_OUTBOUND,
                'message_kind' => $messageKind,
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
