<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Bitrix24\QueueBitrix24LiveMessageExportAction;
use App\Services\Dialogs\SyncMessageDialogMetadataAction;
use Illuminate\Support\Facades\DB;

class StoreOutboundAutoReplyMessageAction
{
    public function __construct(
        private readonly SyncMessageDialogMetadataAction $syncMessageDialogMetadataAction,
        private readonly QueueBitrix24LiveMessageExportAction $queueBitrix24LiveMessageExportAction,
        private readonly ApplyAutoReplyRuleTagEffectsAction $applyAutoReplyRuleTagEffectsAction,
    ) {}

    public function handle(
        Channel $channel,
        Message $inboundMessage,
        AutoReplyDeliveryResult $deliveryResult,
        ?AutoReplyRule $matchedRule = null,
    ): Message
    {
        return DB::transaction(function () use ($channel, $inboundMessage, $deliveryResult, $matchedRule): Message {
            $inboundMessage->forceFill([
                'auto_reply_sent_at' => now(),
            ])->save();

            $contact = $inboundMessage->contact()->firstOrFail();

            $outboundMessage = Message::query()->create([
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

            $outboundMessage = $this->syncMessageDialogMetadataAction->handle(
                $outboundMessage,
                $contact,
                $channel,
                $inboundMessage->contactIdentity,
                $inboundMessage->external_chat_id,
                Message::SENT_BY_TYPE_AUTO_REPLY,
                null,
                Message::SENT_BY_SYSTEM_CODE_AUTO_REPLY_RULE,
            );

            if ($matchedRule instanceof AutoReplyRule) {
                $this->applyAutoReplyRuleTagEffectsAction->handle($contact, $matchedRule);
            }

            $this->queueBitrix24LiveMessageExportAction->handle($outboundMessage);

            return $outboundMessage;
        });
    }
}
