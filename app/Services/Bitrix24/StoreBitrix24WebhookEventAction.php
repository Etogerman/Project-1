<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24WebhookEventData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24WebhookEvent;

class StoreBitrix24WebhookEventAction
{
    /**
     * @return array{event: Bitrix24WebhookEvent, duplicate: bool}
     */
    public function handle(
        Bitrix24WebhookEventData $eventData,
        string $processingStatus,
        ?string $failureReason = null,
        ?Bitrix24Connection $connection = null,
    ): array {
        $event = Bitrix24WebhookEvent::query()->firstOrCreate(
            [
                'callback_type' => $eventData->callbackType,
                'event_name' => $eventData->eventName ?? '',
                'member_id' => $eventData->authContext->memberId ?? '',
                'payload_hash' => $eventData->payloadHash,
            ],
            [
                'connection_id' => $connection?->id,
                'application_token' => $eventData->authContext->applicationToken ?? '',
                'portal_domain' => $eventData->authContext->portalDomain,
                'payload' => $eventData->payload,
                'headers' => $eventData->headers,
                'query' => $eventData->query,
                'processing_status' => $processingStatus,
                'failure_reason' => $failureReason,
            ],
        );

        return [
            'event' => $event,
            'duplicate' => ! $event->wasRecentlyCreated,
        ];
    }
}
