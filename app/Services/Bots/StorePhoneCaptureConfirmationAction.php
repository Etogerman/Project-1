<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bitrix24\QueueBitrix24LiveMessageExportAction;
use App\Services\Dialogs\SyncMessageDialogMetadataAction;
use Illuminate\Support\Facades\DB;

class StorePhoneCaptureConfirmationAction
{
    public function __construct(
        private readonly SyncMessageDialogMetadataAction $syncMessageDialogMetadataAction,
        private readonly QueueBitrix24LiveMessageExportAction $queueBitrix24LiveMessageExportAction,
    ) {}

    public function handle(Dialog $routeDialog, Message $inboundMessage, AutoReplyDeliveryResult $deliveryResult): Message
    {
        return DB::transaction(function () use ($routeDialog, $inboundMessage, $deliveryResult): Message {
            $inboundMessage->forceFill([
                'auto_reply_sent_at' => now(),
            ])->save();

            $routeDialog->loadMissing(['contact', 'channel', 'currentContactIdentity']);
            $storedExternalChatId = $this->resolveStoredExternalChatId($routeDialog);

            $outboundMessage = Message::query()->create([
                'contact_id' => $routeDialog->contact_id,
                'contact_identity_id' => $routeDialog->current_contact_identity_id,
                'channel_id' => $routeDialog->channel_id,
                'direction' => Message::DIRECTION_OUTBOUND,
                'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
                'reply_to_message_id' => $inboundMessage->id,
                'provider_event_key' => null,
                'external_chat_id' => $storedExternalChatId,
                'external_message_id' => $deliveryResult->externalMessageId,
                'text' => $deliveryResult->text,
                'raw_payload' => $deliveryResult->rawPayload,
                'received_at' => now(),
            ]);

            $outboundMessage = $this->syncMessageDialogMetadataAction->handle(
                $outboundMessage,
                $routeDialog->contact ?? $routeDialog->contact()->firstOrFail(),
                $routeDialog->channel ?? $routeDialog->channel()->firstOrFail(),
                $routeDialog->currentContactIdentity,
                $storedExternalChatId,
                Message::SENT_BY_TYPE_COLLECTOR,
                null,
                Message::SENT_BY_SYSTEM_CODE_PHONE_CAPTURE_CONFIRMATION,
            );

            $this->queueBitrix24LiveMessageExportAction->handle($outboundMessage);

            return $outboundMessage;
        });
    }

    private function resolveStoredExternalChatId(Dialog $routeDialog): string
    {
        return (string) ($routeDialog->external_chat_id ?? '');
    }
}
