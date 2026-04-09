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
            $payload = $this->buildBitrix24OpenLinesMessagePayloadAction->handle($message, $route, $retryAfterSync);
            $response = $this->bitrix24ApiClient->call('imconnector.send.messages', $payload);

            if (! $response->successful) {
                throw new Bitrix24ApiException($response->errorMessage ?? 'Bitrix24 Open Lines message export failed.');
            }

            $chatKey = $this->resolveBitrix24LiveChatKeyAction->handle($dialog);
            $previousLiveStatus = $dialog->bitrix24_live_status;

            $this->markExported($message, $rootContact->id, (string) $rootContact->bitrix24_contact_id);

            $dialog->forceFill([
                'bitrix24_live_chat_id' => $chatKey,
                'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
                'bitrix24_live_last_exported_at' => now(),
            ])->save();

            $this->logBitrix24ApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'openlines_live_exported',
                status: Bitrix24SyncLog::STATUS_SUCCESS,
                requestPayload: [
                    'message_id' => $message->id,
                    'dialog_id' => $dialog->id,
                    'contact_id' => $rootContact->id,
                    'bitrix24_contact_id' => $rootContact->bitrix24_contact_id,
                    'chat_id' => $chatKey,
                    'connector_code' => $route->connectorCode,
                    'line_id' => $route->lineId,
                    'retry_after_sync' => $retryAfterSync,
                ],
                responsePayload: [
                    'result' => $response->result,
                    'rest_method' => $response->restMethod,
                ],
                connection: null,
                entityType: 'message',
                entityId: (string) $message->id,
            );

            if (in_array($previousLiveStatus, [
                Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
                Dialog::BITRIX24_LIVE_STATUS_FAILED,
            ], true)) {
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
        } catch (\Throwable $throwable) {
            $this->markFailed(
                $message,
                $rootContact->id,
                (string) $rootContact->bitrix24_contact_id,
                $throwable->getMessage(),
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
                'batch_uuid' => null,
                'bitrix24_timeline_entry_id' => null,
                'exported_at' => null,
                'failed_at' => null,
                'failure_reason' => null,
            ],
        );
    }

    private function markExported(Message $message, int $rootContactId, string $bitrix24ContactId): void
    {
        Bitrix24MessageExport::query()
            ->where('message_id', $message->id)
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->update([
                'contact_id' => $rootContactId,
                'bitrix24_contact_id' => $bitrix24ContactId,
                'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
                'exported_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
                'updated_at' => now(),
            ]);
    }

    private function markFailed(Message $message, int $rootContactId, string $bitrix24ContactId, string $failureReason): void
    {
        Bitrix24MessageExport::query()->updateOrCreate(
            [
                'message_id' => $message->id,
                'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            ],
            [
                'contact_id' => $rootContactId,
                'bitrix24_contact_id' => $bitrix24ContactId,
                'export_status' => Bitrix24MessageExport::STATUS_FAILED,
                'batch_uuid' => null,
                'bitrix24_timeline_entry_id' => null,
                'exported_at' => null,
                'failed_at' => now(),
                'failure_reason' => $failureReason,
            ],
        );
    }
}
