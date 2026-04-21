<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24MessageExport;
use App\Models\Bitrix24SyncLog;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bots\QueueDeferredParameterAutoReplyAction;
use App\Services\Contacts\ResolveRootContactAction;

class ExportMessageToBitrix24OpenLinesAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveBitrix24LiveChatKeyAction $resolveBitrix24LiveChatKeyAction,
        private readonly ResolveBitrix24OpenLinesRouteAction $resolveBitrix24OpenLinesRouteAction,
        private readonly BuildBitrix24OpenLinesMessagePayloadAction $buildBitrix24OpenLinesMessagePayloadAction,
        private readonly ExportManualReplyToBitrix24OpenLinesAction $exportManualReplyToBitrix24OpenLinesAction,
        private readonly IsMessageReadyForBitrix24LiveExportAction $isMessageReadyForBitrix24LiveExportAction,
        private readonly LogBitrix24RawContactPhoneSnapshotAction $logBitrix24RawContactPhoneSnapshotAction,
        private readonly QueueBitrix24RawContactPhoneSnapshotAction $queueBitrix24RawContactPhoneSnapshotAction,
        private readonly QueueBitrix24ContactPhoneDedupeAction $queueBitrix24ContactPhoneDedupeAction,
        private readonly IsDialogBitrix24OpenLinesRetryRequiredAction $isDialogBitrix24OpenLinesRetryRequiredAction,
        private readonly QueueDeferredParameterAutoReplyAction $queueDeferredParameterAutoReplyAction,
        private readonly Bitrix24ApiClient $bitrix24ApiClient,
        private readonly LogBitrix24ApiCallAction $logBitrix24ApiCallAction,
    ) {}

    public function handle(Message|int $message, bool $retryAfterSync = false): Message
    {
        $message = $message instanceof Message
            ? $message
            : Message::query()->with(['dialog.channel', 'contact'])->findOrFail($message);

        $liveExport = Bitrix24MessageExport::query()
            ->where('message_id', $message->id)
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->first();

        if ($liveExport?->export_status === Bitrix24MessageExport::STATUS_EXPORTED) {
            return $message->fresh() ?? $message;
        }

        if (! $this->isMessageReadyForBitrix24LiveExportAction->handle($message)) {
            return $message->fresh() ?? $message;
        }

        $dialog = $message->dialog()->firstOrFail();
        $rootContact = $this->resolveRootContactAction->handle($message->contact()->firstOrFail());

        $this->markPending($message, $rootContact->id, (string) $rootContact->bitrix24_contact_id);

        try {
            $route = $this->resolveBitrix24OpenLinesRouteAction->handle($dialog);

            if ($this->fakeHappyPathEnabled()) {
                return $this->completeSuccessfulExport(
                    message: $message,
                    dialog: $dialog,
                    rootContactId: $rootContact->id,
                    bitrix24ContactId: (string) $rootContact->bitrix24_contact_id,
                    connectorCode: $route->connectorCode,
                    lineId: $route->lineId,
                    retryAfterSync: $retryAfterSync,
                    chatKey: $this->fakeLiveChatKey($dialog),
                    operation: 'openlines_live_exported_fake',
                    transportMethod: Bitrix24MessageExport::TRANSPORT_FAKE_HAPPY_PATH,
                    responsePayload: [
                        'fake_mode' => true,
                        'result' => true,
                    ],
                );
            }

            if ($this->shouldUseServiceActorManualReplyPath($message)) {
                try {
                    $manualReplyExport = $this->exportManualReplyToBitrix24OpenLinesAction->handle(
                        $message,
                        $dialog,
                        $rootContact,
                    );

                    return $this->completeSuccessfulExport(
                        message: $message,
                        dialog: $dialog,
                        rootContactId: $rootContact->id,
                        bitrix24ContactId: (string) $rootContact->bitrix24_contact_id,
                        connectorCode: $route->connectorCode,
                        lineId: $route->lineId,
                        retryAfterSync: $retryAfterSync,
                        chatKey: filled($dialog->bitrix24_live_chat_id)
                            ? (string) $dialog->bitrix24_live_chat_id
                            : $this->resolveBitrix24LiveChatKeyAction->handle($dialog),
                        operation: 'openlines_manual_reply_exported',
                        transportMethod: Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
                        resolvedBitrixChatId: $manualReplyExport->resolvedBitrixChatId,
                        bitrixRemoteMessageId: $manualReplyExport->bitrixRemoteMessageId,
                        responsePayload: [
                            'result' => [
                                'chat_id' => $manualReplyExport->resolvedBitrixChatId,
                                'message_id' => $manualReplyExport->bitrixRemoteMessageId,
                                'used_fallback' => $manualReplyExport->usedFallback,
                                'used_chat_user_add_recovery' => $manualReplyExport->usedChatUserAddRecovery,
                            ],
                            'rest_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
                        ],
                    );
                } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
                    if ($this->shouldFallbackToLegacyManualReplyTransport($exception)) {
                        return $this->exportViaLegacyTransport(
                            message: $message,
                            dialog: $dialog,
                            rootContactId: $rootContact->id,
                            bitrix24ContactId: (string) $rootContact->bitrix24_contact_id,
                            connectorCode: $route->connectorCode,
                            lineId: $route->lineId,
                            retryAfterSync: $retryAfterSync,
                            operation: 'openlines_manual_reply_exported_legacy_fallback',
                            responsePayload: [
                                'fallback_from_failure_code' => $exception->failureCode,
                                'fallback_from_failure_reason' => $exception->getMessage(),
                            ],
                        );
                    }

                    throw $exception;
                }
            }

            return $this->exportViaLegacyTransport(
                message: $message,
                dialog: $dialog,
                rootContactId: $rootContact->id,
                bitrix24ContactId: (string) $rootContact->bitrix24_contact_id,
                connectorCode: $route->connectorCode,
                lineId: $route->lineId,
                retryAfterSync: $retryAfterSync,
                operation: 'openlines_live_exported',
            );
        } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
            $this->markFailed(
                $message,
                $rootContact->id,
                (string) $rootContact->bitrix24_contact_id,
                $exception->getMessage(),
                Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
                failureCode: $exception->failureCode,
                failureUncertain: $exception->failureUncertain,
            );

            $dialog->forceFill([
                'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_FAILED,
            ])->save();

            throw $exception;
        } catch (\Throwable $throwable) {
            $this->markFailed(
                $message,
                $rootContact->id,
                (string) $rootContact->bitrix24_contact_id,
                $throwable->getMessage(),
                Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            );

            $dialog->forceFill([
                'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_FAILED,
            ])->save();

            throw $throwable;
        }
    }

    private function markPending(Message $message, int $rootContactId, string $bitrix24ContactId): void
    {
        Bitrix24MessageExport::query()->updateOrCreate(
            [
                'message_id' => $message->id,
                'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            ],
            [
                'contact_id' => $rootContactId,
                'bitrix24_contact_id' => $bitrix24ContactId,
                'export_status' => Bitrix24MessageExport::STATUS_PENDING,
                'transport_method' => null,
                'resolved_bitrix_chat_id' => null,
                'bitrix_remote_message_id' => null,
                'batch_uuid' => null,
                'bitrix24_timeline_entry_id' => null,
                'exported_at' => null,
                'failed_at' => null,
                'failure_code' => null,
                'failure_uncertain' => false,
                'failure_reason' => null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $responsePayload
     */
    private function completeSuccessfulExport(
        Message $message,
        Dialog $dialog,
        int $rootContactId,
        string $bitrix24ContactId,
        string $connectorCode,
        string $lineId,
        bool $retryAfterSync,
        string $chatKey,
        string $operation,
        array $responsePayload,
        ?string $transportMethod,
        ?string $resolvedBitrixChatId = null,
        ?string $bitrixRemoteMessageId = null,
    ): Message {
        $previousLiveStatus = $dialog->bitrix24_live_status;
        $fakeHappyPathEnabled = $this->fakeHappyPathEnabled();

        $this->markExported(
            $message,
            $rootContactId,
            $bitrix24ContactId,
            $transportMethod,
            $resolvedBitrixChatId,
            $bitrixRemoteMessageId,
        );

        $dialog->forceFill([
            'bitrix24_live_chat_id' => $chatKey,
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
            'bitrix24_live_last_exported_at' => now(),
        ])->save();

        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: $operation,
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'message_id' => $message->id,
                'dialog_id' => $dialog->id,
                'contact_id' => $rootContactId,
                'bitrix24_contact_id' => $bitrix24ContactId,
                'chat_id' => $chatKey,
                'connector_code' => $connectorCode,
                'line_id' => $lineId,
                'retry_after_sync' => $retryAfterSync,
                'fake_mode' => $fakeHappyPathEnabled,
            ],
            responsePayload: $responsePayload,
            connection: null,
            entityType: 'message',
            entityId: (string) $message->id,
        );

        if (
            ! $fakeHappyPathEnabled
            && in_array($previousLiveStatus, [
                Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
                Dialog::BITRIX24_LIVE_STATUS_FAILED,
            ], true)
        ) {
            $rootContact = $this->resolveRootContactAction->handle($rootContactId);

            $this->logBitrix24RawContactPhoneSnapshotAction->handle(
                $rootContact,
                'after_live_export',
                $dialog,
                $message,
            );
            $this->queueBitrix24RawContactPhoneSnapshotAction->handle(
                $rootContact,
                'delayed_post_attach',
                $dialog,
                $message,
            );
            $this->queueBitrix24ContactPhoneDedupeAction->handle($rootContact);
        }

        if (in_array($previousLiveStatus, [
            Dialog::BITRIX24_LIVE_STATUS_CLOSED,
            Dialog::BITRIX24_LIVE_STATUS_FAILED,
        ], true)) {
            $this->logBitrix24ApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'openlines_dialog_reopened',
                status: Bitrix24SyncLog::STATUS_SUCCESS,
                requestPayload: [
                    'message_id' => $message->id,
                    'dialog_id' => $dialog->id,
                    'previous_live_status' => $previousLiveStatus,
                    'chat_id' => $chatKey,
                    'fake_mode' => $fakeHappyPathEnabled,
                ],
                connection: null,
                entityType: 'dialog',
                entityId: (string) $dialog->id,
            );
        }

        if (
            $retryAfterSync
            && filled($dialog->pending_auto_reply_source_message_id)
            && ! $this->isDialogBitrix24OpenLinesRetryRequiredAction->handle($dialog)
        ) {
            $this->queueDeferredParameterAutoReplyAction->handle($dialog);
        }

        return $message->fresh() ?? $message;
    }

    private function fakeHappyPathEnabled(): bool
    {
        return (bool) config('bitrix24.features.fake_happy_path_enabled', false)
            && ! app()->environment('production');
    }

    private function fakeLiveChatKey(Dialog $dialog): string
    {
        return sprintf('fake-live-dialog-%d', $dialog->id);
    }

    private function shouldUseServiceActorManualReplyPath(Message $message): bool
    {
        if ($message->message_kind !== Message::KIND_OUTBOUND_MANUAL_REPLY) {
            return false;
        }

        return (int) config('bitrix24.openlines.service_user_id', 0) > 0;
    }

    private function shouldFallbackToLegacyManualReplyTransport(
        Bitrix24OpenLinesManualReplyExportException $exception,
    ): bool {
        if ($exception->failureUncertain) {
            return false;
        }

        return in_array($exception->failureCode, [
            Bitrix24MessageExport::FAILURE_SESSION_OPEN_UNAVAILABLE,
            Bitrix24MessageExport::FAILURE_SESSION_OPEN_FAILED,
            Bitrix24MessageExport::FAILURE_CHAT_ACCESS_DENIED,
            Bitrix24MessageExport::FAILURE_CHAT_USER_ADD_FAILED,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $responsePayload
     */
    private function exportViaLegacyTransport(
        Message $message,
        Dialog $dialog,
        int $rootContactId,
        string $bitrix24ContactId,
        string $connectorCode,
        string $lineId,
        bool $retryAfterSync,
        string $operation,
        array $responsePayload = [],
    ): Message {
        $payload = $this->buildBitrix24OpenLinesMessagePayloadAction->handle($message, new \App\Data\Bitrix24\Bitrix24OpenLinesRouteData(
            platform: $dialog->channel()->firstOrFail()->platform,
            connectorCode: $connectorCode,
            lineId: $lineId,
        ), $retryAfterSync);
        $response = $this->bitrix24ApiClient->call('imconnector.send.messages', $payload);

        if (! $response->successful) {
            throw new Bitrix24ApiException($response->errorMessage ?? 'Bitrix24 Open Lines message export failed.');
        }

        return $this->completeSuccessfulExport(
            message: $message,
            dialog: $dialog,
            rootContactId: $rootContactId,
            bitrix24ContactId: $bitrix24ContactId,
            connectorCode: $connectorCode,
            lineId: $lineId,
            retryAfterSync: $retryAfterSync,
            chatKey: $this->resolveBitrix24LiveChatKeyAction->handle($dialog),
            operation: $operation,
            transportMethod: Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            resolvedBitrixChatId: $this->extractLegacySessionChatId($response->result),
            responsePayload: $responsePayload + [
                'result' => $response->result,
                'rest_method' => $response->restMethod,
            ],
        );
    }

    private function extractLegacySessionChatId(mixed $result): ?string
    {
        if (! is_array($result)) {
            return null;
        }

        $items = data_get($result, 'DATA.RESULT');

        if (! is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $chatId = data_get($item, 'session.CHAT_ID');

            if (is_scalar($chatId) && trim((string) $chatId) !== '') {
                return trim((string) $chatId);
            }
        }

        return null;
    }

    private function markExported(
        Message $message,
        int $rootContactId,
        string $bitrix24ContactId,
        ?string $transportMethod,
        ?string $resolvedBitrixChatId,
        ?string $bitrixRemoteMessageId,
    ): void {
        Bitrix24MessageExport::query()
            ->where('message_id', $message->id)
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->update([
                'contact_id' => $rootContactId,
                'bitrix24_contact_id' => $bitrix24ContactId,
                'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
                'transport_method' => $transportMethod,
                'resolved_bitrix_chat_id' => $resolvedBitrixChatId,
                'bitrix_remote_message_id' => $bitrixRemoteMessageId,
                'exported_at' => now(),
                'failed_at' => null,
                'failure_code' => null,
                'failure_uncertain' => false,
                'failure_reason' => null,
                'updated_at' => now(),
            ]);
    }

    private function markFailed(
        Message $message,
        int $rootContactId,
        string $bitrix24ContactId,
        string $failureReason,
        ?string $transportMethod = null,
        ?string $resolvedBitrixChatId = null,
        ?string $failureCode = null,
        bool $failureUncertain = false,
    ): void {
        Bitrix24MessageExport::query()->updateOrCreate(
            [
                'message_id' => $message->id,
                'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            ],
            [
                'contact_id' => $rootContactId,
                'bitrix24_contact_id' => $bitrix24ContactId,
                'export_status' => Bitrix24MessageExport::STATUS_FAILED,
                'transport_method' => $transportMethod,
                'resolved_bitrix_chat_id' => $resolvedBitrixChatId,
                'bitrix_remote_message_id' => null,
                'batch_uuid' => null,
                'bitrix24_timeline_entry_id' => null,
                'exported_at' => null,
                'failed_at' => now(),
                'failure_code' => $failureCode,
                'failure_uncertain' => $failureUncertain,
                'failure_reason' => $failureReason,
            ],
        );
    }
}
