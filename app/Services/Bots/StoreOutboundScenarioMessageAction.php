<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Data\Messages\PreparedMessageContentData;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Dialogs\SyncMessageDialogMetadataAction;
use Illuminate\Support\Facades\DB;

class StoreOutboundScenarioMessageAction
{
    public function __construct(
        private readonly SyncMessageDialogMetadataAction $syncMessageDialogMetadataAction,
    ) {}

    public function handle(
        Channel $channel,
        Message $inboundMessage,
        AutoReplyDeliveryResult $deliveryResult,
        string $systemCode,
        ?PreparedMessageContentData $content = null,
    ): Message {
        return DB::transaction(function () use ($channel, $inboundMessage, $deliveryResult, $systemCode, $content): Message {
            $inboundMessage->forceFill([
                'auto_reply_sent_at' => now(),
            ])->save();

            $outboundMessage = Message::query()->create([
                'contact_id' => $inboundMessage->contact_id,
                'contact_identity_id' => $inboundMessage->contact_identity_id,
                'channel_id' => $channel->id,
                'direction' => Message::DIRECTION_OUTBOUND,
                'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
                'reply_to_message_id' => $inboundMessage->id,
                'provider_event_key' => null,
                'external_chat_id' => $inboundMessage->external_chat_id,
                'external_message_id' => $deliveryResult->externalMessageId,
                'text' => $content?->plainText ?? $deliveryResult->text,
                'text_format' => $content?->textFormat ?? Message::TEXT_FORMAT_PLAIN_TEXT,
                'source_text' => $content?->sourceText,
                'raw_payload' => $deliveryResult->rawPayload,
                'received_at' => now(),
            ]);

            $outboundMessage = $this->syncMessageDialogMetadataAction->handle(
                $outboundMessage,
                $inboundMessage->contact()->firstOrFail(),
                $channel,
                $inboundMessage->contactIdentity,
                $inboundMessage->external_chat_id,
                Message::SENT_BY_TYPE_SYSTEM,
                null,
                $systemCode,
            );

            return $outboundMessage;
        });
    }
}
