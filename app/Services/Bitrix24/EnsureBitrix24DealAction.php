<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24ActiveDealStateResultData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class EnsureBitrix24DealAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly FindActiveBitrix24DealsForContactAction $findActiveDealsForContactAction,
        private readonly ResolveBitrix24ActiveDealStateAction $resolveActiveDealStateAction,
        private readonly ApplyBitrix24DealLookupResultAction $applyDealLookupResultAction,
        private readonly BuildBitrix24DealPayloadAction $buildBitrix24DealPayloadAction,
        private readonly CreateBitrix24DealAction $createBitrix24DealAction,
        private readonly LinkBitrix24DealAction $linkBitrix24DealAction,
        private readonly LogBitrix24ApiCallAction $logApiCallAction,
        private readonly ResolveCurrentBitrix24ConnectionAction $resolveCurrentConnectionAction,
    ) {}

    public function handle(Contact|int $contact): Contact
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $connection = $this->resolveCurrentConnectionAction->handle();
        $lookupResult = $this->findActiveDealsForContactAction->handle($rootContact, $connection);
        $state = $this->resolveActiveDealStateAction->handle($lookupResult);

        $this->logApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'deal_sync_active_lookup',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'contact_id' => $rootContact->id,
                'bitrix24_contact_id' => $lookupResult->bitrix24ContactId,
            ],
            responsePayload: [
                'pages_fetched' => $lookupResult->pagesFetched,
                'deal_ids' => $lookupResult->dealIds,
                'selected_deal_id' => $state->selectedDealId,
                'state' => $state->type,
            ],
            connection: null,
            entityType: 'contact',
            entityId: (string) $rootContact->id,
        );

        if ($state->type === Bitrix24ActiveDealStateResultData::TYPE_NO_ACTIVE_DEAL) {
            return $this->createAndLinkDeal($rootContact, $state, $connection);
        }

        $updatedContact = $this->applyDealLookupResultAction->handle($rootContact, $state);

        $this->logOutcome($updatedContact, $state);

        return $updatedContact;
    }

    private function createAndLinkDeal(
        Contact $contact,
        Bitrix24ActiveDealStateResultData $state,
        Bitrix24Connection $connection,
    ): Contact
    {
        $this->logApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'deal_sync_no_active',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'contact_id' => $contact->id,
                'bitrix24_contact_id' => $contact->bitrix24_contact_id,
            ],
            responsePayload: [
                'selected_deal_id' => $state->selectedDealId,
                'active_deal_ids' => $state->activeDealIds,
            ],
            connection: null,
            entityType: 'contact',
            entityId: (string) $contact->id,
        );

        try {
            $payload = $this->buildBitrix24DealPayloadAction->handle($contact);
        } catch (Bitrix24ApiException $exception) {
            $this->logApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'deal_sync_config_failed',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'contact_id' => $contact->id,
                    'bitrix24_contact_id' => $contact->bitrix24_contact_id,
                ],
                connection: null,
                errorMessage: $exception->getMessage(),
                entityType: 'contact',
                entityId: (string) $contact->id,
            );

            throw $exception;
        }

        try {
            $bitrix24DealId = $this->createBitrix24DealAction->handle($contact, $payload, $connection);
        } catch (Bitrix24ApiException $exception) {
            $this->logApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'deal_sync_create_failed',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'contact_id' => $contact->id,
                    'bitrix24_contact_id' => $contact->bitrix24_contact_id,
                    'payload' => $payload,
                ],
                connection: null,
                errorMessage: $exception->getMessage(),
                entityType: 'contact',
                entityId: (string) $contact->id,
            );

            throw $exception;
        }

        $linkedContact = $this->linkBitrix24DealAction->handle($contact, $bitrix24DealId);

        $this->logApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'deal_sync_created',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'contact_id' => $contact->id,
                'bitrix24_contact_id' => $contact->bitrix24_contact_id,
                'payload' => $payload,
            ],
            responsePayload: [
                'bitrix24_deal_id' => $bitrix24DealId,
            ],
            connection: null,
            entityType: 'contact',
            entityId: (string) $contact->id,
        );

        return $linkedContact;
    }

    private function logOutcome(Contact $contact, Bitrix24ActiveDealStateResultData $state): void
    {
        $operation = match ($state->type) {
            Bitrix24ActiveDealStateResultData::TYPE_NO_ACTIVE_DEAL => 'deal_sync_no_active',
            Bitrix24ActiveDealStateResultData::TYPE_SINGLE_ACTIVE_DEAL => 'deal_sync_linked_existing',
            Bitrix24ActiveDealStateResultData::TYPE_MULTIPLE_ACTIVE_DEALS => 'deal_sync_multiple_active',
            default => 'deal_sync_active_lookup',
        };

        $status = $state->type === Bitrix24ActiveDealStateResultData::TYPE_MULTIPLE_ACTIVE_DEALS
            ? Bitrix24SyncLog::STATUS_SKIPPED
            : Bitrix24SyncLog::STATUS_SUCCESS;

        $this->logApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: $operation,
            status: $status,
            requestPayload: [
                'contact_id' => $contact->id,
                'bitrix24_contact_id' => $contact->bitrix24_contact_id,
            ],
            responsePayload: [
                'selected_deal_id' => $state->selectedDealId,
                'active_deal_ids' => $state->activeDealIds,
            ],
            connection: null,
            entityType: 'contact',
            entityId: (string) $contact->id,
        );
    }
}
