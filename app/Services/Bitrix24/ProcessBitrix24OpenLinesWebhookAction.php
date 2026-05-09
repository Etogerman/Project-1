<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24CurrentOpenLineChatData;
use App\Data\Bitrix24\Bitrix24OpenLinesOperatorMessageData;
use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Data\Bots\AutoReplyDeliveryResult;
use App\Data\Bots\BotDialogTextSendResult;
use App\Data\Dialogs\DialogRouteStatusData;
use App\Jobs\ProcessBitrix24WebhookEventJob;
use App\Models\Bitrix24MessageExport;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Dialogs\MessageChronology;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProcessBitrix24OpenLinesWebhookAction
{
    private const BLOCKED_DIALOG_FEEDBACK_TEXT = 'Система: Сообщение не отправлено. Клиент заблокировал бота.';

    private const BLOCKED_DIALOG_PHASE_ENTITY_TYPE = 'openlines_blocked_attempt';

    private const DELIVERY_PHASE_ENTITY_TYPE = 'openlines_delivery_phase';

    private const BLOCKED_DIALOG_FEEDBACK_SENT_OPERATION = 'openlines_blocked_feedback_sent';

    private const BLOCKED_DIALOG_FEEDBACK_FAILED_OPERATION = 'openlines_blocked_feedback_failed';

    private const BLOCKED_DIALOG_ACK_SENT_OPERATION = 'openlines_blocked_feedback_ack_sent';

    private const BLOCKED_DIALOG_ACK_FAILED_OPERATION = 'openlines_blocked_feedback_ack_failed';

    private const DELIVERY_SENT_OPERATION = 'openlines_message_delivery_sent';

    private const DELIVERY_RESUMED_OPERATION = 'openlines_message_delivery_resumed';

    private const EXACT_ECHO_SKIPPED_OPERATION = 'openlines_exact_echo_skipped';

    private const DELAYED_RECHECK_SCHEDULED_OPERATION = 'openlines_delayed_recheck_scheduled';

    private const DELAYED_RECHECK_CONFIRMED_ECHO_OPERATION = 'openlines_delayed_recheck_confirmed_echo';

    private const DELAYED_RECHECK_FELL_THROUGH_OPERATION = 'openlines_delayed_recheck_fell_through';

    private const DELAYED_RECHECK_ACK_FAILED_OPERATION = 'openlines_delayed_recheck_ack_failed';

    private const STALE_OPEN_LINE_MESSAGE_IGNORED_OPERATION = 'openlines_stale_chat_ignored';

    private const INBOUND_ECHO_SKIPPED_OPERATION = 'openlines_inbound_echo_skipped';

    private const ECHO_RECHECK_DELAY_SECONDS = 2;

    private const ECHO_CANDIDATE_FRESH_WINDOW_SECONDS = 10;

    private const INBOUND_ECHO_FRESH_WINDOW_SECONDS = 120;

    private const SUCCESSFUL_SEND_EXPECTED_REPLY_WINDOW_SECONDS = 1800;

    private const ECHO_RESULT_NONE = 'none';

    private const ECHO_RESULT_SKIPPED = 'skipped';

    private const ECHO_RESULT_DEFERRED = 'deferred';

    public function __construct(
        private readonly NormalizeBitrix24OpenLinesEventAction $normalizeBitrix24OpenLinesEventAction,
        private readonly HandleBitrix24OpenLinesSessionClosedAction $handleBitrix24OpenLinesSessionClosedAction,
        private readonly ResolveDialogByBitrix24LiveChatKeyAction $resolveDialogByBitrix24LiveChatKeyAction,
        private readonly ResolveBitrix24OpenLinesRouteAction $resolveBitrix24OpenLinesRouteAction,
        private readonly ResolveCurrentBitrix24ConnectionAction $resolveCurrentBitrix24ConnectionAction,
        private readonly ResolveCurrentBitrix24OpenLineChatAction $resolveCurrentBitrix24OpenLineChatAction,
        private readonly IsDialogReadyForBitrix24LiveBridgeAction $isDialogReadyForBitrix24LiveBridgeAction,
        private readonly DeliverBitrix24OpenLinesMessageToMessengerAction $deliverBitrix24OpenLinesMessageToMessengerAction,
        private readonly SendBitrix24OpenLinesBlockedDialogFeedbackAction $sendBitrix24OpenLinesBlockedDialogFeedbackAction,
        private readonly StoreBitrix24OpenLinesOutboundMessageAction $storeBitrix24OpenLinesOutboundMessageAction,
        private readonly AcknowledgeBitrix24OpenLinesDeliveryAction $acknowledgeBitrix24OpenLinesDeliveryAction,
        private readonly MessageChronology $messageChronology,
        private readonly LogBitrix24ApiCallAction $logBitrix24ApiCallAction,
        private readonly ResolveBitrix24OpenLinesDialogBindingAction $resolveDialogBindingAction,
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

                $route = $this->assertMatchesCurrentRuntimeRoute($dialog, $messageData);

                $inboundEchoHandlingResult = $this->handleInboundEchoCallback($event, $dialog, $messageData);

                if ($inboundEchoHandlingResult === self::ECHO_RESULT_SKIPPED) {
                    continue;
                }

                if ($inboundEchoHandlingResult === self::ECHO_RESULT_DEFERRED) {
                    return $event->fresh();
                }

                if ($this->ignoreStaleOpenLineMessageIfNeeded($event, $dialog, $messageData, $route)) {
                    continue;
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

                $echoHandlingResult = $this->handleManualReplyEchoCallback($event, $dialog, $messageData);

                if ($echoHandlingResult === self::ECHO_RESULT_SKIPPED) {
                    $this->activateDialog($dialog, $event, $messageData->chatId);

                    continue;
                }

                if ($echoHandlingResult === self::ECHO_RESULT_DEFERRED) {
                    return $event->fresh();
                }

                $deliveryResultData = $this->findSuccessfulDeliveryPhaseResult($event, $messageData);

                if (! $deliveryResultData instanceof AutoReplyDeliveryResult) {
                    $deliveryResult = $this->deliverBitrix24OpenLinesMessageToMessengerAction->handle(
                        $dialog,
                        $messageData,
                    );

                    if ($event->recheck_attempted_at !== null) {
                        $this->logEchoDecision(
                            operation: self::DELAYED_RECHECK_FELL_THROUGH_OPERATION,
                            event: $event,
                            dialog: $dialog,
                            messageData: $messageData,
                        );
                    }

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

                    $deliveryResultData = $deliveryResult->deliveryResult;
                    $this->logSuccessfulDeliveryPhase($event, $dialog, $messageData, $deliveryResultData);
                } else {
                    $this->logResumedDeliveryPhase($event, $dialog, $messageData, $deliveryResultData);
                }

                $externalMessageId = trim((string) ($deliveryResultData->externalMessageId ?? ''));

                if ($externalMessageId === '') {
                    throw new Bitrix24ApiException('Messenger delivery did not return an external message id for Bitrix acknowledgement.');
                }

                $storedMessage = $this->storeBitrix24OpenLinesOutboundMessageAction->handle(
                    $dialog,
                    $messageData,
                    $deliveryResultData,
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
            } catch (Bitrix24OpenLinesRouteMismatchException $exception) {
                return $this->ignoreRouteMismatchEvent($event, $dialog, $messageData, $exception);
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

    private function assertMatchesCurrentRuntimeRoute(
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
    ): Bitrix24OpenLinesRouteData {
        $route = $this->resolveBitrix24OpenLinesRouteAction->handleIncomingCallback(
            $dialog,
            $messageData->connectorCode,
            $messageData->lineId,
        );

        if ($messageData->connectorCode !== $route->connectorCode) {
            throw new Bitrix24OpenLinesRouteMismatchException(sprintf(
                'Bitrix24 Open Lines callback connector `%s` does not match current runtime route `%s` for dialog #%d.',
                $messageData->connectorCode,
                $route->connectorCode,
                $dialog->id,
            ));
        }

        if ($messageData->lineId !== $route->lineId) {
            throw new Bitrix24OpenLinesRouteMismatchException(sprintf(
                'Bitrix24 Open Lines callback line `%s` does not match current runtime route `%s` for dialog #%d.',
                $messageData->lineId,
                $route->lineId,
                $dialog->id,
            ));
        }

        return $route;
    }

    private function ignoreStaleOpenLineMessageIfNeeded(
        Bitrix24WebhookEvent $event,
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        Bitrix24OpenLinesRouteData $route,
    ): bool {
        $recentSuccessfulSendResult = $this->ignoreByRecentSuccessfulSendChatIfNeeded(
            $event,
            $dialog,
            $messageData,
            $route,
        );

        if ($recentSuccessfulSendResult !== null) {
            return $recentSuccessfulSendResult;
        }

        $selectedBinding = $this->resolveDialogBindingAction->handle($dialog, $route);
        $selectedChatId = $this->selectedOpenLineBindingChatId($selectedBinding?->resolvedBitrixChatId);

        if (
            $selectedChatId !== null
            && $messageData->sourceBitrixChatId !== null
            && $this->canUseSelectedBindingShortcut($dialog, $messageData, $event->created_at)
        ) {
            if ($messageData->sourceBitrixChatId === $selectedChatId) {
                return false;
            }

            $this->logBitrix24ApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: self::STALE_OPEN_LINE_MESSAGE_IGNORED_OPERATION,
                status: Bitrix24SyncLog::STATUS_SKIPPED,
                requestPayload: [
                    'webhook_event_id' => $event->id,
                    'event_name' => $event->event_name,
                    'dialog_id' => $dialog->id,
                    'chat_id' => $messageData->chatId,
                    'source_bitrix_chat_id' => $messageData->sourceBitrixChatId,
                    'current_bitrix_chat_id' => $selectedChatId,
                    'current_chat_source' => 'selected_verified_binding',
                    'selected_user_code' => $selectedBinding?->userCode,
                    'bitrix_message_id' => $messageData->bitrixMessageId,
                    'connector_code' => $messageData->connectorCode,
                    'line_id' => $messageData->lineId,
                    'diagnostic_event_type' => 'duplicate_inbound_skipped',
                    'decision_reason' => 'duplicate_inbound_stale_chat',
                ],
                connection: $event->connection,
                entityType: 'openlines_webhook_event',
                entityId: (string) $event->id,
            );

            return true;
        }

        $connection = $event->connection ?? $this->resolveCurrentBitrix24ConnectionAction->handle();
        $currentChat = $this->resolveCurrentBitrix24OpenLineChatAction->handle(
            $dialog,
            $route,
            $connection,
        );

        if (! $currentChat instanceof Bitrix24CurrentOpenLineChatData) {
            return false;
        }

        $this->syncCurrentOpenLineBinding($dialog, $currentChat);

        if ($messageData->sourceBitrixChatId === null || $messageData->sourceBitrixChatId === $currentChat->chatId) {
            return false;
        }

        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: self::STALE_OPEN_LINE_MESSAGE_IGNORED_OPERATION,
            status: Bitrix24SyncLog::STATUS_SKIPPED,
            requestPayload: [
                'webhook_event_id' => $event->id,
                'event_name' => $event->event_name,
                'dialog_id' => $dialog->id,
                'chat_id' => $messageData->chatId,
                'source_bitrix_chat_id' => $messageData->sourceBitrixChatId,
                'current_bitrix_chat_id' => $currentChat->chatId,
                'bitrix_message_id' => $messageData->bitrixMessageId,
                'connector_code' => $messageData->connectorCode,
                'line_id' => $messageData->lineId,
            ],
            connection: $event->connection,
            entityType: 'openlines_webhook_event',
            entityId: (string) $event->id,
        );

        return true;
    }

    private function canUseSelectedBindingShortcut(
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        ?Carbon $asOf,
    ): bool {
        $verifiedAt = $dialog->bitrix24_open_line_binding_verified_at;

        if (! $verifiedAt instanceof Carbon) {
            return false;
        }

        if ($verifiedAt->lt($this->normalizeDialogTimestampSecond($asOf)->subSeconds(self::SUCCESSFUL_SEND_EXPECTED_REPLY_WINDOW_SECONDS))) {
            return false;
        }

        $latestExport = $this->latestSuccessfulInboundClientExport($dialog, $messageData, $asOf);

        return ! $latestExport instanceof Bitrix24MessageExport
            || $latestExport->resolved_bitrix_chat_verified;
    }

    private function selectedOpenLineBindingChatId(mixed $selectedChatId): ?string
    {
        if (! is_scalar($selectedChatId)) {
            return null;
        }

        $selectedChatId = trim((string) $selectedChatId);

        return $selectedChatId === '' ? null : $selectedChatId;
    }

    private function ignoreByRecentSuccessfulSendChatIfNeeded(
        Bitrix24WebhookEvent $event,
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        Bitrix24OpenLinesRouteData $route,
    ): ?bool {
        if ($messageData->sourceBitrixChatId === null) {
            return null;
        }

        $latestExport = $this->latestSuccessfulInboundClientExport($dialog, $messageData, $event->created_at);

        if (
            ! $latestExport instanceof Bitrix24MessageExport
            || ! $latestExport->resolved_bitrix_chat_verified
            || ! is_string($latestExport->resolved_bitrix_chat_id)
            || trim($latestExport->resolved_bitrix_chat_id) === ''
        ) {
            return null;
        }

        $connection = $event->connection ?? $this->resolveCurrentBitrix24ConnectionAction->handle();
        $expectedChatId = trim($latestExport->resolved_bitrix_chat_id);

        try {
            $currentChat = $this->resolveCurrentBitrix24OpenLineChatAction->handleMatchingChatId(
                $dialog,
                $route,
                $connection,
                $expectedChatId,
            );
        } catch (Bitrix24ApiException) {
            return null;
        }

        if (! $currentChat instanceof Bitrix24CurrentOpenLineChatData) {
            return null;
        }

        if ($messageData->sourceBitrixChatId === $expectedChatId) {
            if (! $this->hasNewerSuccessfulSendStateAfter($dialog, $event->created_at, $latestExport)) {
                $this->syncCurrentOpenLineBinding($dialog, $currentChat);
            }

            return false;
        }

        if (! $this->hasNewerSuccessfulSendStateAfter($dialog, $event->created_at, $latestExport)) {
            $this->syncCurrentOpenLineBinding($dialog, $currentChat);
        }

        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: self::STALE_OPEN_LINE_MESSAGE_IGNORED_OPERATION,
            status: Bitrix24SyncLog::STATUS_SKIPPED,
            requestPayload: [
                'webhook_event_id' => $event->id,
                'event_name' => $event->event_name,
                'dialog_id' => $dialog->id,
                'chat_id' => $messageData->chatId,
                'source_bitrix_chat_id' => $messageData->sourceBitrixChatId,
                'current_bitrix_chat_id' => $expectedChatId,
                'current_chat_source' => 'recent_successful_inbound_export',
                'bitrix_message_id' => $messageData->bitrixMessageId,
                'connector_code' => $messageData->connectorCode,
                'line_id' => $messageData->lineId,
            ],
            responsePayload: [
                'export_id' => $latestExport->id,
                'message_id' => $latestExport->message_id,
                'resolved_bitrix_chat_id' => $expectedChatId,
            ],
            connection: $event->connection,
            entityType: 'openlines_webhook_event',
            entityId: (string) $event->id,
        );

        return true;
    }

    private function handleInboundEchoCallback(
        Bitrix24WebhookEvent $event,
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
    ): string {
        $echoExport = $this->findRecentInboundEchoExport($dialog, $messageData, $event->created_at);

        if ($echoExport instanceof Bitrix24MessageExport) {
            $this->logInboundEchoSkipped(
                $event,
                $dialog,
                $messageData,
                $echoExport,
                $event->recheck_attempted_at !== null
                    ? self::DELAYED_RECHECK_CONFIRMED_ECHO_OPERATION
                    : self::INBOUND_ECHO_SKIPPED_OPERATION,
                $event->recheck_attempted_at !== null
                    ? Bitrix24SyncLog::STATUS_SUCCESS
                    : Bitrix24SyncLog::STATUS_SKIPPED,
            );

            return self::ECHO_RESULT_SKIPPED;
        }

        return self::ECHO_RESULT_NONE;
    }

    private function logInboundEchoSkipped(
        Bitrix24WebhookEvent $event,
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        Bitrix24MessageExport $echoExport,
        string $operation,
        string $status,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: $operation,
            status: $status,
            requestPayload: [
                'webhook_event_id' => $event->id,
                'event_name' => $event->event_name,
                'dialog_id' => $dialog->id,
                'chat_id' => $messageData->chatId,
                'source_bitrix_chat_id' => $messageData->sourceBitrixChatId,
                'bitrix_message_id' => $messageData->bitrixMessageId,
            ],
            responsePayload: [
                'export_id' => $echoExport->id,
                'message_id' => $echoExport->message_id,
                'resolved_bitrix_chat_id' => $echoExport->resolved_bitrix_chat_id,
            ],
            connection: $event->connection,
            entityType: 'openlines_webhook_event',
            entityId: (string) $event->id,
        );
    }

    private function findRecentInboundEchoExport(
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        ?Carbon $asOf = null,
    ): ?Bitrix24MessageExport {
        if ($messageData->bitrixMessageId === '') {
            return null;
        }

        $asOf = $this->normalizeEventSecond($asOf);

        if ($messageData->sourceBitrixChatId !== null) {
            $chatScopedEchoExport = $this->successfulInboundClientExportQuery($dialog)
                ->where('bitrix24_message_exports.resolved_bitrix_chat_id', $messageData->sourceBitrixChatId)
                ->where('bitrix24_message_exports.bitrix_remote_message_id', $messageData->bitrixMessageId)
                ->where('bitrix24_message_exports.exported_at', '>=', $asOf->copy()->subSeconds(self::INBOUND_ECHO_FRESH_WINDOW_SECONDS))
                ->latest('bitrix24_message_exports.exported_at')
                ->latest('bitrix24_message_exports.id')
                ->first()
                ?? $this->uncertainFailedInboundClientExportQuery($dialog)
                    ->where('bitrix24_message_exports.resolved_bitrix_chat_id', $messageData->sourceBitrixChatId)
                    ->where('bitrix24_message_exports.bitrix_remote_message_id', $messageData->bitrixMessageId)
                    ->where('bitrix24_message_exports.failed_at', '>=', $asOf->copy()->subSeconds(self::INBOUND_ECHO_FRESH_WINDOW_SECONDS))
                    ->latest('bitrix24_message_exports.failed_at')
                    ->latest('bitrix24_message_exports.id')
                    ->first();

            if ($chatScopedEchoExport instanceof Bitrix24MessageExport) {
                return $chatScopedEchoExport;
            }
        }

        return $this->uncertainFailedInboundClientExportQuery($dialog)
            ->whereNull('bitrix24_message_exports.resolved_bitrix_chat_id')
            ->where('bitrix24_message_exports.bitrix_remote_message_id', $messageData->bitrixMessageId)
            ->where('bitrix24_message_exports.failed_at', '>=', $asOf->copy()->subSeconds(self::INBOUND_ECHO_FRESH_WINDOW_SECONDS))
            ->latest('bitrix24_message_exports.failed_at')
            ->latest('bitrix24_message_exports.id')
            ->first();
    }

    private function successfulInboundClientExportQuery(Dialog $dialog): Builder
    {
        return Bitrix24MessageExport::query()
            ->select('bitrix24_message_exports.*')
            ->join('messages', 'messages.id', '=', 'bitrix24_message_exports.message_id')
            ->where('messages.dialog_id', $dialog->id)
            ->where('messages.direction', Message::DIRECTION_INBOUND)
            ->where('messages.sent_by_type', Message::SENT_BY_TYPE_CONTACT)
            ->whereIn('messages.message_kind', [
                Message::KIND_INBOUND_USER,
                Message::KIND_INBOUND_CONTACT_SHARE,
            ])
            ->where('bitrix24_message_exports.export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->where('bitrix24_message_exports.export_status', Bitrix24MessageExport::STATUS_EXPORTED)
            ->where('bitrix24_message_exports.transport_method', Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES)
            ->whereNotNull('bitrix24_message_exports.resolved_bitrix_chat_id');
    }

    private function uncertainFailedInboundClientExportQuery(Dialog $dialog): Builder
    {
        return Bitrix24MessageExport::query()
            ->select('bitrix24_message_exports.*')
            ->join('messages', 'messages.id', '=', 'bitrix24_message_exports.message_id')
            ->where('messages.dialog_id', $dialog->id)
            ->where('messages.direction', Message::DIRECTION_INBOUND)
            ->where('messages.sent_by_type', Message::SENT_BY_TYPE_CONTACT)
            ->whereIn('messages.message_kind', [
                Message::KIND_INBOUND_USER,
                Message::KIND_INBOUND_CONTACT_SHARE,
            ])
            ->where('bitrix24_message_exports.export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->where('bitrix24_message_exports.export_status', Bitrix24MessageExport::STATUS_FAILED)
            ->where('bitrix24_message_exports.failure_uncertain', true)
            ->where('bitrix24_message_exports.transport_method', Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES)
            ->whereNotNull('bitrix24_message_exports.bitrix_remote_message_id');
    }

    private function latestSuccessfulInboundClientExport(
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        ?Carbon $asOf = null,
    ): ?Bitrix24MessageExport {
        $asOf = $this->normalizeEventSecond($asOf);
        $windowStart = $asOf->copy()->subSeconds(self::SUCCESSFUL_SEND_EXPECTED_REPLY_WINDOW_SECONDS);
        $latestBeforeEvent = $this->successfulInboundClientExportQuery($dialog)
            ->where('bitrix24_message_exports.exported_at', '>=', $windowStart)
            ->where('bitrix24_message_exports.exported_at', '<', $asOf)
            ->latest('bitrix24_message_exports.exported_at')
            ->latest('bitrix24_message_exports.id')
            ->first();

        if ($messageData->sourceBitrixChatId !== null) {
            $sameSecondSourceExport = $this->successfulInboundClientExportQuery($dialog)
                ->where('bitrix24_message_exports.exported_at', '>=', $asOf)
                ->where('bitrix24_message_exports.exported_at', '<', $asOf->copy()->addSecond())
                ->where('bitrix24_message_exports.resolved_bitrix_chat_id', $messageData->sourceBitrixChatId)
                ->latest('bitrix24_message_exports.exported_at')
                ->latest('bitrix24_message_exports.id')
                ->first();

            if ($sameSecondSourceExport instanceof Bitrix24MessageExport) {
                return $sameSecondSourceExport;
            }

            if (
                $latestBeforeEvent instanceof Bitrix24MessageExport
                && $latestBeforeEvent->resolved_bitrix_chat_id === $messageData->sourceBitrixChatId
            ) {
                return $latestBeforeEvent;
            }
        }

        $sameSecondExport = $this->successfulInboundClientExportQuery($dialog)
            ->where('bitrix24_message_exports.exported_at', '>=', $asOf)
            ->where('bitrix24_message_exports.exported_at', '<', $asOf->copy()->addSecond())
            ->latest('bitrix24_message_exports.exported_at')
            ->latest('bitrix24_message_exports.id')
            ->first();

        if ($sameSecondExport instanceof Bitrix24MessageExport) {
            return $sameSecondExport;
        }

        return $latestBeforeEvent;
    }

    private function hasSuccessfulInboundClientExportAfter(
        Dialog $dialog,
        ?Carbon $asOf = null,
        ?Bitrix24MessageExport $referenceExport = null,
    ): bool {
        $asOf = $this->normalizeEventSecond($asOf);
        $query = $this->successfulInboundClientExportQuery($dialog)
            ->where('bitrix24_message_exports.exported_at', '>=', $asOf);

        if ($referenceExport instanceof Bitrix24MessageExport) {
            $query->where('bitrix24_message_exports.id', '!=', $referenceExport->id);
        }

        return $query->exists();
    }

    private function hasNewerSuccessfulSendStateAfter(
        Dialog $dialog,
        ?Carbon $asOf = null,
        ?Bitrix24MessageExport $referenceExport = null,
    ): bool {
        return $this->hasSuccessfulInboundClientExportAfter($dialog, $asOf, $referenceExport)
            || $this->hasVerifiedOpenLineBindingAfter($dialog, $asOf, $referenceExport);
    }

    private function hasVerifiedOpenLineBindingAfter(
        Dialog $dialog,
        ?Carbon $asOf = null,
        ?Bitrix24MessageExport $referenceExport = null,
    ): bool {
        $bindingState = Dialog::query()
            ->whereKey($dialog->id)
            ->first([
                'bitrix24_open_line_resolved_chat_id_override',
                'bitrix24_open_line_binding_verified_at',
            ]);
        $verifiedAt = $bindingState?->bitrix24_open_line_binding_verified_at;

        if (! $verifiedAt instanceof Carbon) {
            return false;
        }

        $asOfSecond = $this->normalizeDialogTimestampSecond($asOf);

        if ($verifiedAt->gte($asOfSecond->copy()->addSecond())) {
            return true;
        }

        if (
            $verifiedAt->gte($asOfSecond)
            && $referenceExport instanceof Bitrix24MessageExport
            && $this->referenceExportIsBeforeEventSecond($referenceExport, $asOf)
            && is_string($referenceExport->resolved_bitrix_chat_id)
            && trim((string) $bindingState?->bitrix24_open_line_resolved_chat_id_override) !== trim($referenceExport->resolved_bitrix_chat_id)
        ) {
            return true;
        }

        return false;
    }

    private function normalizeDialogTimestampSecond(?Carbon $value): Carbon
    {
        $value = $this->normalizeEventSecond($value);

        // Dialog binding timestamp is stored without timezone, so compare by stored wall-clock second.
        return Carbon::parse($value->format('Y-m-d H:i:s'), config('app.timezone'));
    }

    private function normalizeEventSecond(?Carbon $value): Carbon
    {
        return ($value ?? now())->copy()->startOfSecond();
    }

    private function referenceExportIsBeforeEventSecond(
        Bitrix24MessageExport $referenceExport,
        ?Carbon $asOf = null,
    ): bool {
        if (! $referenceExport->exported_at instanceof Carbon) {
            return false;
        }

        return $referenceExport->exported_at->lt($this->normalizeEventSecond($asOf));
    }

    private function syncCurrentOpenLineBinding(Dialog $dialog, Bitrix24CurrentOpenLineChatData $currentChat): void
    {
        $verifiedAt = now();

        if (
            $dialog->bitrix24_open_line_user_code_override === $currentChat->userCode
            && $dialog->bitrix24_open_line_resolved_chat_id_override === $currentChat->chatId
            && $dialog->bitrix24_open_line_binding_verified_at !== null
        ) {
            $dialog->forceFill([
                'bitrix24_open_line_binding_verified_at' => $verifiedAt,
            ])->save();

            return;
        }

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $currentChat->userCode,
            'bitrix24_open_line_resolved_chat_id_override' => $currentChat->chatId,
            'bitrix24_open_line_binding_verified_at' => $verifiedAt,
        ])->save();
    }

    private function ignoreRouteMismatchEvent(
        Bitrix24WebhookEvent $event,
        ?Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        Bitrix24OpenLinesRouteMismatchException $exception,
    ): Bitrix24WebhookEvent {
        $this->markEventIgnored($event);

        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'openlines_route_mismatch_ignored',
            status: Bitrix24SyncLog::STATUS_SKIPPED,
            requestPayload: array_filter([
                'webhook_event_id' => $event->id,
                'event_name' => $event->event_name,
                'dialog_id' => $dialog?->id,
                'chat_id' => $messageData->chatId,
                'bitrix_message_id' => $messageData->bitrixMessageId,
                'connector_code' => $messageData->connectorCode,
                'line_id' => $messageData->lineId,
            ], static fn (mixed $value): bool => $value !== null),
            connection: $event->connection,
            errorMessage: $exception->getMessage(),
            entityType: 'openlines_webhook_event',
            entityId: (string) $event->id,
        );

        return $event->fresh();
    }

    private function handleManualReplyEchoCallback(
        Bitrix24WebhookEvent $event,
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
    ): string {
        $exactEchoExport = $this->findExactEchoExport($dialog, $messageData->bitrixMessageId);

        if ($exactEchoExport instanceof Bitrix24MessageExport) {
            $this->acknowledgeExactEcho($event, $dialog, $messageData, $exactEchoExport);

            return self::ECHO_RESULT_SKIPPED;
        }

        if ($event->recheck_attempted_at !== null) {
            return self::ECHO_RESULT_NONE;
        }

        $echoCandidate = $this->findSuspiciousEchoCandidate($dialog, $messageData->text);

        if (! $echoCandidate instanceof Message) {
            return self::ECHO_RESULT_NONE;
        }

        $scheduledAt = $this->scheduleDelayedRecheck($event);

        if ($scheduledAt !== null) {
            $this->logEchoDecision(
                operation: self::DELAYED_RECHECK_SCHEDULED_OPERATION,
                event: $event,
                dialog: $dialog,
                messageData: $messageData,
                responsePayload: [
                    'candidate_message_id' => $echoCandidate->id,
                    'recheck_scheduled_at' => $scheduledAt->toIso8601String(),
                ],
            );
        }

        return self::ECHO_RESULT_DEFERRED;
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

    private function buildDeliveryPhaseFingerprint(
        Bitrix24WebhookEvent $event,
        Bitrix24OpenLinesOperatorMessageData $messageData,
    ): string {
        return hash('sha256', implode('|', [
            (string) ($event->connection_id ?? ''),
            (string) $event->portal_domain,
            $messageData->chatId,
            $messageData->bitrixMessageId,
            $messageData->connectorCode,
            $messageData->lineId,
        ]));
    }

    private function findSuccessfulDeliveryPhaseResult(
        Bitrix24WebhookEvent $event,
        Bitrix24OpenLinesOperatorMessageData $messageData,
    ): ?AutoReplyDeliveryResult {
        $phaseLog = Bitrix24SyncLog::query()
            ->where('operation', self::DELIVERY_SENT_OPERATION)
            ->where('status', Bitrix24SyncLog::STATUS_SUCCESS)
            ->where('connection_id', $event->connection_id)
            ->where('fingerprint', $this->buildDeliveryPhaseFingerprint($event, $messageData))
            ->latest('id')
            ->first();

        if (! $phaseLog instanceof Bitrix24SyncLog) {
            return null;
        }

        $payload = $phaseLog->response_payload;

        if (! is_array($payload)) {
            return null;
        }

        $externalMessageId = trim((string) ($payload['external_message_id'] ?? ''));

        if ($externalMessageId === '') {
            return null;
        }

        return new AutoReplyDeliveryResult(
            text: (string) ($payload['text'] ?? $messageData->text),
            externalMessageId: $externalMessageId,
            rawPayload: is_array($payload['raw_payload'] ?? null)
                ? $payload['raw_payload']
                : [],
        );
    }

    private function logSuccessfulDeliveryPhase(
        Bitrix24WebhookEvent $event,
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        AutoReplyDeliveryResult $deliveryResult,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: self::DELIVERY_SENT_OPERATION,
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'webhook_event_id' => $event->id,
                'event_name' => $event->event_name,
                'dialog_id' => $dialog->id,
                'chat_id' => $messageData->chatId,
                'bitrix_message_id' => $messageData->bitrixMessageId,
            ],
            responsePayload: [
                'external_message_id' => $deliveryResult->externalMessageId,
                'text' => $deliveryResult->text,
                'raw_payload' => $deliveryResult->rawPayload,
            ],
            connection: $event->connection,
            entityType: self::DELIVERY_PHASE_ENTITY_TYPE,
            entityId: $messageData->bitrixMessageId,
            fingerprint: $this->buildDeliveryPhaseFingerprint($event, $messageData),
        );
    }

    private function logResumedDeliveryPhase(
        Bitrix24WebhookEvent $event,
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        AutoReplyDeliveryResult $deliveryResult,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: self::DELIVERY_RESUMED_OPERATION,
            status: Bitrix24SyncLog::STATUS_SKIPPED,
            requestPayload: [
                'webhook_event_id' => $event->id,
                'event_name' => $event->event_name,
                'dialog_id' => $dialog->id,
                'chat_id' => $messageData->chatId,
                'bitrix_message_id' => $messageData->bitrixMessageId,
            ],
            responsePayload: [
                'external_message_id' => $deliveryResult->externalMessageId,
            ],
            connection: $event->connection,
            entityType: self::DELIVERY_PHASE_ENTITY_TYPE,
            entityId: $messageData->bitrixMessageId,
            fingerprint: $this->buildDeliveryPhaseFingerprint($event, $messageData),
        );
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

    private function findExactEchoExport(Dialog $dialog, string $bitrixMessageId): ?Bitrix24MessageExport
    {
        $transportMethod = $this->resolveExactEchoTransportMethod();

        if ($transportMethod === null) {
            return null;
        }

        return Bitrix24MessageExport::query()
            ->with('message')
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->where('transport_method', $transportMethod)
            ->where('bitrix_remote_message_id', $bitrixMessageId)
            ->whereHas('message', function (Builder $query) use ($dialog): void {
                $query
                    ->where('dialog_id', $dialog->id)
                    ->where('direction', Message::DIRECTION_OUTBOUND)
                    ->where('message_kind', Message::KIND_OUTBOUND_MANUAL_REPLY);
            })
            ->first();
    }

    private function resolveExactEchoTransportMethod(): ?string
    {
        if (! Schema::hasColumns('bitrix24_message_exports', [
            'transport_method',
            'bitrix_remote_message_id',
        ])) {
            return null;
        }

        $transportConstant = Bitrix24MessageExport::class.'::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD';

        if (defined($transportConstant)) {
            /** @var string */
            return constant($transportConstant);
        }

        return null;
    }

    private function findSuspiciousEchoCandidate(Dialog $dialog, string $bitrixText): ?Message
    {
        $normalizedBitrixText = $this->normalizeEchoText($bitrixText);

        if ($normalizedBitrixText === '') {
            return null;
        }

        $freshAfter = now()->subSeconds(self::ECHO_CANDIDATE_FRESH_WINDOW_SECONDS);

        $candidateMessages = Message::query()
            ->select('messages.*')
            ->join('bitrix24_message_exports as live_exports', function (JoinClause $join): void {
                $join
                    ->on('live_exports.message_id', '=', 'messages.id')
                    ->where('live_exports.export_mode', Bitrix24MessageExport::MODE_LIVE)
                    ->where('live_exports.export_status', Bitrix24MessageExport::STATUS_PENDING);
            })
            ->where('messages.dialog_id', $dialog->id)
            ->where('messages.direction', Message::DIRECTION_OUTBOUND)
            ->where('messages.message_kind', Message::KIND_OUTBOUND_MANUAL_REPLY)
            ->whereRaw($this->messageChronology->sqlSortAt('messages').' >= ?', [$freshAfter])
            ->tap(fn (Builder $query): Builder => $this->messageChronology->applyLatestOrder($query))
            ->get()
            ->filter(fn (Message $candidate): bool => $this->normalizeEchoText($candidate->text) === $normalizedBitrixText)
            ->values();

        if ($candidateMessages->count() !== 1) {
            return null;
        }

        return $candidateMessages->first();
    }

    private function scheduleDelayedRecheck(Bitrix24WebhookEvent $event): ?Carbon
    {
        $scheduledAt = now()->addSeconds(self::ECHO_RECHECK_DELAY_SECONDS);

        $updated = Bitrix24WebhookEvent::query()
            ->whereKey($event->id)
            ->where('processing_status', Bitrix24WebhookEvent::STATUS_PENDING)
            ->whereNull('recheck_scheduled_at')
            ->whereNull('recheck_attempted_at')
            ->update([
                'recheck_scheduled_at' => $scheduledAt,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return null;
        }

        try {
            ProcessBitrix24WebhookEventJob::dispatch($event->id)->delay($scheduledAt);
        } catch (Throwable $throwable) {
            Bitrix24WebhookEvent::query()
                ->whereKey($event->id)
                ->where('processing_status', Bitrix24WebhookEvent::STATUS_PENDING)
                ->whereNull('recheck_attempted_at')
                ->update([
                    'recheck_scheduled_at' => null,
                    'updated_at' => now(),
                ]);

            throw $throwable;
        }

        return $scheduledAt;
    }

    private function acknowledgeExactEcho(
        Bitrix24WebhookEvent $event,
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        Bitrix24MessageExport $exactEchoExport,
    ): void {
        $localMessage = $exactEchoExport->message;

        if (! $localMessage instanceof Message) {
            throw new Bitrix24ApiException('Bitrix24 exact echo export record does not have a local message.');
        }

        $externalMessageId = trim((string) $localMessage->external_message_id);

        if ($externalMessageId === '') {
            throw new Bitrix24ApiException('Bitrix24 exact echo local message does not have a stored external delivery id.');
        }

        try {
            $this->acknowledgeBitrix24OpenLinesDeliveryAction->handle(
                $dialog,
                $messageData,
                $externalMessageId,
            );
        } catch (Throwable $throwable) {
            if ($event->recheck_attempted_at !== null) {
                $this->logEchoDecision(
                    operation: self::DELAYED_RECHECK_ACK_FAILED_OPERATION,
                    event: $event,
                    dialog: $dialog,
                    messageData: $messageData,
                    responsePayload: [
                        'local_message_id' => $localMessage->id,
                        'external_message_id' => $externalMessageId,
                    ],
                    errorMessage: $throwable->getMessage(),
                    status: Bitrix24SyncLog::STATUS_FAILED,
                );
            }

            throw $throwable;
        }

        $this->logEchoDecision(
            operation: $event->recheck_attempted_at !== null
                ? self::DELAYED_RECHECK_CONFIRMED_ECHO_OPERATION
                : self::EXACT_ECHO_SKIPPED_OPERATION,
            event: $event,
            dialog: $dialog,
            messageData: $messageData,
            responsePayload: [
                'local_message_id' => $localMessage->id,
                'external_message_id' => $externalMessageId,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $responsePayload
     */
    private function logEchoDecision(
        string $operation,
        Bitrix24WebhookEvent $event,
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $messageData,
        array $responsePayload = [],
        ?string $errorMessage = null,
        string $status = Bitrix24SyncLog::STATUS_SUCCESS,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: $operation,
            status: $status,
            requestPayload: [
                'webhook_event_id' => $event->id,
                'event_name' => $event->event_name,
                'dialog_id' => $dialog->id,
                'chat_id' => $messageData->chatId,
                'bitrix_message_id' => $messageData->bitrixMessageId,
            ],
            responsePayload: $responsePayload,
            connection: $event->connection,
            errorMessage: $errorMessage,
            entityType: 'openlines_webhook_event',
            entityId: (string) $event->id,
        );
    }

    private function normalizeEchoText(?string $value): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);

        return trim($text);
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
