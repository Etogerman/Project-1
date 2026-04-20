<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesOperatorMessageData;
use App\Data\Bots\BotDialogTextSendResult;
use App\Data\Dialogs\DialogRouteStatusData;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Models\Dialog;
use App\Models\Message;
use Throwable;

class ProcessBitrix24OpenLinesWebhookAction
{
    private const BLOCKED_DIALOG_FEEDBACK_TEXT = 'Система: Сообщение не отправлено. Клиент заблокировал бота.';
    private const BLOCKED_DIALOG_PHASE_ENTITY_TYPE = 'openlines_blocked_attempt';
    private const BLOCKED_DIALOG_FEEDBACK_SENT_OPERATION = 'openlines_blocked_feedback_sent';
    private const BLOCKED_DIALOG_FEEDBACK_FAILED_OPERATION = 'openlines_blocked_feedback_failed';
    private const BLOCKED_DIALOG_ACK_SENT_OPERATION = 'openlines_blocked_feedback_ack_sent';
    private const BLOCKED_DIALOG_ACK_FAILED_OPERATION = 'openlines_blocked_feedback_ack_failed';

    public function __construct(
        private readonly NormalizeBitrix24OpenLinesEventAction $normalizeBitrix24OpenLinesEventAction,
        private readonly HandleBitrix24OpenLinesSessionClosedAction $handleBitrix24OpenLinesSessionClosedAction,
        private readonly ResolveDialogByBitrix24LiveChatKeyAction $resolveDialogByBitrix24LiveChatKeyAction,
        private readonly IsDialogReadyForBitrix24LiveBridgeAction $isDialogReadyForBitrix24LiveBridgeAction,
        private readonly DeliverBitrix24OpenLinesMessageToMessengerAction $deliverBitrix24OpenLinesMessageToMessengerAction,
        private readonly SendBitrix24OpenLinesBlockedDialogFeedbackAction $sendBitrix24OpenLinesBlockedDialogFeedbackAction,
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
            $preserveDialogLiveStatusOnFailure = false;

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

                if ($this->isBlockedDialogSkip($deliveryResult)) {
                    $preserveDialogLiveStatusOnFailure = true;
                    [$feedbackMessageId, $acknowledgementMessageId] = $this->handleBlockedDialogSkip(
                        $event,
                        $dialog,
                        $messageData,
                    );

                    $this->activateDialog($dialog, $event, $messageData->chatId);
                    $this->logBlockedDialogSkipped(
                        $event,
                        $dialog,
                        $messageData,
                        $deliveryResult,
                        $feedbackMessageId,
                        $acknowledgementMessageId,
                    );

                    continue;
                }

                if (! $deliveryResult->wasSent() || $deliveryResult->deliveryResult === null) {
                    throw new Bitrix24ApiException(
                        $deliveryResult->routeStatus->blockedReason
                            ?? 'Bitrix24 Open Lines dialog is not sendable for messenger delivery.'
                    );
                }

                $externalMessageId = trim((string) ($deliveryResult->deliveryResult->externalMessageId ?? ''));

                if ($externalMessageId === '') {
                    throw new Bitrix24ApiException('Messenger delivery did not return an external message id for Bitrix acknowledgement.');
                }

                $storedMessage = $this->storeBitrix24OpenLinesOutboundMessageAction->handle(
                    $dialog,
                    $messageData,
                    $deliveryResult->deliveryResult,
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
                if ($dialog instanceof Dialog && ! $preserveDialogLiveStatusOnFailure) {
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

    private function isBlockedDialogSkip(BotDialogTextSendResult $deliveryResult): bool
    {
        return ! $deliveryResult->wasSent()
            && $deliveryResult->routeStatus->code === DialogRouteStatusData::CODE_BLOCKED_BY_USER;
    }

    private function logBlockedDialogSkipped(
        Bitrix24WebhookEvent $event,
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        BotDialogTextSendResult $deliveryResult,
        string $feedbackMessageId,
        string $acknowledgementMessageId,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'openlines_message_skipped_blocked_dialog',
            status: Bitrix24SyncLog::STATUS_SKIPPED,
            requestPayload: [
                'webhook_event_id' => $event->id,
                'event_name' => $event->event_name,
                'dialog_id' => $dialog->id,
                'chat_id' => $messageData->chatId,
                'bitrix_message_id' => $messageData->bitrixMessageId,
            ],
            responsePayload: [
                'route_status_code' => $deliveryResult->routeStatus->code,
                'route_status_label' => $deliveryResult->routeStatus->label,
                'blocked_reason' => $deliveryResult->routeStatus->blockedReason,
                'feedback_message_text' => self::BLOCKED_DIALOG_FEEDBACK_TEXT,
                'feedback_message_id' => $feedbackMessageId,
                'acknowledgement_message_id' => $acknowledgementMessageId,
            ],
            connection: $event->connection,
            entityType: 'openlines_webhook_event',
            entityId: (string) $event->id,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function handleBlockedDialogSkip(
        Bitrix24WebhookEvent $event,
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
    ): array {
        $phaseFingerprint = $this->buildBlockedDialogPhaseFingerprint($messageData);
        $feedbackMessageId = $this->buildBlockedDialogFeedbackMessageId($messageData);

        if (! $this->hasBlockedDialogPhaseSucceeded(self::BLOCKED_DIALOG_FEEDBACK_SENT_OPERATION, $phaseFingerprint)) {
            try {
                $this->sendBitrix24OpenLinesBlockedDialogFeedbackAction->handle(
                    $dialog,
                    $messageData,
                    self::BLOCKED_DIALOG_FEEDBACK_TEXT,
                    $feedbackMessageId,
                );
            } catch (Throwable $throwable) {
                $this->logBlockedDialogPhaseFailure(
                    operation: self::BLOCKED_DIALOG_FEEDBACK_FAILED_OPERATION,
                    event: $event,
                    dialog: $dialog,
                    messageData: $messageData,
                    phaseFingerprint: $phaseFingerprint,
                    feedbackMessageId: $feedbackMessageId,
                    errorMessage: $throwable->getMessage(),
                );

                throw $throwable;
            }

            $this->logBlockedDialogPhaseSuccess(
                operation: self::BLOCKED_DIALOG_FEEDBACK_SENT_OPERATION,
                event: $event,
                dialog: $dialog,
                messageData: $messageData,
                phaseFingerprint: $phaseFingerprint,
                feedbackMessageId: $feedbackMessageId,
            );
        }

        if (! $this->hasBlockedDialogPhaseSucceeded(self::BLOCKED_DIALOG_ACK_SENT_OPERATION, $phaseFingerprint)) {
            try {
                $this->acknowledgeBitrix24OpenLinesDeliveryAction->handle(
                    $dialog,
                    $messageData,
                    $feedbackMessageId,
                );
            } catch (Throwable $throwable) {
                $this->logBlockedDialogPhaseFailure(
                    operation: self::BLOCKED_DIALOG_ACK_FAILED_OPERATION,
                    event: $event,
                    dialog: $dialog,
                    messageData: $messageData,
                    phaseFingerprint: $phaseFingerprint,
                    feedbackMessageId: $feedbackMessageId,
                    errorMessage: $throwable->getMessage(),
                );

                throw $throwable;
            }

            $this->logBlockedDialogPhaseSuccess(
                operation: self::BLOCKED_DIALOG_ACK_SENT_OPERATION,
                event: $event,
                dialog: $dialog,
                messageData: $messageData,
                phaseFingerprint: $phaseFingerprint,
                feedbackMessageId: $feedbackMessageId,
            );
        }

        return [$feedbackMessageId, $feedbackMessageId];
    }

    private function buildBlockedDialogFeedbackMessageId(Bitrix24OpenLinesOperatorMessageData $messageData): string
    {
        return 'abrikosoff-openlines-blocked:'.$messageData->bitrixMessageId;
    }

    private function buildBlockedDialogPhaseFingerprint(Bitrix24OpenLinesOperatorMessageData $messageData): string
    {
        return hash('sha256', $messageData->chatId.'|'.$messageData->bitrixMessageId);
    }

    private function hasBlockedDialogPhaseSucceeded(string $operation, string $phaseFingerprint): bool
    {
        return Bitrix24SyncLog::query()
            ->where('operation', $operation)
            ->where('status', Bitrix24SyncLog::STATUS_SUCCESS)
            ->where('fingerprint', $phaseFingerprint)
            ->exists();
    }

    private function logBlockedDialogPhaseSuccess(
        string $operation,
        Bitrix24WebhookEvent $event,
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        string $phaseFingerprint,
        string $feedbackMessageId,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: $operation,
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'webhook_event_id' => $event->id,
                'event_name' => $event->event_name,
                'dialog_id' => $dialog->id,
                'chat_id' => $messageData->chatId,
                'bitrix_message_id' => $messageData->bitrixMessageId,
            ],
            responsePayload: [
                'feedback_message_id' => $feedbackMessageId,
                'feedback_message_text' => self::BLOCKED_DIALOG_FEEDBACK_TEXT,
            ],
            connection: $event->connection,
            entityType: self::BLOCKED_DIALOG_PHASE_ENTITY_TYPE,
            entityId: $feedbackMessageId,
            fingerprint: $phaseFingerprint,
        );
    }

    private function logBlockedDialogPhaseFailure(
        string $operation,
        Bitrix24WebhookEvent $event,
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        string $phaseFingerprint,
        string $feedbackMessageId,
        string $errorMessage,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: $operation,
            status: Bitrix24SyncLog::STATUS_FAILED,
            requestPayload: [
                'webhook_event_id' => $event->id,
                'event_name' => $event->event_name,
                'dialog_id' => $dialog->id,
                'chat_id' => $messageData->chatId,
                'bitrix_message_id' => $messageData->bitrixMessageId,
            ],
            responsePayload: [
                'feedback_message_id' => $feedbackMessageId,
                'feedback_message_text' => self::BLOCKED_DIALOG_FEEDBACK_TEXT,
            ],
            connection: $event->connection,
            errorMessage: $errorMessage,
            entityType: self::BLOCKED_DIALOG_PHASE_ENTITY_TYPE,
            entityId: $feedbackMessageId,
            fingerprint: $phaseFingerprint,
        );
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
