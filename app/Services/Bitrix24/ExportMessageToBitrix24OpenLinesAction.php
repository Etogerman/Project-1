<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24CurrentOpenLineChatData;
use App\Data\Bitrix24\Bitrix24OpenLinesIdentityDecisionData;
use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24MessageExport;
use App\Models\Bitrix24SyncLog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bots\QueueDeferredParameterAutoReplyAction;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Support\Str;
use Throwable;

class ExportMessageToBitrix24OpenLinesAction
{
    private const LIVE_CLAIM_LEASE_SECONDS = 120;

    private const VERIFIED_BINDING_FAST_PATH_WINDOW_SECONDS = 1800;

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
        private readonly ResolveCurrentBitrix24ConnectionAction $resolveCurrentConnectionAction,
        private readonly ResolveBitrix24OpenLinesDialogBindingAction $resolveDialogBindingAction,
        private readonly ResolveBitrix24OpenLinesIdentityDecisionAction $resolveIdentityDecisionAction,
        private readonly ResolveCurrentBitrix24OpenLineChatAction $resolveCurrentOpenLineChatAction,
        private readonly GuardBitrix24OpenLineMutationAction $guardOpenLineMutationAction,
        private readonly RepairStaleBitrix24ContactForLiveExportAction $repairStaleBitrix24ContactForLiveExportAction,
        private readonly QueueBitrix24LiveMessageExportAction $queueBitrix24LiveMessageExportAction,
        private readonly MarkBitrix24OpenLineRouteMisconfiguredAction $markRouteMisconfiguredAction,
    ) {}

    public function handle(
        Message|int $message,
        bool $retryAfterSync = false,
        ?string $liveBatchUuid = null,
        ?string $retryAfterSyncReason = null,
    ): Message {
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
        $bitrix24ContactId = (string) $rootContact->bitrix24_contact_id;
        $liveExport = $this->claimLiveExport(
            $message,
            $rootContact->id,
            $bitrix24ContactId,
            $liveBatchUuid,
        );

        if (! $liveExport instanceof Bitrix24MessageExport) {
            return $message->fresh() ?? $message;
        }

        $route = null;

        try {
            $route = $this->resolveBitrix24OpenLinesRouteAction->handle($dialog);

            if ($this->fakeHappyPathEnabled()) {
                return $this->completeSuccessfulExport(
                    message: $message,
                    dialog: $dialog,
                    rootContactId: $rootContact->id,
                    bitrix24ContactId: $bitrix24ContactId,
                    connectorCode: $route->connectorCode,
                    lineId: $route->lineId,
                    routeId: $route->routeId,
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

            if ($this->shouldUseControlledConnectorMirrorManualReplyPath($message, $route)) {
                return $this->exportViaLegacyTransport(
                    message: $message,
                    dialog: $dialog,
                    rootContactId: $rootContact->id,
                    bitrix24ContactId: $bitrix24ContactId,
                    connectorCode: $route->connectorCode,
                    lineId: $route->lineId,
                    routeId: $route->routeId,
                    retryAfterSync: $retryAfterSync,
                    retryAfterSyncReason: $retryAfterSyncReason,
                    operation: 'openlines_manual_reply_exported_connector_mirror',
                    connection: $this->resolveCurrentConnectionAction->handle(),
                    applyLegacyFallbackSignature: true,
                    requireExpectedResolvedBitrixChatId: true,
                    allowPostSendBindingResync: false,
                    responsePayload: [
                        'controlled_manual_reply_connector_mirror' => true,
                    ],
                );
            }

            if ($this->shouldUseServiceActorManualReplyPath($message, $route)) {
                $manualReplyConnection = $this->resolveCurrentConnectionAction->handle();

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
                        routeId: $route->routeId,
                        retryAfterSync: $retryAfterSync,
                        chatKey: $this->resolveExportChatKey($dialog, $route),
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
                                'resolved_crm_entity_type' => $manualReplyExport->resolvedCrmEntityType,
                                'resolved_crm_entity_id' => $manualReplyExport->resolvedCrmEntityId,
                            ],
                            'rest_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
                        ],
                        resolvedCrmEntityType: $manualReplyExport->resolvedCrmEntityType,
                        resolvedCrmEntityId: $manualReplyExport->resolvedCrmEntityId,
                    );
                } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
                    if ($this->shouldFallbackToLegacyManualReplyTransport($exception)) {
                        return $this->exportViaLegacyTransport(
                            message: $message,
                            dialog: $dialog,
                            rootContactId: $rootContact->id,
                            bitrix24ContactId: $bitrix24ContactId,
                            connectorCode: $route->connectorCode,
                            lineId: $route->lineId,
                            routeId: $route->routeId,
                            retryAfterSync: $retryAfterSync,
                            retryAfterSyncReason: $retryAfterSyncReason,
                            operation: 'openlines_manual_reply_exported_legacy_fallback',
                            connection: $manualReplyConnection,
                            applyLegacyFallbackSignature: $this->shouldApplyLegacyFallbackSignature($message),
                            expectedResolvedBitrixChatId: $this->resolveExpectedLegacyFallbackChatId($dialog, $route, $exception),
                            responsePayload: [
                                'fallback_from_failure_code' => $exception->failureCode,
                                'fallback_from_failure_reason' => $exception->getMessage(),
                            ],
                        );
                    }

                    throw $exception;
                }
            }

            if ($this->shouldUseInboundClientFastPath($message)) {
                $fastPathResult = $this->tryExportInboundClientFastPath(
                    message: $message,
                    dialog: $dialog,
                    rootContactId: $rootContact->id,
                    bitrix24ContactId: $bitrix24ContactId,
                    route: $route,
                    retryAfterSync: $retryAfterSync,
                );

                if ($fastPathResult instanceof Message) {
                    return $fastPathResult;
                }
            }

            return $this->exportViaLegacyTransport(
                message: $message,
                dialog: $dialog,
                rootContactId: $rootContact->id,
                bitrix24ContactId: $bitrix24ContactId,
                connectorCode: $route->connectorCode,
                lineId: $route->lineId,
                routeId: $route->routeId,
                retryAfterSync: $retryAfterSync,
                retryAfterSyncReason: $retryAfterSyncReason,
                operation: 'openlines_live_exported',
                applyLegacyFallbackSignature: $this->shouldApplyLegacyFallbackSignature($message),
            );
        } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
            $this->markFailed(
                $message,
                $rootContact->id,
                $bitrix24ContactId,
                $exception->getMessage(),
                Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
                failureCode: $exception->failureCode,
                failureUncertain: $exception->failureUncertain,
            );

            $dialog->forceFill([
                'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_FAILED,
            ])->save();

            throw $exception;
        } catch (Bitrix24LiveExportTransportException $exception) {
            $this->markRouteMisconfiguredOnInactiveLineFailure($route, $dialog, $exception);

            if ($this->handleStaleContactRepairAfterFailedExport(
                message: $message,
                dialog: $dialog,
                rootContact: $rootContact,
                bitrix24ContactId: $bitrix24ContactId,
                exception: $exception,
                transportMethod: Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            )) {
                return $message->fresh() ?? $message;
            }

            if ($this->handleStaleOpenLineBindingRepairAfterFailedExport(
                message: $message,
                dialog: $dialog,
                rootContact: $rootContact,
                bitrix24ContactId: $bitrix24ContactId,
                exception: $exception,
                transportMethod: Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            )) {
                return $message->fresh() ?? $message;
            }

            $this->markFailed(
                $message,
                $rootContact->id,
                $bitrix24ContactId,
                $exception->getMessage(),
                Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
                resolvedBitrixChatId: $exception->resolvedBitrixChatId,
                bitrixRemoteMessageId: $exception->bitrixRemoteMessageId,
                failureCode: $exception->failureCode,
                failureUncertain: $exception->failureUncertain,
            );

            $dialog->forceFill([
                'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_FAILED,
            ])->save();

            throw $exception;
        } catch (Throwable $throwable) {
            $this->markFailed(
                $message,
                $rootContact->id,
                $bitrix24ContactId,
                $throwable->getMessage(),
                Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            );

            $dialog->forceFill([
                'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_FAILED,
            ])->save();

            throw $throwable;
        }
    }

    private function handleStaleContactRepairAfterFailedExport(
        Message $message,
        Dialog $dialog,
        Contact $rootContact,
        string $bitrix24ContactId,
        Bitrix24LiveExportTransportException $exception,
        ?string $transportMethod,
    ): bool {
        if ($exception->failureUncertain) {
            return false;
        }

        $notFound = $this->findContactNotFoundException($exception);

        if (! $notFound instanceof Bitrix24ContactNotFoundException) {
            return false;
        }

        if ($notFound->restMethod !== 'crm.contact.get' || $notFound->bitrix24ContactId !== $bitrix24ContactId) {
            return false;
        }

        $this->markFailed(
            $message,
            $rootContact->id,
            $bitrix24ContactId,
            $exception->getMessage(),
            $transportMethod,
            failureCode: $exception->failureCode,
            failureUncertain: false,
        );

        $dialog->forceFill([
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_FAILED,
        ])->save();

        $result = $this->repairStaleBitrix24ContactForLiveExportAction->handle(
            $message,
            $rootContact->fresh() ?? $rootContact,
            $bitrix24ContactId,
        );

        if ($result->repaired) {
            return true;
        }

        $this->markFailed(
            $message,
            $rootContact->id,
            $bitrix24ContactId,
            $result->failureReason ?? 'Bitrix24 stale contact repair failed.',
            $transportMethod,
            failureCode: $result->failureCode ?? Bitrix24MessageExport::FAILURE_STALE_CONTACT_REPAIR_FAILED,
            failureUncertain: false,
        );

        return true;
    }

    private function handleStaleOpenLineBindingRepairAfterFailedExport(
        Message $message,
        Dialog $dialog,
        Contact $rootContact,
        string $bitrix24ContactId,
        Bitrix24LiveExportTransportException $exception,
        ?string $transportMethod,
    ): bool {
        $guardFailure = $this->findOpenLineMutationGuardException($exception);

        if (! $guardFailure instanceof Bitrix24OpenLineMutationGuardException) {
            return false;
        }

        if (! $this->isRecoverableStaleOpenLineBindingGuardFailure($dialog, $guardFailure)) {
            return false;
        }

        $oldUserCode = $dialog->bitrix24_open_line_user_code_override;
        $oldResolvedChatId = $dialog->bitrix24_open_line_resolved_chat_id_override;

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => null,
            'bitrix24_open_line_resolved_chat_id_override' => null,
            'bitrix24_open_line_binding_verified_at' => null,
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_FAILED,
        ])->save();

        $this->markFailed(
            $message,
            $rootContact->id,
            $bitrix24ContactId,
            $exception->getMessage(),
            $transportMethod,
            failureCode: $exception->failureCode,
            failureUncertain: false,
        );

        $queueResult = $this->queueBitrix24LiveMessageExportAction->handle($message->fresh() ?? $message, retryAfterSync: true);

        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'open_line_binding_invalidated_after_guard_failure',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'message_id' => $message->id,
                'dialog_id' => $dialog->id,
                'contact_id' => $rootContact->id,
                'bitrix24_contact_id' => $bitrix24ContactId,
                'old_user_code' => $oldUserCode,
                'old_resolved_chat_id' => $oldResolvedChatId,
                'failure_code' => $exception->failureCode,
                'failure_reason' => $exception->getMessage(),
            ],
            responsePayload: [
                'queued' => $queueResult->queued,
                'already_pending' => $queueResult->alreadyPending,
            ],
            entityType: 'message',
            entityId: (string) $message->id,
        );

        return true;
    }

    private function isRecoverableStaleOpenLineBindingGuardFailure(
        Dialog $dialog,
        Bitrix24OpenLineMutationGuardException $exception,
    ): bool {
        if (
            $exception->failureCode !== Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED
            || $exception->failureUncertain
            || $dialog->bitrix24_open_line_binding_verified_at === null
            || blank($dialog->bitrix24_open_line_user_code_override)
            || blank($dialog->bitrix24_open_line_resolved_chat_id_override)
        ) {
            return false;
        }

        $message = Str::lower($exception->getMessage());

        return Str::contains($message, 'verified binding preflight failed')
            && Str::contains($message, [
                'is not active for contact',
                'is active, but connector',
            ]);
    }

    private function findContactNotFoundException(Throwable $throwable): ?Bitrix24ContactNotFoundException
    {
        do {
            if ($throwable instanceof Bitrix24ContactNotFoundException) {
                return $throwable;
            }

            $throwable = $throwable->getPrevious();
        } while ($throwable instanceof Throwable);

        return null;
    }

    private function findOpenLineMutationGuardException(Throwable $throwable): ?Bitrix24OpenLineMutationGuardException
    {
        do {
            if ($throwable instanceof Bitrix24OpenLineMutationGuardException) {
                return $throwable;
            }

            $throwable = $throwable->getPrevious();
        } while ($throwable instanceof Throwable);

        return null;
    }

    private function markRouteMisconfiguredOnInactiveLineFailure(
        ?Bitrix24OpenLinesRouteData $route,
        Dialog $dialog,
        Bitrix24LiveExportTransportException $exception,
    ): void {
        if (! $this->isInactiveOpenLineFailure($exception)) {
            return;
        }

        $routeId = $route?->routeId ?? $dialog->bitrix24_open_line_route_id;

        if ($routeId === null) {
            return;
        }

        $this->markRouteMisconfiguredAction->handle($routeId, $exception->getMessage());
    }

    private function isInactiveOpenLineFailure(Bitrix24LiveExportTransportException $exception): bool
    {
        if (
            $exception->failureCode !== Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED
            || $exception->failureUncertain
        ) {
            return false;
        }

        $message = Str::lower($exception->getMessage());

        return Str::contains($message, [
            'not_active_line',
            'inactive or does not exist',
            'неактивна',
            'не существует',
        ]);
    }

    private function markPending(Message $message, int $rootContactId, string $bitrix24ContactId, string $liveBatchUuid): void
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
                'live_batch_uuid' => $liveBatchUuid,
                'live_claim_uuid' => null,
                'live_claimed_at' => null,
                'live_claim_expires_at' => null,
                'transport_method' => null,
                'resolved_bitrix_chat_id' => null,
                'resolved_crm_entity_type' => null,
                'resolved_crm_entity_id' => null,
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

    private function claimLiveExport(
        Message $message,
        int $rootContactId,
        string $bitrix24ContactId,
        ?string $expectedLiveBatchUuid,
    ): ?Bitrix24MessageExport {
        $liveExport = Bitrix24MessageExport::query()
            ->where('message_id', $message->id)
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->first();

        if ($liveExport?->export_status === Bitrix24MessageExport::STATUS_EXPORTED) {
            return null;
        }

        if (
            $liveExport?->export_status === Bitrix24MessageExport::STATUS_FAILED
            && $liveExport->failure_uncertain
        ) {
            return null;
        }

        if ($liveExport?->export_status !== Bitrix24MessageExport::STATUS_PENDING) {
            $expectedLiveBatchUuid ??= (string) Str::uuid();
            $this->markPending($message, $rootContactId, $bitrix24ContactId, $expectedLiveBatchUuid);
            $liveExport = Bitrix24MessageExport::query()
                ->where('message_id', $message->id)
                ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
                ->first();
        }

        if (! $liveExport instanceof Bitrix24MessageExport) {
            return null;
        }

        if (blank($liveExport->live_batch_uuid)) {
            $expectedLiveBatchUuid ??= (string) Str::uuid();

            Bitrix24MessageExport::query()
                ->whereKey($liveExport->id)
                ->update([
                    'live_batch_uuid' => $expectedLiveBatchUuid,
                    'live_claim_uuid' => null,
                    'live_claimed_at' => null,
                    'live_claim_expires_at' => null,
                    'updated_at' => now(),
                ]);

            $liveExport->refresh();
        }

        if (
            filled($expectedLiveBatchUuid)
            && $liveExport->live_batch_uuid !== $expectedLiveBatchUuid
        ) {
            return null;
        }

        $claimUuid = (string) Str::uuid();
        $claimedAt = now();
        $claimExpiresAt = $claimedAt->copy()->addSeconds(self::LIVE_CLAIM_LEASE_SECONDS);

        $updated = Bitrix24MessageExport::query()
            ->whereKey($liveExport->id)
            ->where('export_status', Bitrix24MessageExport::STATUS_PENDING)
            ->when(
                filled($expectedLiveBatchUuid),
                fn ($query) => $query->where('live_batch_uuid', $expectedLiveBatchUuid),
            )
            ->where(function ($query) use ($claimedAt): void {
                $query->whereNull('live_claim_uuid')
                    ->orWhere('live_claim_expires_at', '<=', $claimedAt);
            })
            ->update([
                'live_claim_uuid' => $claimUuid,
                'live_claimed_at' => $claimedAt,
                'live_claim_expires_at' => $claimExpiresAt,
                'updated_at' => $claimedAt,
            ]);

        if ($updated !== 1) {
            return null;
        }

        return $liveExport->fresh();
    }

    /**
     * @param  array<string, mixed>  $responsePayload
     * @param  array<string, mixed>  $requestPayload
     */
    private function completeSuccessfulExport(
        Message $message,
        Dialog $dialog,
        int $rootContactId,
        string $bitrix24ContactId,
        string $connectorCode,
        string $lineId,
        ?int $routeId,
        bool $retryAfterSync,
        string $chatKey,
        string $operation,
        array $responsePayload,
        ?string $transportMethod,
        ?string $resolvedBitrixChatId = null,
        ?string $bitrixRemoteMessageId = null,
        ?string $resolvedCrmEntityType = null,
        ?string $resolvedCrmEntityId = null,
        array $requestPayload = [],
        bool $resolvedBitrixChatVerified = false,
    ): Message {
        $previousLiveStatus = $dialog->bitrix24_live_status;
        $fakeHappyPathEnabled = $this->fakeHappyPathEnabled();

        $this->markExported(
            $message,
            $dialog,
            $rootContactId,
            $bitrix24ContactId,
            $transportMethod,
            $resolvedBitrixChatId,
            $bitrixRemoteMessageId,
            $resolvedCrmEntityType,
            $resolvedCrmEntityId,
            $resolvedBitrixChatVerified,
        );

        $dialogUpdates = [
            'bitrix24_live_chat_id' => $chatKey,
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
            'bitrix24_live_last_exported_at' => now(),
        ];

        if ($dialog->bitrix24_open_line_route_id === null && $routeId !== null) {
            $dialogUpdates['bitrix24_open_line_route_id'] = $routeId;
        }

        $dialog->forceFill($dialogUpdates)->save();

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
            ] + $requestPayload,
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

    private function inboundClientFastPathEnabled(): bool
    {
        return (bool) config('bitrix24.features.fast_inbound_export_enabled', false);
    }

    private function shouldUseInboundClientFastPath(Message $message): bool
    {
        return $this->inboundClientFastPathEnabled()
            && $message->direction === Message::DIRECTION_INBOUND
            && $message->sent_by_type === Message::SENT_BY_TYPE_CONTACT
            && in_array($message->message_kind, [
                Message::KIND_INBOUND_USER,
                Message::KIND_INBOUND_CONTACT_SHARE,
            ], true);
    }

    private function shouldUseControlledConnectorMirrorManualReplyPath(Message $message, Bitrix24OpenLinesRouteData $route): bool
    {
        return $message->message_kind === Message::KIND_OUTBOUND_MANUAL_REPLY
            && $route->platform === Channel::PLATFORM_MAX;
    }

    private function shouldUseServiceActorManualReplyPath(Message $message, Bitrix24OpenLinesRouteData $route): bool
    {
        return false;
    }

    private function shouldFallbackToLegacyManualReplyTransport(
        Bitrix24OpenLinesManualReplyExportException $exception,
    ): bool {
        // Falling back to imconnector.send.messages after the service-actor path
        // already failed can create duplicate Bitrix24 IMOL users for one dialog.
        return false;
    }

    private function tryExportInboundClientFastPath(
        Message $message,
        Dialog $dialog,
        int $rootContactId,
        string $bitrix24ContactId,
        Bitrix24OpenLinesRouteData $route,
        bool $retryAfterSync,
    ): ?Message {
        $connection = $this->resolveCurrentConnectionAction->handle();
        $expectedResolvedBitrixChatId = $this->freshVerifiedBindingResolvedChatId($dialog, $route);

        if ($expectedResolvedBitrixChatId === null) {
            return null;
        }

        if (! $this->confirmFreshVerifiedBindingIsCurrentBeforeFastPath(
            $dialog,
            $route,
            $connection,
            $expectedResolvedBitrixChatId,
        )) {
            return null;
        }

        try {
            $this->guardOpenLineMutationAction->assertRuntimeAllowsRouteMutation($route);
        } catch (Bitrix24OpenLineMutationGuardException $exception) {
            throw new Bitrix24LiveExportTransportException(
                $exception->getMessage(),
                failureCode: $exception->failureCode,
                failureUncertain: $exception->failureUncertain,
                previous: $exception,
            );
        }

        $identityDecision = $this->resolveIdentityDecisionAction->handle($message, $route, $connection);

        $payload = $this->buildBitrix24OpenLinesMessagePayloadAction->handle(
            $message,
            $route,
            $retryAfterSync,
            false,
            $identityDecision->userId,
        );
        $payloadChatId = $this->nonEmptyScalarString(data_get($payload, 'MESSAGES.0.chat.id'));
        $payloadUserId = $this->nonEmptyScalarString(data_get($payload, 'MESSAGES.0.user.id'));
        $this->logIdentityDecision(
            message: $message,
            dialog: $dialog,
            rootContactId: $rootContactId,
            bitrix24ContactId: $bitrix24ContactId,
            route: $route,
            connection: $connection,
            identityDecision: $identityDecision,
            payloadChatId: $payloadChatId,
            payloadUserId: $payloadUserId,
            fastPath: true,
        );

        try {
            $response = $this->bitrix24ApiClient->call(
                'imconnector.send.messages',
                $payload,
                connection: $connection,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException $exception) {
            $this->logBitrix24ApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'openlines_live_export_fast_path_uncertain',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'message_id' => $message->id,
                    'dialog_id' => $dialog->id,
                    'contact_id' => $rootContactId,
                    'bitrix24_contact_id' => $bitrix24ContactId,
                    'payload_chat_id' => $payloadChatId,
                    'payload_user_id' => $payloadUserId,
                    'connector_code' => $route->connectorCode,
                    'line_id' => $route->lineId,
                    'retry_after_sync' => $retryAfterSync,
                ] + $this->identityDecisionLogPayload($identityDecision),
                connection: $connection,
                errorMessage: $exception->getMessage(),
                entityType: 'message',
                entityId: (string) $message->id,
            );

            throw new Bitrix24LiveExportTransportException(
                'Bitrix24 Open Lines fast-path transport outcome is uncertain.',
                failureCode: Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                failureUncertain: true,
                previous: $exception,
            );
        }

        if (! $response->successful) {
            if ($response->httpStatus !== null && $response->httpStatus < 500) {
                $this->logBitrix24ApiCallAction->handle(
                    direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                    operation: 'openlines_live_export_fast_path_fallback',
                    status: Bitrix24SyncLog::STATUS_SKIPPED,
                    requestPayload: [
                        'message_id' => $message->id,
                        'dialog_id' => $dialog->id,
                        'contact_id' => $rootContactId,
                        'bitrix24_contact_id' => $bitrix24ContactId,
                        'payload_chat_id' => $payloadChatId,
                        'payload_user_id' => $payloadUserId,
                        'connector_code' => $route->connectorCode,
                        'line_id' => $route->lineId,
                        'retry_after_sync' => $retryAfterSync,
                    ] + $this->identityDecisionLogPayload($identityDecision),
                    responsePayload: [
                        'result' => $response->result,
                        'rest_method' => $response->restMethod,
                        'fast_path' => true,
                    ],
                    connection: $connection,
                    httpStatus: $response->httpStatus,
                    errorCode: $response->errorCode,
                    errorMessage: $response->errorMessage,
                    entityType: 'message',
                    entityId: (string) $message->id,
                );

                return null;
            }

            $this->logBitrix24ApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'openlines_live_export_fast_path_uncertain',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'message_id' => $message->id,
                    'dialog_id' => $dialog->id,
                    'contact_id' => $rootContactId,
                    'bitrix24_contact_id' => $bitrix24ContactId,
                    'payload_chat_id' => $payloadChatId,
                    'payload_user_id' => $payloadUserId,
                    'connector_code' => $route->connectorCode,
                    'line_id' => $route->lineId,
                    'retry_after_sync' => $retryAfterSync,
                ] + $this->identityDecisionLogPayload($identityDecision),
                responsePayload: [
                    'result' => $response->result,
                    'rest_method' => $response->restMethod,
                    'fast_path' => true,
                ],
                connection: $connection,
                httpStatus: $response->httpStatus,
                errorCode: $response->errorCode,
                errorMessage: $response->errorMessage,
                entityType: 'message',
                entityId: (string) $message->id,
            );

            throw new Bitrix24LiveExportTransportException(
                $response->errorMessage ?? 'Bitrix24 Open Lines fast-path message export failed with uncertain status.',
                failureCode: Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                failureUncertain: true,
            );
        }

        $resolvedBitrixChatId = $this->extractLegacySessionChatId($response->result);
        $connectorUserId = $this->extractLegacyConnectorUserId($response->result);
        $bitrixRemoteMessageId = $this->extractLegacyRemoteMessageId($response->result);

        if ($resolvedBitrixChatId === null || $connectorUserId === null) {
            $messageText = 'Bitrix24 Open Lines fast-path response is missing session chat id or connector user id.';

            $this->logBitrix24ApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'openlines_live_export_fast_path_unexpected_response',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'message_id' => $message->id,
                    'dialog_id' => $dialog->id,
                    'contact_id' => $rootContactId,
                    'bitrix24_contact_id' => $bitrix24ContactId,
                    'payload_chat_id' => $payloadChatId,
                    'payload_user_id' => $payloadUserId,
                    'connector_code' => $route->connectorCode,
                    'line_id' => $route->lineId,
                    'retry_after_sync' => $retryAfterSync,
                ] + $this->identityDecisionLogPayload($identityDecision),
                responsePayload: [
                    'result' => $response->result,
                    'rest_method' => $response->restMethod,
                    'fast_path' => true,
                    'returned_session_chat_id' => $resolvedBitrixChatId,
                    'returned_connector_user_id' => $connectorUserId,
                ],
                connection: $connection,
                httpStatus: $response->httpStatus,
                errorMessage: $messageText,
                entityType: 'message',
                entityId: (string) $message->id,
            );

            throw new Bitrix24LiveExportTransportException(
                $messageText,
                failureCode: Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                failureUncertain: true,
                resolvedBitrixChatId: $this->positiveIntegerString($resolvedBitrixChatId),
                bitrixRemoteMessageId: $bitrixRemoteMessageId,
            );
        }

        $validatedReturnedChat = $this->resolveValidatedInboundClientReturnedChat(
            $dialog,
            $route,
            $connection,
            $payloadChatId,
            $resolvedBitrixChatId,
            $connectorUserId,
            $bitrixRemoteMessageId,
        );

        if ($validatedReturnedChat === null && $resolvedBitrixChatId !== $expectedResolvedBitrixChatId) {
            $messageText = sprintf(
                'Bitrix24 Open Lines fast-path returned unverified chat id [%s], expected [%s].',
                $resolvedBitrixChatId,
                $expectedResolvedBitrixChatId,
            );

            $this->logBitrix24ApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'openlines_live_export_fast_path_unverified_chat',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'message_id' => $message->id,
                    'dialog_id' => $dialog->id,
                    'contact_id' => $rootContactId,
                    'bitrix24_contact_id' => $bitrix24ContactId,
                    'payload_chat_id' => $payloadChatId,
                    'payload_user_id' => $payloadUserId,
                    'expected_current_chat_id' => $expectedResolvedBitrixChatId,
                    'connector_code' => $route->connectorCode,
                    'line_id' => $route->lineId,
                    'retry_after_sync' => $retryAfterSync,
                ] + $this->identityDecisionLogPayload($identityDecision),
                responsePayload: [
                    'result' => $response->result,
                    'rest_method' => $response->restMethod,
                    'fast_path' => true,
                    'returned_session_chat_id' => $resolvedBitrixChatId,
                    'returned_connector_user_id' => $connectorUserId,
                ],
                connection: $connection,
                httpStatus: $response->httpStatus,
                errorMessage: $messageText,
                entityType: 'message',
                entityId: (string) $message->id,
            );

            throw new Bitrix24LiveExportTransportException(
                $messageText,
                failureCode: Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                failureUncertain: true,
                resolvedBitrixChatId: $this->positiveIntegerString($resolvedBitrixChatId),
                bitrixRemoteMessageId: $bitrixRemoteMessageId,
            );
        }

        $bindingSynced = $validatedReturnedChat instanceof Bitrix24CurrentOpenLineChatData;

        if ($bindingSynced) {
            $this->syncVerifiedBindingToCurrentChat($dialog, $validatedReturnedChat);
        }

        $resolvedBitrixChatVerified = $bindingSynced
            || $resolvedBitrixChatId === $expectedResolvedBitrixChatId;

        return $this->completeSuccessfulExport(
            message: $message,
            dialog: $dialog,
            rootContactId: $rootContactId,
            bitrix24ContactId: $bitrix24ContactId,
            connectorCode: $route->connectorCode,
            lineId: $route->lineId,
            routeId: $route->routeId,
            retryAfterSync: $retryAfterSync,
            chatKey: $payloadChatId ?? $this->resolveExportChatKey($dialog, $route),
            operation: 'openlines_live_export_fast_path_exported',
            transportMethod: Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            resolvedBitrixChatId: $resolvedBitrixChatId,
            bitrixRemoteMessageId: $bitrixRemoteMessageId,
            responsePayload: [
                'result' => $response->result,
                'rest_method' => $response->restMethod,
                'fast_path' => true,
                'expected_current_chat_id' => $expectedResolvedBitrixChatId,
                'returned_session_chat_id' => $resolvedBitrixChatId,
                'returned_connector_user_id' => $connectorUserId,
                'returned_message_id' => $bitrixRemoteMessageId,
                'binding_synced_from_response' => $bindingSynced,
                'resolved_bitrix_chat_verified' => $resolvedBitrixChatVerified,
                'identity_mode' => $identityDecision->identityMode,
                'decision_reason' => $identityDecision->decisionReason,
            ],
            requestPayload: [
                'fast_path' => true,
                'payload_chat_id' => $payloadChatId,
                'payload_user_id' => $payloadUserId,
            ] + $this->identityDecisionLogPayload($identityDecision),
            resolvedBitrixChatVerified: $resolvedBitrixChatVerified,
        );
    }

    private function freshVerifiedBindingResolvedChatId(Dialog $dialog, Bitrix24OpenLinesRouteData $route): ?string
    {
        if (
            $dialog->bitrix24_open_line_binding_verified_at === null
            || $dialog->bitrix24_open_line_binding_verified_at->lt(
                now()->subSeconds(self::VERIFIED_BINDING_FAST_PATH_WINDOW_SECONDS)
            )
        ) {
            return null;
        }

        $binding = $this->resolveDialogBindingAction->handle($dialog, $route);
        $resolvedChatId = $this->positiveIntegerString($binding?->resolvedBitrixChatId);

        return $resolvedChatId === null ? null : $resolvedChatId;
    }

    private function confirmFreshVerifiedBindingIsCurrentBeforeFastPath(
        Dialog $dialog,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        string $expectedResolvedBitrixChatId,
    ): bool {
        try {
            $currentChat = $this->resolveCurrentOpenLineChatAction->handle(
                $dialog,
                $route,
                $connection,
            );
        } catch (Bitrix24ContactNotFoundException $exception) {
            throw new Bitrix24LiveExportTransportException(
                'Bitrix24 Open Lines fast-path current chat lookup found a stale CRM contact binding before mutating export.',
                failureCode: Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
                failureUncertain: false,
                previous: $exception,
            );
        } catch (Bitrix24ApiException) {
            return false;
        }

        if (! $currentChat instanceof Bitrix24CurrentOpenLineChatData) {
            return false;
        }

        if ($currentChat->chatId !== $expectedResolvedBitrixChatId) {
            $this->syncVerifiedBindingToCurrentChat($dialog, $currentChat);

            return false;
        }

        return true;
    }

    private function resolveValidatedInboundClientReturnedChat(
        Dialog $dialog,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        ?string $payloadChatId,
        ?string $resolvedBitrixChatId,
        ?string $connectorUserId,
        ?string $bitrixRemoteMessageId = null,
    ): ?Bitrix24CurrentOpenLineChatData {
        if ($payloadChatId === null || $resolvedBitrixChatId === null || $connectorUserId === null) {
            return null;
        }

        try {
            $currentChat = $this->resolveCurrentOpenLineChatAction->handleMatchingChatId(
                $dialog,
                $route,
                $connection,
                $resolvedBitrixChatId,
            );
        } catch (Bitrix24ApiException $exception) {
            throw new Bitrix24LiveExportTransportException(
                'Bitrix24 Open Lines returned chat validation failed after inbound client export.',
                failureCode: Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                failureUncertain: true,
                resolvedBitrixChatId: $this->positiveIntegerString($resolvedBitrixChatId),
                bitrixRemoteMessageId: $bitrixRemoteMessageId,
                previous: $exception,
            );
        }

        if (
            ! $currentChat instanceof Bitrix24CurrentOpenLineChatData
            || $currentChat->chatId !== $resolvedBitrixChatId
        ) {
            return null;
        }

        $binding = $this->resolveDialogBindingAction->parseUserCode($currentChat->userCode);

        if (
            $binding === null
            || $binding->connectorChatId !== $payloadChatId
            || $binding->connectorUserId !== $connectorUserId
        ) {
            return null;
        }

        return $currentChat;
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
        ?int $routeId,
        bool $retryAfterSync,
        ?string $retryAfterSyncReason,
        string $operation,
        ?Bitrix24Connection $connection = null,
        bool $applyLegacyFallbackSignature = false,
        ?string $expectedResolvedBitrixChatId = null,
        bool $requireExpectedResolvedBitrixChatId = false,
        bool $allowPostSendBindingResync = true,
        array $responsePayload = [],
    ): Message {
        $route = new Bitrix24OpenLinesRouteData(
            platform: $dialog->channel()->firstOrFail()->platform,
            connectorCode: $connectorCode,
            lineId: $lineId,
            routeId: $routeId,
        );
        $connection ??= $this->resolveCurrentConnectionAction->handle();
        $dialogBinding = $this->resolveDialogBindingAction->handle($dialog, $route);
        $expectedResolvedBitrixChatId ??= $dialogBinding?->resolvedBitrixChatId;

        if ($dialogBinding !== null && $expectedResolvedBitrixChatId === null) {
            throw new Bitrix24LiveExportTransportException(
                'Bitrix24 Open Lines verified dialog binding is missing resolved chat id.',
                failureCode: Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
            );
        }

        $rootContact = Contact::query()->findOrFail($rootContactId);

        try {
            if (
                $dialogBinding === null
                && ($requireExpectedResolvedBitrixChatId || $this->hasLegacyOpenLineExportHistory($dialog))
                && $this->syncMissingBindingToCurrentChatBeforeSend($dialog, $route, $connection)
            ) {
                $dialog->refresh();
                $dialogBinding = $this->resolveDialogBindingAction->handle($dialog, $route);
                $expectedResolvedBitrixChatId = $dialogBinding?->resolvedBitrixChatId;
            }

            $this->guardOpenLineMutationAction->handle(
                $dialog,
                $rootContact,
                $route,
                $connection,
            );

            if ($expectedResolvedBitrixChatId !== null) {
                try {
                    $this->guardOpenLineMutationAction->assertVerifiedBindingChatIsActiveForContact(
                        $dialog,
                        $rootContact,
                        $route,
                        $connection,
                        $expectedResolvedBitrixChatId,
                        $dialogBinding?->userCode,
                    );
                } catch (Bitrix24OpenLineMutationGuardException $exception) {
                    if (! $this->syncVerifiedBindingToCurrentChatBeforeSend($dialog, $route, $connection, $expectedResolvedBitrixChatId, $exception)) {
                        throw $exception;
                    }

                    $dialog->refresh();
                    $dialogBinding = $this->resolveDialogBindingAction->handle($dialog, $route);
                    $expectedResolvedBitrixChatId = $dialogBinding?->resolvedBitrixChatId;

                    if ($expectedResolvedBitrixChatId === null) {
                        throw $exception;
                    }

                    $this->guardOpenLineMutationAction->assertVerifiedBindingChatIsActiveForContact(
                        $dialog,
                        $rootContact,
                        $route,
                        $connection,
                        $expectedResolvedBitrixChatId,
                        $dialogBinding?->userCode,
                    );
                }
            }
        } catch (Bitrix24OpenLineMutationGuardException $exception) {
            throw new Bitrix24LiveExportTransportException(
                $exception->getMessage(),
                failureCode: $exception->failureCode,
                failureUncertain: $exception->failureUncertain,
                previous: $exception,
            );
        }

        if ($requireExpectedResolvedBitrixChatId && $expectedResolvedBitrixChatId === null) {
            throw new Bitrix24LiveExportTransportException(
                'Bitrix24 Open Lines controlled manual reply connector mirror requires a confirmed current chat id before mutating export.',
                failureCode: Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
            );
        }

        $identityDecision = $this->resolveIdentityDecisionAction->handle($message, $route, $connection);
        $payload = $this->buildBitrix24OpenLinesMessagePayloadAction->handle(
            $message,
            $route,
            $retryAfterSync,
            $applyLegacyFallbackSignature,
            $identityDecision->userId,
        );
        $payloadChatId = $this->nonEmptyScalarString(data_get($payload, 'MESSAGES.0.chat.id'));
        $payloadUserId = $this->nonEmptyScalarString(data_get($payload, 'MESSAGES.0.user.id'));
        $this->logIdentityDecision(
            message: $message,
            dialog: $dialog,
            rootContactId: $rootContactId,
            bitrix24ContactId: $bitrix24ContactId,
            route: $route,
            connection: $connection,
            identityDecision: $identityDecision,
            payloadChatId: $payloadChatId,
            payloadUserId: $payloadUserId,
        );

        try {
            $response = $this->bitrix24ApiClient->call(
                'imconnector.send.messages',
                $payload,
                connection: $connection,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException $exception) {
            $this->logIdentityUncertain(
                message: $message,
                dialog: $dialog,
                rootContactId: $rootContactId,
                bitrix24ContactId: $bitrix24ContactId,
                route: $route,
                connection: $connection,
                identityDecision: $identityDecision,
                payloadChatId: $payloadChatId,
                payloadUserId: $payloadUserId,
                expectedResolvedBitrixChatId: $expectedResolvedBitrixChatId,
                returnedResolvedBitrixChatId: null,
                errorMessage: $exception->getMessage(),
            );

            throw new Bitrix24LiveExportTransportException(
                'Bitrix24 Open Lines live export transport outcome is uncertain.',
                failureCode: Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                failureUncertain: true,
                previous: $exception,
            );
        }

        if (! $response->successful) {
            $failureCode = $response->httpStatus >= 500
                ? Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN
                : Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED;
            $failureUncertain = $failureCode === Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN;

            if ($failureUncertain) {
                $this->logIdentityUncertain(
                    message: $message,
                    dialog: $dialog,
                    rootContactId: $rootContactId,
                    bitrix24ContactId: $bitrix24ContactId,
                    route: $route,
                    connection: $connection,
                    identityDecision: $identityDecision,
                    payloadChatId: $payloadChatId,
                    payloadUserId: $payloadUserId,
                    expectedResolvedBitrixChatId: $expectedResolvedBitrixChatId,
                    returnedResolvedBitrixChatId: null,
                    errorMessage: $response->errorMessage ?? 'Bitrix24 Open Lines message export failed with uncertain status.',
                    responsePayload: [
                        'result' => $response->result,
                        'rest_method' => $response->restMethod,
                    ],
                    httpStatus: $response->httpStatus,
                    errorCode: $response->errorCode,
                );
            }

            throw new Bitrix24LiveExportTransportException(
                $response->errorMessage ?? 'Bitrix24 Open Lines message export failed.',
                failureCode: $failureCode,
                failureUncertain: $failureUncertain,
            );
        }

        $resolvedBitrixChatId = $this->extractLegacySessionChatId($response->result);
        $returnedResolvedBitrixChatId = $resolvedBitrixChatId;
        $connectorUserId = $this->extractLegacyConnectorUserId($response->result);
        $bitrixRemoteMessageId = $this->extractLegacyRemoteMessageId($response->result);
        $validatedReturnedChat = $this->shouldSyncInboundClientBindingFromResponse($message, $operation)
            ? $this->resolveValidatedInboundClientReturnedChat(
                $dialog,
                $route,
                $connection,
                $payloadChatId,
                $resolvedBitrixChatId,
                $connectorUserId,
                $bitrixRemoteMessageId,
            )
            : null;
        $bindingSyncedFromResponse = $validatedReturnedChat instanceof Bitrix24CurrentOpenLineChatData;
        $bindingResyncedAfterChatMismatch = false;
        $bindingValidatedAfterRepair = false;

        if ($bindingSyncedFromResponse) {
            $this->syncVerifiedBindingToCurrentChat($dialog, $validatedReturnedChat);

            if ($expectedResolvedBitrixChatId !== null && $resolvedBitrixChatId !== $expectedResolvedBitrixChatId) {
                $this->logIdentityMigrationRebound(
                    message: $message,
                    dialog: $dialog->fresh() ?? $dialog,
                    rootContactId: $rootContactId,
                    bitrix24ContactId: $bitrix24ContactId,
                    route: $route,
                    connection: $connection,
                    identityDecision: $identityDecision,
                    payloadChatId: $payloadChatId,
                    payloadUserId: $payloadUserId,
                    expectedResolvedBitrixChatId: $expectedResolvedBitrixChatId,
                    returnedResolvedBitrixChatId: $resolvedBitrixChatId,
                    returnedConnectorUserId: $connectorUserId,
                    bitrixRemoteMessageId: $bitrixRemoteMessageId,
                );
            }
        }

        $postRepairValidatedChat = $this->validateRetryAfterSyncInboundCrmBinding(
            message: $message,
            dialog: $dialog,
            rootContact: $rootContact,
            route: $route,
            connection: $connection,
            operation: $operation,
            retryAfterSync: $retryAfterSync,
            retryAfterSyncReason: $retryAfterSyncReason,
            alreadyValidatedChat: $validatedReturnedChat,
            payloadChatId: $payloadChatId,
            returnedResolvedBitrixChatId: $returnedResolvedBitrixChatId,
            returnedConnectorUserId: $connectorUserId,
            bitrixRemoteMessageId: $bitrixRemoteMessageId,
        );

        if ($postRepairValidatedChat instanceof Bitrix24CurrentOpenLineChatData) {
            $bindingValidatedAfterRepair = true;
            $resolvedBitrixChatId = $postRepairValidatedChat->chatId;
        }

        $resolvedBitrixChatVerified = $bindingSyncedFromResponse
            || $bindingValidatedAfterRepair
            || (
                $expectedResolvedBitrixChatId !== null
                && $resolvedBitrixChatId === $expectedResolvedBitrixChatId
            );

        if (
            $expectedResolvedBitrixChatId !== null
            && $resolvedBitrixChatId !== $expectedResolvedBitrixChatId
            && ! $bindingSyncedFromResponse
            && ! $bindingValidatedAfterRepair
        ) {
            if (
                ! $allowPostSendBindingResync
                || ! $this->syncVerifiedBindingToCurrentChatAfterMismatch(
                    $dialog,
                    $route,
                    $connection,
                    $resolvedBitrixChatId,
                    $bitrixRemoteMessageId,
                )
            ) {
                $this->logIdentityUncertain(
                    message: $message,
                    dialog: $dialog,
                    rootContactId: $rootContactId,
                    bitrix24ContactId: $bitrix24ContactId,
                    route: $route,
                    connection: $connection,
                    identityDecision: $identityDecision,
                    payloadChatId: $payloadChatId,
                    payloadUserId: $payloadUserId,
                    expectedResolvedBitrixChatId: $expectedResolvedBitrixChatId,
                    returnedResolvedBitrixChatId: $resolvedBitrixChatId,
                    returnedConnectorUserId: $connectorUserId,
                    bitrixRemoteMessageId: $bitrixRemoteMessageId,
                    errorMessage: sprintf(
                        'Bitrix24 Open Lines verified binding legacy export returned unexpected chat id [%s], expected [%s].',
                        $resolvedBitrixChatId ?? 'null',
                        $expectedResolvedBitrixChatId,
                    ),
                    responsePayload: [
                        'result' => $response->result,
                        'rest_method' => $response->restMethod,
                    ],
                    httpStatus: $response->httpStatus,
                );

                throw new Bitrix24LiveExportTransportException(
                    sprintf(
                        'Bitrix24 Open Lines verified binding legacy export returned unexpected chat id [%s], expected [%s].',
                        $resolvedBitrixChatId ?? 'null',
                        $expectedResolvedBitrixChatId,
                    ),
                    failureCode: Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                    failureUncertain: true,
                    resolvedBitrixChatId: $this->positiveIntegerString($resolvedBitrixChatId),
                    bitrixRemoteMessageId: $bitrixRemoteMessageId,
                );
            }

            $bindingResyncedAfterChatMismatch = true;
            $resolvedBitrixChatVerified = true;
            $this->logIdentityMigrationRebound(
                message: $message,
                dialog: $dialog->fresh() ?? $dialog,
                rootContactId: $rootContactId,
                bitrix24ContactId: $bitrix24ContactId,
                route: $route,
                connection: $connection,
                identityDecision: $identityDecision,
                payloadChatId: $payloadChatId,
                payloadUserId: $payloadUserId,
                expectedResolvedBitrixChatId: $expectedResolvedBitrixChatId,
                returnedResolvedBitrixChatId: $resolvedBitrixChatId,
                returnedConnectorUserId: $connectorUserId,
                bitrixRemoteMessageId: $bitrixRemoteMessageId,
            );
        }

        return $this->completeSuccessfulExport(
            message: $message,
            dialog: $dialog,
            rootContactId: $rootContactId,
            bitrix24ContactId: $bitrix24ContactId,
            connectorCode: $connectorCode,
            lineId: $lineId,
            routeId: $routeId,
            retryAfterSync: $retryAfterSync,
            chatKey: $this->resolveExportChatKey($dialog, $route),
            operation: $operation,
            transportMethod: Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            resolvedBitrixChatId: $resolvedBitrixChatId,
            bitrixRemoteMessageId: $bitrixRemoteMessageId,
            responsePayload: $responsePayload + [
                'result' => $response->result,
                'rest_method' => $response->restMethod,
                'verified_binding_resynced_after_chat_mismatch' => $bindingResyncedAfterChatMismatch,
                'returned_session_chat_id' => $returnedResolvedBitrixChatId,
                'returned_connector_user_id' => $connectorUserId,
                'returned_message_id' => $bitrixRemoteMessageId,
                'inbound_client_binding_synced_from_response' => $bindingSyncedFromResponse,
                'post_repair_crm_binding_validated' => $bindingValidatedAfterRepair,
                'resolved_bitrix_chat_verified' => $resolvedBitrixChatVerified,
                'identity_mode' => $identityDecision->identityMode,
                'decision_reason' => $identityDecision->decisionReason,
            ] + $this->resolveLegacyExportAuditResponsePayload($operation, $resolvedBitrixChatId),
            requestPayload: $this->resolveLegacyExportAuditRequestPayload(
                $operation,
                $payloadChatId,
                $expectedResolvedBitrixChatId,
            ) + [
                'payload_user_id' => $payloadUserId,
            ] + $this->identityDecisionLogPayload($identityDecision),
            resolvedBitrixChatVerified: $resolvedBitrixChatVerified,
        );
    }

    private function logIdentityDecision(
        Message $message,
        Dialog $dialog,
        int $rootContactId,
        string $bitrix24ContactId,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        Bitrix24OpenLinesIdentityDecisionData $identityDecision,
        ?string $payloadChatId,
        ?string $payloadUserId,
        bool $fastPath = false,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'identity_mode_selected',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'message_id' => $message->id,
                'dialog_id' => $dialog->id,
                'contact_id' => $rootContactId,
                'bitrix24_contact_id' => $bitrix24ContactId,
                'connector_code' => $route->connectorCode,
                'line_id' => $route->lineId,
                'payload_chat_id' => $payloadChatId,
                'payload_user_id' => $payloadUserId,
                'fast_path' => $fastPath,
                'diagnostic_event_type' => 'identity_mode_selected',
            ] + $this->identityDecisionLogPayload($identityDecision),
            responsePayload: [
                'diagnostic_event_type' => 'identity_mode_selected',
                'identity_mode' => $identityDecision->identityMode,
                'decision_reason' => $identityDecision->decisionReason,
            ],
            connection: $connection,
            entityType: 'message',
            entityId: (string) $message->id,
        );
    }

    /**
     * @param  array<string, mixed>|null  $responsePayload
     */
    private function logIdentityUncertain(
        Message $message,
        Dialog $dialog,
        int $rootContactId,
        string $bitrix24ContactId,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        Bitrix24OpenLinesIdentityDecisionData $identityDecision,
        ?string $payloadChatId,
        ?string $payloadUserId,
        ?string $expectedResolvedBitrixChatId,
        ?string $returnedResolvedBitrixChatId,
        ?string $errorMessage,
        ?string $returnedConnectorUserId = null,
        ?string $bitrixRemoteMessageId = null,
        ?array $responsePayload = null,
        ?int $httpStatus = null,
        ?string $errorCode = null,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'identity_uncertain',
            status: Bitrix24SyncLog::STATUS_FAILED,
            requestPayload: [
                'message_id' => $message->id,
                'dialog_id' => $dialog->id,
                'contact_id' => $rootContactId,
                'bitrix24_contact_id' => $bitrix24ContactId,
                'connector_code' => $route->connectorCode,
                'line_id' => $route->lineId,
                'payload_chat_id' => $payloadChatId,
                'payload_user_id' => $payloadUserId,
                'expected_current_chat_id' => $expectedResolvedBitrixChatId,
                'returned_session_chat_id' => $returnedResolvedBitrixChatId,
                'returned_connector_user_id' => $returnedConnectorUserId,
                'returned_message_id' => $bitrixRemoteMessageId,
                'diagnostic_event_type' => 'identity_uncertain',
            ] + $this->identityDecisionLogPayload($identityDecision),
            responsePayload: [
                'diagnostic_event_type' => 'identity_uncertain',
                'human_status' => 'Возможно доставлено, автоматический повтор не выполняется',
            ] + ($responsePayload ?? []),
            connection: $connection,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            entityType: 'message',
            entityId: (string) $message->id,
        );
    }

    private function logIdentityMigrationRebound(
        Message $message,
        Dialog $dialog,
        int $rootContactId,
        string $bitrix24ContactId,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        Bitrix24OpenLinesIdentityDecisionData $identityDecision,
        ?string $payloadChatId,
        ?string $payloadUserId,
        ?string $expectedResolvedBitrixChatId,
        ?string $returnedResolvedBitrixChatId,
        ?string $returnedConnectorUserId,
        ?string $bitrixRemoteMessageId,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'identity_migration_rebound',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'message_id' => $message->id,
                'dialog_id' => $dialog->id,
                'contact_id' => $rootContactId,
                'bitrix24_contact_id' => $bitrix24ContactId,
                'connector_code' => $route->connectorCode,
                'line_id' => $route->lineId,
                'payload_chat_id' => $payloadChatId,
                'payload_user_id' => $payloadUserId,
                'expected_current_chat_id' => $expectedResolvedBitrixChatId,
                'returned_session_chat_id' => $returnedResolvedBitrixChatId,
                'returned_connector_user_id' => $returnedConnectorUserId,
                'returned_message_id' => $bitrixRemoteMessageId,
                'diagnostic_event_type' => 'identity_migration_rebound',
            ] + $this->identityDecisionLogPayload($identityDecision),
            responsePayload: [
                'diagnostic_event_type' => 'identity_migration_rebound',
                'selected_user_code' => $dialog->bitrix24_open_line_user_code_override,
                'selected_chat_id' => $dialog->bitrix24_open_line_resolved_chat_id_override,
            ],
            connection: $connection,
            entityType: 'message',
            entityId: (string) $message->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function identityDecisionLogPayload(Bitrix24OpenLinesIdentityDecisionData $identityDecision): array
    {
        return [
            'identity_mode' => $identityDecision->identityMode,
            'decision_reason' => $identityDecision->decisionReason,
            'channel_aware_user_id' => $identityDecision->channelAwareUserId,
            'legacy_external_user_id' => $identityDecision->legacyExternalUserId,
            'selected_user_code' => $identityDecision->selectedUserCode,
            'selected_chat_id' => $identityDecision->selectedChatId,
            'selected_payload_chat_id' => $identityDecision->payloadChatId,
            'legacy_candidate_count' => $identityDecision->legacyCandidateCount,
        ];
    }

    private function validateRetryAfterSyncInboundCrmBinding(
        Message $message,
        Dialog $dialog,
        Contact $rootContact,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        string $operation,
        bool $retryAfterSync,
        ?string $retryAfterSyncReason,
        ?Bitrix24CurrentOpenLineChatData $alreadyValidatedChat,
        ?string $payloadChatId,
        ?string $returnedResolvedBitrixChatId,
        ?string $returnedConnectorUserId,
        ?string $bitrixRemoteMessageId,
    ): ?Bitrix24CurrentOpenLineChatData {
        if (
            ! $retryAfterSync
            || $retryAfterSyncReason !== Bitrix24MessageExport::RETRY_AFTER_SYNC_REASON_STALE_CONTACT_REPAIR
            || ! $this->shouldSyncInboundClientBindingFromResponse($message, $operation)
        ) {
            return null;
        }

        if ($alreadyValidatedChat instanceof Bitrix24CurrentOpenLineChatData) {
            return $alreadyValidatedChat;
        }

        try {
            $hasCompleteReturnedChatIdentity = $returnedResolvedBitrixChatId !== null
                && $payloadChatId !== null
                && $returnedConnectorUserId !== null;
            $currentChat = $hasCompleteReturnedChatIdentity
                ? $this->resolveValidatedInboundClientReturnedChat(
                    $dialog,
                    $route,
                    $connection,
                    $payloadChatId,
                    $returnedResolvedBitrixChatId,
                    $returnedConnectorUserId,
                    $bitrixRemoteMessageId,
                )
                : $this->resolveCurrentOpenLineChatAction->handle($dialog, $route, $connection);
        } catch (Bitrix24LiveExportTransportException $exception) {
            throw $exception;
        } catch (Bitrix24ApiException $exception) {
            throw new Bitrix24LiveExportTransportException(
                'Bitrix24 Open Lines post-repair CRM binding lookup outcome is uncertain.',
                failureCode: Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                failureUncertain: true,
                resolvedBitrixChatId: $this->positiveIntegerString($returnedResolvedBitrixChatId),
                bitrixRemoteMessageId: $bitrixRemoteMessageId,
                previous: $exception,
            );
        }

        if ($currentChat instanceof Bitrix24CurrentOpenLineChatData) {
            $returnedChatId = $this->positiveIntegerString($returnedResolvedBitrixChatId);

            if ($returnedChatId === null || $currentChat->chatId === $returnedChatId) {
                $this->syncVerifiedBindingToCurrentChat($dialog, $currentChat);

                return $currentChat;
            }
        }

        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: Bitrix24MessageExport::FAILURE_OPEN_LINE_CRM_BINDING_MISSING_AFTER_REPAIR,
            status: Bitrix24SyncLog::STATUS_FAILED,
            requestPayload: [
                'message_id' => $message->id,
                'dialog_id' => $dialog->id,
                'contact_id' => $rootContact->id,
                'expected_bitrix24_contact_id' => (string) $rootContact->bitrix24_contact_id,
                'expected_bitrix24_deal_id' => filled($rootContact->bitrix24_deal_id)
                    ? (string) $rootContact->bitrix24_deal_id
                    : null,
                'connector_code' => $route->connectorCode,
                'line_id' => $route->lineId,
                'retry_after_sync' => true,
                'returned_resolved_bitrix_chat_id' => $returnedResolvedBitrixChatId,
                'returned_connector_user_id' => $returnedConnectorUserId,
            ],
            responsePayload: [
                'current_contact_bound_chat_found' => false,
            ],
            connection: $connection,
            errorCode: Bitrix24MessageExport::FAILURE_OPEN_LINE_CRM_BINDING_MISSING_AFTER_REPAIR,
            errorMessage: 'Bitrix24 Open Lines retry export was accepted, but the current Open Line CRM binding was not confirmed for the expected contact.',
            entityType: 'message',
            entityId: (string) $message->id,
        );

        throw new Bitrix24LiveExportTransportException(
            'Bitrix24 Open Lines retry export was accepted, but the current Open Line CRM binding was not confirmed for the expected contact.',
            failureCode: Bitrix24MessageExport::FAILURE_OPEN_LINE_CRM_BINDING_MISSING_AFTER_REPAIR,
            failureUncertain: true,
            resolvedBitrixChatId: $this->positiveIntegerString($returnedResolvedBitrixChatId),
            bitrixRemoteMessageId: $bitrixRemoteMessageId,
        );
    }

    private function shouldSyncInboundClientBindingFromResponse(Message $message, string $operation): bool
    {
        return $operation === 'openlines_live_exported'
            && $message->direction === Message::DIRECTION_INBOUND
            && $message->sent_by_type === Message::SENT_BY_TYPE_CONTACT
            && in_array($message->message_kind, [
                Message::KIND_INBOUND_USER,
                Message::KIND_INBOUND_CONTACT_SHARE,
            ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLegacyExportAuditRequestPayload(
        string $operation,
        ?string $payloadChatId,
        ?string $expectedResolvedBitrixChatId,
    ): array {
        if ($operation !== 'openlines_manual_reply_exported_connector_mirror') {
            return [];
        }

        return [
            'payload_chat_id' => $payloadChatId,
            'expected_current_chat_id' => $expectedResolvedBitrixChatId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLegacyExportAuditResponsePayload(string $operation, ?string $resolvedBitrixChatId): array
    {
        if ($operation !== 'openlines_manual_reply_exported_connector_mirror') {
            return [];
        }

        return [
            'returned_session_chat_id' => $resolvedBitrixChatId,
        ];
    }

    private function syncMissingBindingToCurrentChatBeforeSend(
        Dialog $dialog,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
    ): bool {
        try {
            $currentChat = $this->resolveCurrentOpenLineChatAction->handle($dialog, $route, $connection);
        } catch (Bitrix24ApiException $exception) {
            throw new Bitrix24OpenLineMutationGuardException(
                'Bitrix24 Open Lines missing binding current chat lookup failed before mutating export.',
                Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
                false,
                $exception,
            );
        }

        if (! $currentChat instanceof Bitrix24CurrentOpenLineChatData) {
            return false;
        }

        $this->syncVerifiedBindingToCurrentChat($dialog, $currentChat);

        return true;
    }

    private function syncVerifiedBindingToCurrentChatBeforeSend(
        Dialog $dialog,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        string $expectedResolvedBitrixChatId,
        Bitrix24OpenLineMutationGuardException $exception,
    ): bool {
        if (! $this->isStaleVerifiedBindingGuardFailure($exception)) {
            return false;
        }

        $activeChatId = $this->positiveIntegerString($exception->relatedChatId);

        if ($activeChatId === null) {
            return false;
        }

        try {
            $currentChat = $this->resolveCurrentOpenLineChatAction->handleMatchingChatId(
                $dialog,
                $route,
                $connection,
                $activeChatId,
            ) ?? $this->resolveCurrentOpenLineChatAction->handle($dialog, $route, $connection);
        } catch (Bitrix24ApiException $lookupException) {
            throw new Bitrix24OpenLineMutationGuardException(
                'Bitrix24 Open Lines verified binding current chat lookup failed before mutating export.',
                Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
                false,
                $lookupException,
            );
        }

        if (
            $currentChat === null
            || $currentChat->chatId !== $activeChatId
            || $currentChat->chatId === $expectedResolvedBitrixChatId
        ) {
            return false;
        }

        $this->syncVerifiedBindingToCurrentChat($dialog, $currentChat);

        return true;
    }

    private function isStaleVerifiedBindingGuardFailure(Bitrix24OpenLineMutationGuardException $exception): bool
    {
        return $exception->failureCode === Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED
            && $exception->relatedChatId !== null;
    }

    private function syncVerifiedBindingToCurrentChatAfterMismatch(
        Dialog $dialog,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        ?string $resolvedBitrixChatId,
        ?string $bitrixRemoteMessageId = null,
    ): bool {
        if ($this->positiveIntegerString($resolvedBitrixChatId) === null) {
            return false;
        }

        try {
            $currentChat = $this->resolveCurrentOpenLineChatAction->handleMatchingChatId(
                $dialog,
                $route,
                $connection,
                $resolvedBitrixChatId,
            );
        } catch (Bitrix24ApiException $exception) {
            throw new Bitrix24LiveExportTransportException(
                'Bitrix24 Open Lines verified binding post-send lookup outcome is uncertain.',
                failureCode: Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                failureUncertain: true,
                resolvedBitrixChatId: $this->positiveIntegerString($resolvedBitrixChatId),
                bitrixRemoteMessageId: $bitrixRemoteMessageId,
                previous: $exception,
            );
        }

        if ($currentChat === null || $currentChat->chatId !== $resolvedBitrixChatId) {
            return false;
        }

        $this->syncVerifiedBindingToCurrentChat($dialog, $currentChat);

        return true;
    }

    private function syncVerifiedBindingToCurrentChat(Dialog $dialog, Bitrix24CurrentOpenLineChatData $currentChat): void
    {
        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $currentChat->userCode,
            'bitrix24_open_line_resolved_chat_id_override' => $currentChat->chatId,
            'bitrix24_open_line_binding_verified_at' => now(),
        ])->save();
    }

    private function hasLegacyOpenLineExportHistory(Dialog $dialog): bool
    {
        return Bitrix24MessageExport::query()
            ->join('messages', 'messages.id', '=', 'bitrix24_message_exports.message_id')
            ->where('messages.dialog_id', $dialog->id)
            ->where('bitrix24_message_exports.export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->where('bitrix24_message_exports.export_status', Bitrix24MessageExport::STATUS_EXPORTED)
            ->where(function ($query): void {
                $query->where('bitrix24_message_exports.transport_method', Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES)
                    ->orWhereNull('bitrix24_message_exports.transport_method');
            })
            ->exists();
    }

    private function resolveExpectedLegacyFallbackChatId(
        Dialog $dialog,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24OpenLinesManualReplyExportException $exception,
    ): ?string {
        if ($exception->failureCode !== Bitrix24MessageExport::FAILURE_VERIFIED_BINDING_CRM_MESSAGE_ADD_UNAVAILABLE) {
            return null;
        }

        $binding = $this->resolveDialogBindingAction->handle($dialog, $route);

        return $binding?->resolvedBitrixChatId;
    }

    private function resolveExportChatKey(Dialog $dialog, Bitrix24OpenLinesRouteData $route): string
    {
        if ($binding = $this->resolveDialogBindingAction->handle($dialog, $route)) {
            return $binding->connectorChatId;
        }

        return filled($dialog->bitrix24_live_chat_id)
            ? (string) $dialog->bitrix24_live_chat_id
            : $this->resolveBitrix24LiveChatKeyAction->handle($dialog);
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

    private function extractLegacyConnectorUserId(mixed $result): ?string
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

            $userId = data_get($item, 'user');

            if (is_scalar($userId) && trim((string) $userId) !== '') {
                return trim((string) $userId);
            }
        }

        return null;
    }

    private function extractLegacyRemoteMessageId(mixed $result): ?string
    {
        if (! is_array($result)) {
            return null;
        }

        $items = data_get($result, 'DATA.RESULT');

        if (! is_array($items)) {
            return null;
        }

        $paths = [
            'message.MESSAGE_ID',
            'message.message_id',
            'message.messageId',
            'MESSAGE_ID',
            'message_id',
            'messageId',
        ];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach ($paths as $path) {
                $messageId = data_get($item, $path);

                if (is_scalar($messageId) && trim((string) $messageId) !== '') {
                    return trim((string) $messageId);
                }
            }
        }

        return null;
    }

    private function positiveIntegerString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '' || ! ctype_digit($normalized) || (int) $normalized <= 0) {
            return null;
        }

        return $normalized;
    }

    private function nonEmptyScalarString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function shouldApplyLegacyFallbackSignature(Message $message): bool
    {
        return in_array($message->message_kind, [
            Message::KIND_OUTBOUND_MANUAL_REPLY,
            Message::KIND_OUTBOUND_AUTO_REPLY,
            Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
        ], true);
    }

    private function markExported(
        Message $message,
        Dialog $dialog,
        int $rootContactId,
        string $bitrix24ContactId,
        ?string $transportMethod,
        ?string $resolvedBitrixChatId,
        ?string $bitrixRemoteMessageId,
        ?string $resolvedCrmEntityType,
        ?string $resolvedCrmEntityId,
        bool $resolvedBitrixChatVerified,
    ): void {
        [$resolvedCrmEntityType, $resolvedCrmEntityId] = $this->resolvePersistedCrmBinding(
            $dialog,
            $resolvedBitrixChatId,
            $resolvedCrmEntityType,
            $resolvedCrmEntityId,
        );

        Bitrix24MessageExport::query()
            ->where('message_id', $message->id)
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->update([
                'contact_id' => $rootContactId,
                'bitrix24_contact_id' => $bitrix24ContactId,
                'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
                'transport_method' => $transportMethod,
                'resolved_bitrix_chat_id' => $resolvedBitrixChatId,
                'resolved_bitrix_chat_verified' => $resolvedBitrixChatVerified,
                'resolved_crm_entity_type' => $resolvedCrmEntityType,
                'resolved_crm_entity_id' => $resolvedCrmEntityId,
                'bitrix_remote_message_id' => $bitrixRemoteMessageId,
                'exported_at' => now(),
                'failed_at' => null,
                'failure_code' => null,
                'failure_uncertain' => false,
                'failure_reason' => null,
                'live_claim_uuid' => null,
                'live_claimed_at' => null,
                'live_claim_expires_at' => null,
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
        ?string $bitrixRemoteMessageId = null,
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
                'resolved_bitrix_chat_verified' => false,
                'resolved_crm_entity_type' => null,
                'resolved_crm_entity_id' => null,
                'bitrix_remote_message_id' => $bitrixRemoteMessageId,
                'batch_uuid' => null,
                'bitrix24_timeline_entry_id' => null,
                'exported_at' => null,
                'failed_at' => now(),
                'failure_code' => $failureCode,
                'failure_uncertain' => $failureUncertain,
                'failure_reason' => $failureReason,
                'live_claim_uuid' => null,
                'live_claimed_at' => null,
                'live_claim_expires_at' => null,
            ],
        );
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolvePersistedCrmBinding(
        Dialog $dialog,
        ?string $resolvedBitrixChatId,
        ?string $resolvedCrmEntityType,
        ?string $resolvedCrmEntityId,
    ): array {
        $normalizedChatId = is_string($resolvedBitrixChatId) ? trim($resolvedBitrixChatId) : null;

        if ($normalizedChatId === null || $normalizedChatId === '') {
            return [null, null];
        }

        $normalizedCrmEntityType = is_string($resolvedCrmEntityType) ? trim($resolvedCrmEntityType) : null;
        $normalizedCrmEntityId = is_string($resolvedCrmEntityId) ? trim($resolvedCrmEntityId) : null;

        if (
            $normalizedCrmEntityType !== null
            && $normalizedCrmEntityType !== ''
            && $normalizedCrmEntityId !== null
            && $normalizedCrmEntityId !== ''
            && $normalizedCrmEntityId !== '0'
        ) {
            return [$normalizedCrmEntityType, $normalizedCrmEntityId];
        }

        $persistedBinding = Bitrix24MessageExport::query()
            ->join('messages', 'messages.id', '=', 'bitrix24_message_exports.message_id')
            ->where('messages.dialog_id', $dialog->id)
            ->where('bitrix24_message_exports.export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->where('bitrix24_message_exports.export_status', Bitrix24MessageExport::STATUS_EXPORTED)
            ->where('bitrix24_message_exports.resolved_bitrix_chat_id', $normalizedChatId)
            ->whereNotNull('bitrix24_message_exports.resolved_crm_entity_type')
            ->whereNotNull('bitrix24_message_exports.resolved_crm_entity_id')
            ->orderByDesc('bitrix24_message_exports.exported_at')
            ->orderByDesc('bitrix24_message_exports.id')
            ->first([
                'bitrix24_message_exports.resolved_crm_entity_type',
                'bitrix24_message_exports.resolved_crm_entity_id',
            ]);

        $persistedCrmEntityType = $persistedBinding?->getAttribute('resolved_crm_entity_type');
        $persistedCrmEntityId = $persistedBinding?->getAttribute('resolved_crm_entity_id');

        return [
            is_scalar($persistedCrmEntityType) && trim((string) $persistedCrmEntityType) !== ''
                ? trim((string) $persistedCrmEntityType)
                : null,
            is_scalar($persistedCrmEntityId) && trim((string) $persistedCrmEntityId) !== '' && trim((string) $persistedCrmEntityId) !== '0'
                ? trim((string) $persistedCrmEntityId)
                : null,
        ];
    }
}
