<?php

namespace App\Services\Bitrix24;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Data\Bitrix24\Bitrix24OpenLinesOperatorMessageData;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Dialogs\MessageChronology;
use App\Services\Dialogs\SyncMessageDialogMetadataAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class StoreBitrix24OpenLinesOutboundMessageAction
{
    public function __construct(
        private readonly SyncMessageDialogMetadataAction $syncMessageDialogMetadataAction,
        private readonly MessageChronology $messageChronology,
    ) {}

    public function handle(
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $bitrixMessage,
        AutoReplyDeliveryResult $deliveryResult,
    ): Message {
        $providerEventKey = 'bitrix24-openlines:'.$bitrixMessage->bitrixMessageId;

        $existingMessage = $this->findExistingOutboundMessage($dialog, $providerEventKey);

        if ($existingMessage instanceof Message) {
            return $existingMessage;
        }

        return DB::transaction(function () use ($dialog, $bitrixMessage, $deliveryResult, $providerEventKey): Message {
            $replyToMessage = $this->resolveReplyToMessage($dialog);

            try {
                $message = Message::query()->create([
                    'contact_id' => $dialog->contact_id,
                    'contact_identity_id' => $dialog->current_contact_identity_id,
                    'channel_id' => $dialog->channel_id,
                    'direction' => Message::DIRECTION_OUTBOUND,
                    'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
                    'reply_to_message_id' => $replyToMessage?->id,
                    'provider_event_key' => $providerEventKey,
                    'external_chat_id' => $dialog->external_chat_id,
                    'external_message_id' => $deliveryResult->externalMessageId,
                    'text' => $bitrixMessage->text,
                    'raw_payload' => $bitrixMessage->rawPayload,
                    'received_at' => now(),
                ]);
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) !== '23505') {
                    throw $exception;
                }

                return $this->findExistingOutboundMessage($dialog, $providerEventKey) ?? throw $exception;
            }

            return $this->syncMessageDialogMetadataAction->handle(
                $message,
                $dialog->contact ?? $dialog->contact()->firstOrFail(),
                $dialog->channel ?? $dialog->channel()->firstOrFail(),
                $dialog->currentContactIdentity,
                $dialog->external_chat_id,
                Message::SENT_BY_TYPE_OPERATOR,
                null,
                Message::SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES,
            );
        });
    }

    private function findExistingOutboundMessage(Dialog $dialog, string $providerEventKey): ?Message
    {
        return Message::query()
            ->where('channel_id', $dialog->channel_id)
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('provider_event_key', $providerEventKey)
            ->first();
    }

    private function resolveReplyToMessage(Dialog $dialog): ?Message
    {
        return Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->tap(fn (\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder => $this->messageChronology->applyLatestOrder($query))
            ->first();
    }
}
