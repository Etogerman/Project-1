<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bitrix24\QueueBitrix24LiveMessageExportAction;
use App\Services\Dialogs\SyncMessageDialogMetadataAction;
use Illuminate\Support\Facades\DB;

class StoreDataCollectionOutboundMessageAction
{
    public function __construct(
        private readonly SyncMessageDialogMetadataAction $syncMessageDialogMetadataAction,
        private readonly QueueBitrix24LiveMessageExportAction $queueBitrix24LiveMessageExportAction,
    ) {}

    public function handle(
        Message $inboundMessage,
        AutoReplyDeliveryResult $deliveryResult,
        string $messageKind,
        ?Dialog $routeDialog = null,
        ?string $messageParameter = null,
    ): Message
    {
        return DB::transaction(function () use ($inboundMessage, $deliveryResult, $messageKind, $routeDialog, $messageParameter): Message {
            $inboundMessage->forceFill([
                'auto_reply_sent_at' => now(),
            ])->save();

            $routeDialog = $this->resolveRouteDialog($inboundMessage, $routeDialog);
            $routeDialog?->loadMissing(['contact', 'channel', 'currentContactIdentity']);
            $storedExternalChatId = $this->resolveStoredExternalChatId($routeDialog, $inboundMessage);
            $contact = $routeDialog?->contact ?? $inboundMessage->contact()->firstOrFail();
            $channel = $routeDialog?->channel ?? $inboundMessage->channel()->firstOrFail();
            $collectorField = $this->resolveCollectorField($messageKind, $messageParameter, $contact);

            $outboundMessage = Message::query()->create([
                'contact_id' => $contact->id,
                'contact_identity_id' => $routeDialog?->current_contact_identity_id ?? $inboundMessage->contact_identity_id,
                'channel_id' => $channel->id,
                'direction' => Message::DIRECTION_OUTBOUND,
                'message_kind' => $messageKind,
                'reply_to_message_id' => $inboundMessage->id,
                'provider_event_key' => null,
                'external_chat_id' => $storedExternalChatId,
                'external_message_id' => $deliveryResult->externalMessageId,
                'text' => $deliveryResult->text,
                'message_parameter' => $collectorField,
                'raw_payload' => $deliveryResult->rawPayload,
                'received_at' => now(),
            ]);

            $outboundMessage = $this->syncMessageDialogMetadataAction->handle(
                $outboundMessage,
                $contact,
                $channel,
                $routeDialog?->currentContactIdentity ?? $inboundMessage->contactIdentity,
                $storedExternalChatId,
                Message::SENT_BY_TYPE_COLLECTOR,
                null,
                $this->resolveCollectorSystemCode($messageKind),
            );

            $this->syncCollectorPromptMarker($contact, $messageKind, $collectorField);
            $this->queueBitrix24LiveMessageExportAction->handle($outboundMessage);

            return $outboundMessage;
        });
    }

    private function resolveRouteDialog(Message $inboundMessage, ?Dialog $routeDialog): ?Dialog
    {
        if ($routeDialog instanceof Dialog) {
            return $routeDialog;
        }

        $inboundMessage->loadMissing([
            'dialog.contact',
            'dialog.channel',
            'dialog.currentContactIdentity',
        ]);

        return $inboundMessage->dialog;
    }

    private function resolveStoredExternalChatId(?Dialog $routeDialog, Message $inboundMessage): string
    {
        if ($routeDialog instanceof Dialog) {
            return (string) ($routeDialog->external_chat_id ?? '');
        }

        return (string) $inboundMessage->external_chat_id;
    }

    private function resolveCollectorSystemCode(string $messageKind): string
    {
        return match ($messageKind) {
            Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION => Message::SENT_BY_SYSTEM_CODE_PHONE_CAPTURE_CONFIRMATION,
            Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION => Message::SENT_BY_SYSTEM_CODE_DATA_COLLECTION_COMPLETION,
            default => Message::SENT_BY_SYSTEM_CODE_DATA_COLLECTION_QUESTION,
        };
    }

    private function resolveCollectorField(string $messageKind, ?string $messageParameter, \App\Models\Contact $contact): ?string
    {
        if ($messageKind !== Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION) {
            return $messageParameter;
        }

        return $messageParameter ?? $contact->data_collection_current_field;
    }

    private function syncCollectorPromptMarker(\App\Models\Contact $contact, string $messageKind, ?string $collectorField): void
    {
        if ($messageKind === Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION) {
            $contact->forceFill([
                'data_collection_last_prompted_field' => null,
                'data_collection_current_field_started_at' => null,
            ])->save();

            return;
        }

        if ($messageKind !== Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION || ! filled($collectorField)) {
            return;
        }

        $payload = [
            'data_collection_last_prompted_field' => $collectorField,
        ];

        if (
            $contact->data_collection_current_field === $collectorField
            && $contact->data_collection_current_field_started_at === null
        ) {
            $payload['data_collection_current_field_started_at'] = now();
        }

        $contact->forceFill($payload)->save();
    }
}
