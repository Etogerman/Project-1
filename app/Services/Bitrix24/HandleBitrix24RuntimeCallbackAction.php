<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24CallbackHandlingResultData;
use App\Data\Bitrix24\Bitrix24WebhookEventData;
use App\Jobs\ProcessBitrix24WebhookEventJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use Illuminate\Http\Request;

class HandleBitrix24RuntimeCallbackAction
{
    public function __construct(
        private readonly NormalizeBitrix24WebhookPayloadAction $normalizeWebhookPayload,
        private readonly BuildBitrix24AuthContextAction $buildAuthContext,
        private readonly ValidateBitrix24CallbackAction $validateCallback,
        private readonly BuildBitrix24WebhookFingerprintAction $buildFingerprint,
        private readonly StoreBitrix24WebhookEventAction $storeWebhookEvent,
        private readonly LogBitrix24CallbackOutcomeAction $logCallbackOutcome,
    ) {}

    public function handle(Request $request, string $callbackType): Bitrix24CallbackHandlingResultData
    {
        $normalized = $this->normalizeWebhookPayload->handle($request);
        $authContext = $this->buildAuthContext->handle($request->all());
        $validation = $this->validateCallback->handle($callbackType, $authContext, $normalized['looks_like_bitrix']);
        $fingerprint = $this->buildFingerprint->handle($normalized['payload']);

        $connection = $validation->connection;

        if ($validation->accepted && $connection) {
            $this->touchLastCallbackTimestamp($connection, $callbackType);
        } elseif ($connection && filled($validation->reason)) {
            $connection->forceFill([
                'last_error_at' => now(),
                'last_error_message' => $validation->reason,
            ])->save();
        }

        $storeResult = $this->storeWebhookEvent->handle(
            eventData: new Bitrix24WebhookEventData(
                callbackType: $callbackType,
                eventName: $normalized['event_name'],
                authContext: $authContext,
                payload: $normalized['payload'],
                headers: $normalized['headers'],
                query: $normalized['query'],
                payloadHash: $fingerprint,
            ),
            processingStatus: $validation->processingStatus,
            failureReason: $validation->reason,
            connection: $connection,
        );

        $event = $storeResult['event'];
        $duplicate = $storeResult['duplicate'];

        $logStatus = match (true) {
            $duplicate => Bitrix24SyncLog::STATUS_SKIPPED,
            $validation->processingStatus === Bitrix24WebhookEvent::STATUS_FAILED => Bitrix24SyncLog::STATUS_FAILED,
            $validation->processingStatus === Bitrix24WebhookEvent::STATUS_IGNORED => Bitrix24SyncLog::STATUS_SKIPPED,
            default => Bitrix24SyncLog::STATUS_SUCCESS,
        };

        $operation = match (true) {
            $duplicate => $callbackType.'_callback_duplicate',
            $validation->processingStatus === Bitrix24WebhookEvent::STATUS_FAILED => $callbackType.'_callback_validation_failed',
            $validation->processingStatus === Bitrix24WebhookEvent::STATUS_IGNORED => $callbackType.'_callback_ignored',
            default => $callbackType.'_callback_stored',
        };

        $this->logCallbackOutcome->handle(
            callbackType: $callbackType,
            status: $logStatus,
            operation: $operation,
            payload: $normalized['payload'],
            eventName: $normalized['event_name'],
            fingerprint: $fingerprint,
            errorMessage: $validation->reason,
            connection: $connection,
        );

        $dispatched = false;

        if (! $duplicate && $validation->accepted) {
            ProcessBitrix24WebhookEventJob::dispatch($event->id);
            $dispatched = true;
        }

        return new Bitrix24CallbackHandlingResultData(
            callbackType: $callbackType,
            processingStatus: $validation->processingStatus,
            stored: true,
            duplicate: $duplicate,
            dispatched: $dispatched,
            event: $event,
        );
    }

    private function touchLastCallbackTimestamp(Bitrix24Connection $connection, string $callbackType): void
    {
        $field = match ($callbackType) {
            Bitrix24WebhookEvent::TYPE_EVENTS => 'last_events_callback_at',
            Bitrix24WebhookEvent::TYPE_OPENLINES => 'last_openlines_callback_at',
            default => null,
        };

        if ($field === null) {
            return;
        }

        $connection->forceFill([$field => now()])->save();
    }
}
