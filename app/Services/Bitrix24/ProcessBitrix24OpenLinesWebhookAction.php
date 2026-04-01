<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Models\Dialog;
use App\Models\Message;
use Throwable;

class ProcessBitrix24OpenLinesWebhookAction
{
    public function __construct(
        private readonly NormalizeBitrix24OpenLinesEventAction $normalizeBitrix24OpenLinesEventAction,
        private readonly HandleBitrix24OpenLinesSessionClosedAction $handleBitrix24OpenLinesSessionClosedAction,
        private readonly ResolveDialogByBitrix24LiveChatKeyAction $resolveDialogByBitrix24LiveChatKeyAction,
        private readonly IsDialogReadyForBitrix24LiveBridgeAction $isDialogReadyForBitrix24LiveBridgeAction,
        private readonly DeliverBitrix24OpenLinesMessageToMessengerAction $deliverBitrix24OpenLinesMessageToMessengerAction,
        private readonly StoreBitrix24OpenLinesOutboundMessageAction $storeBitrix24OpenLinesOutboundMessageAction,
        private readonly AcknowledgeBitrix24OpenLinesDeliveryAction $acknowledgeBitrix24OpenLinesDeliveryAction,
        private readonly LogBitrix24ApiCallAction $logBitrix24ApiCallAction,
    ) {}

    public function handle(Bitrix24WebhookEvent|int $event): Bitrix24WebhookEvent
    {
        $event = $event instanceof Bitrix24WebhookEvent
            ? $event
            : Bitrix24WebhookEvent::query()->findOrFail($event);

        if (! config('bitrix24.features.openlines_enabled', false)) {
            $this->markEventIgnored($event);
            $this->logBitrix24ApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'openlines_disabled_callback_ignored',
                status: Bitrix24SyncLog::STATUS_SKIPPED,
                requestPayload: [
                    'webhook_event_id' => $event->id,
                    'event_name' => $event->event_name,
                ],
                connection: $event->connection,
                entityType: 'openlines_webhook_event',
                entityId: (string) $event->id,
            );

            return $event->fresh();
        }

        $normalized = $this->normalizeBitrix24OpenLinesEventAction->handle($event);

        if ($normalized['type'] === NormalizeBitrix24OpenLinesEventAction::TYPE_UNSUPPORTED) {
            return $this->ignoreKnownEvent($event, 'openlines_event_ignored');
        }

        if ($normalized['type'] === NormalizeBitrix24OpenLinesEventAction::TYPE_MESSAGE_UPDATED) {
            return $this->ignoreKnownEvent($event, 'openlines_message_update_ignored');
        }

        if ($normalized['type'] === NormalizeBitrix24OpenLinesEventAction::TYPE_MESSAGE_DELETED) {
            return $this->ignoreKnownEvent($event, 'openlines_message_delete_ignored');
        }

        if ($normalized['type'] === NormalizeBitrix24OpenLinesEventAction::TYPE_SESSION_CLOSED) {
            return $this->handleSessionClosed($event, $normalized['chat_ids'] ?? []);
        }

        foreach ($normalized['messages'] as $messageData) {
            $dialog = null;

            try {
                $dialog = $this->resolveDialogByBitrix24LiveChatKeyAction->handle($messageData->chatId);

                if (! $this->isDialogReadyForBitrix24LiveBridgeAction->handle($dialog)) {
                    throw new Bitrix24ApiException('Bitrix24 Open Lines dialog is not ready for live bridge processing.');
                }

                $providerEventKey = 'bitrix24-openlines:'.$messageData->bitrixMessageId;
                $existingMessage = Message::query()
                    ->where('channel_id', $dialog->channel_id)
                    ->where('direction', Message::DIRECTION_OUTBOUND)
                    ->where('provider_event_key', $providerEventKey)
                    ->first();

                if ($existingMessage instanceof Message) {
                    $externalMessageId = trim((string) $existingMessage->external_message_id);

                    if ($externalMessageId === '') {
                        throw new Bitrix24ApiException('Bitrix24 Open Lines duplicate message does not have a stored external delivery id.');
                    }

                    $this->acknowledgeBitrix24OpenLinesDeliveryAction->handle(
                        $dialog,
                        $messageData,
                        $externalMessageId,
                    );

                    $this->activateDialog($dialog, $event, $messageData->chatId);

                    continue;
                }

                $deliveryResult = $this->deliverBitrix24OpenLinesMessageToMessengerAction->handle(
                    $dialog,
                    $messageData,
                );

                $externalMessageId = trim((string) ($deliveryResult->externalMessageId ?? ''));

                if ($externalMessageId === '') {
                    throw new Bitrix24ApiException('Messenger delivery did not return an external message id for Bitrix acknowledgement.');
                }

                $storedMessage = $this->storeBitrix24OpenLinesOutboundMessageAction->handle(
                    $dialog,
                    $messageData,
                    $deliveryResult,
                );

                $this->acknowledgeBitrix24OpenLinesDeliveryAction->handle(
                    $dialog,
                    $messageData,
                    $externalMessageId,
                );

                $this->activateDialog($dialog, $event, $messageData->chatId);

                $this->logBitrix24ApiCallAction->handle(
                    direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                    operation: 'openlines_message_imported',
                    status: Bitrix24SyncLog::STATUS_SUCCESS,
                    requestPayload: [
                        'webhook_event_id' => $event->id,
                        'event_name' => $event->event_name,
                        'dialog_id' => $dialog->id,
                        'chat_id' => $messageData->chatId,
                        'bitrix_message_id' => $messageData->bitrixMessageId,
                    ],
                    responsePayload: [
                        'local_message_id' => $storedMessage->id,
                        'external_message_id' => $externalMessageId,
                    ],
                    connection: $event->connection,
                    entityType: 'openlines_webhook_event',
                    entityId: (string) $event->id,
                );
            } catch (Throwable $throwable) {
                if ($dialog instanceof Dialog) {
                    $dialog->forceFill([
                        'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_FAILED,
                    ])->save();
                }

                throw $throwable;
            }
        }

