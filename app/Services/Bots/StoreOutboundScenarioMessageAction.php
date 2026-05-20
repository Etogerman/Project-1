<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Data\Messages\PreparedMessageContentData;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bitrix24\QueueBitrix24LiveMessageExportAction;
use App\Services\Dialogs\SyncMessageDialogMetadataAction;
use Illuminate\Support\Facades\DB;

class StoreOutboundScenarioMessageAction
{
    public function __construct(
        private readonly SyncMessageDialogMetadataAction $syncMessageDialogMetadataAction,
        private readonly QueueBitrix24LiveMessageExportAction $queueBitrix24LiveMessageExportAction,
    ) {}

    public function handle(
        Channel $channel,
        Message $inboundMessage,
        AutoReplyDeliveryResult $deliveryResult,
        string $systemCode,
        ?Dialog $routeDialog = null,
        ?PreparedMessageContentData $content = null,
        array $rawPayloadMetadata = [],
    ): Message {
        return DB::transaction(function () use ($channel, $inboundMessage, $deliveryResult, $systemCode, $routeDialog, $content, $rawPayloadMetadata): Message {
            $inboundMessage->forceFill([
                'auto_reply_sent_at' => now(),
            ])->save();

            $routeDialog?->loadMissing(['contact', 'currentContactIdentity']);

            $contact = $routeDialog?->contact ?? $inboundMessage->contact()->firstOrFail();
            $routeContactIdentity = $routeDialog?->currentContactIdentity ?? $inboundMessage->contactIdentity;
            $routeExternalChatId = $routeDialog?->external_chat_id ?? $inboundMessage->external_chat_id;

            $outboundMessage = Message::query()->create([
                'contact_id' => $contact->id,
                'contact_identity_id' => $routeContactIdentity?->id,
                'channel_id' => $channel->id,
                'direction' => Message::DIRECTION_OUTBOUND,
                'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
                'reply_to_message_id' => $inboundMessage->id,
                'provider_event_key' => null,
                'external_chat_id' => $routeExternalChatId,
                'external_message_id' => $deliveryResult->externalMessageId,
                'text' => $content?->plainText ?? $deliveryResult->text,
                'text_format' => $content?->textFormat ?? Message::TEXT_FORMAT_PLAIN_TEXT,
                'source_text' => $content?->sourceText,
                'raw_payload' => $this->rawPayload($deliveryResult->rawPayload, $rawPayloadMetadata),
                'received_at' => now(),
            ]);

            $outboundMessage = $this->syncMessageDialogMetadataAction->handle(
                $outboundMessage,
                $contact,
                $channel,
                $routeContactIdentity,
                $routeExternalChatId,
                Message::SENT_BY_TYPE_SYSTEM,
                null,
                $systemCode,
            );

            $this->queueBitrix24LiveMessageExportAction->handle($outboundMessage);

            return $outboundMessage;
        });
    }

    /**
     * @param  array<string, mixed>  $deliveryPayload
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function rawPayload(array $deliveryPayload, array $metadata): array
    {
        if ($metadata === []) {
            return $deliveryPayload;
        }

        return array_replace($deliveryPayload, $metadata);
    }
}