        $this->markEventProcessed($event);

        return $event->fresh();
    }

    /**
     * @param  list<string>  $chatIds
     */
    private function handleSessionClosed(Bitrix24WebhookEvent $event, array $chatIds): Bitrix24WebhookEvent
    {
        $handledDialog = null;

        foreach ($chatIds as $chatId) {
            $handledDialog = $this->handleBitrix24OpenLinesSessionClosedAction->handle($chatId);

            if ($handledDialog instanceof Dialog) {
                $this->markEventProcessed($event);
                $this->logBitrix24ApiCallAction->handle(
                    direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                    operation: 'openlines_session_closed',
                    status: Bitrix24SyncLog::STATUS_SUCCESS,
                    requestPayload: [
                        'webhook_event_id' => $event->id,
                        'event_name' => $event->event_name,
                        'chat_id' => $chatId,
                    ],
                    connection: $event->connection,
                    entityType: 'dialog',
                    entityId: (string) $handledDialog->id,
                );

                return $event->fresh();
            }
        }

        return $this->ignoreKnownEvent($event, 'openlines_session_closed_ignored');
    }

    private function ignoreKnownEvent(Bitrix24WebhookEvent $event, string $operation): Bitrix24WebhookEvent
    {
        $this->markEventIgnored($event);

        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: $operation,
            status: Bitrix24SyncLog::STATUS_SKIPPED,
            requestPayload: [
                'webhook_event_id' => $event->id,
                'event_name' => $event->event_name,
            ],
            connection: $event->connection,
            entityType: 'openlines_webhook_event',
            entityId: (string) $event->id,
        );

        return $event->fresh();
    }

    private function markEventProcessed(Bitrix24WebhookEvent $event): void
    {
        $event->forceFill([
            'processing_status' => Bitrix24WebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
            'attempts' => $event->attempts + 1,
        ])->save();
    }

    private function markEventIgnored(Bitrix24WebhookEvent $event): void
    {
        $event->forceFill([
            'processing_status' => Bitrix24WebhookEvent::STATUS_IGNORED,
            'processed_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
            'attempts' => $event->attempts + 1,
        ])->save();
    }

    private function activateDialog(Dialog $dialog, Bitrix24WebhookEvent $event, string $chatId): void
    {
        $previousLiveStatus = $dialog->bitrix24_live_status;

        $dialog->forceFill([
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
            'bitrix24_live_last_imported_at' => now(),
        ])->save();

        if (in_array($previousLiveStatus, [
            Dialog::BITRIX24_LIVE_STATUS_CLOSED,
            Dialog::BITRIX24_LIVE_STATUS_FAILED,
        ], true)) {
            $this->logBitrix24ApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'openlines_dialog_reopened',
                status: Bitrix24SyncLog::STATUS_SUCCESS,
                requestPayload: [
                    'webhook_event_id' => $event->id,
                    'event_name' => $event->event_name,
                    'dialog_id' => $dialog->id,
                    'chat_id' => $chatId,
                    'previous_live_status' => $previousLiveStatus,
                ],
                connection: $event->connection,
                entityType: 'dialog',
                entityId: (string) $dialog->id,
            );
        }
    }
}
