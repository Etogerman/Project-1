<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24RescueSyncDiagnosticData;
use App\Models\Bitrix24SyncLog;
use App\Models\Contact;
use App\Services\Contacts\ResolveContactDataCollectionCompletionRequirementsAction;
use App\Services\Contacts\ResolveRootContactAction;

class DiagnoseBitrix24RescueSyncAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveContactDataCollectionCompletionRequirementsAction $completionRequirementsAction,
    ) {}

    public function handle(Contact|int $contact): Bitrix24RescueSyncDiagnosticData
    {
        $requestedContact = $contact instanceof Contact
            ? $contact
            : Contact::query()->findOrFail($contact);

        $rootContact = $this->resolveRootContactAction->handle($requestedContact);
        $missingRequirements = $this->completionRequirementsAction->handle($rootContact);
        $dealsSyncEnabled = (bool) config('bitrix24.features.deals_sync_enabled', false);
        $historySyncEnabled = (bool) config('bitrix24.features.timeline_history_import_enabled', false);

        $reasons = [];

        if ((int) $rootContact->id !== (int) $requestedContact->id) {
            $reasons[] = 'using_root_contact';
        }

        if ($rootContact->data_collection_status !== Contact::DATA_COLLECTION_STATUS_COMPLETED) {
            $reasons[] = 'data_collection_not_completed';
        }

        foreach ($missingRequirements as $requirement) {
            $reasons[] = 'missing_'.$requirement;
        }

        $needsManualReview = in_array($rootContact->bitrix24_sync_status, [
            Contact::BITRIX24_SYNC_STATUS_PENDING_REVIEW,
        ], true) || in_array($rootContact->bitrix24_deal_sync_status, [
            Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING_REVIEW,
        ], true);

        if ($rootContact->bitrix24_sync_status === Contact::BITRIX24_SYNC_STATUS_PENDING_REVIEW) {
            $reasons[] = 'contact_needs_manual_review';
        }

        if ($rootContact->bitrix24_deal_sync_status === Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING_REVIEW) {
            $reasons[] = 'deal_needs_manual_review';
        }

        if ($rootContact->bitrix24_sync_pending) {
            $reasons[] = 'contact_already_pending';
        }

        if ($rootContact->bitrix24_deal_sync_pending) {
            $reasons[] = 'deal_already_pending';
        }

        if ($rootContact->bitrix24_history_sync_pending) {
            $reasons[] = 'history_already_pending';
        }

        if (! $dealsSyncEnabled) {
            $reasons[] = 'deals_sync_disabled';
        }

        if (! $historySyncEnabled) {
            $reasons[] = 'history_sync_disabled';
        }

        $ready = $rootContact->data_collection_status === Contact::DATA_COLLECTION_STATUS_COMPLETED
            && $missingRequirements === []
            && ! $needsManualReview;

        $contactQueueableStatus = in_array($rootContact->bitrix24_sync_status, [
            Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED,
            Contact::BITRIX24_SYNC_STATUS_FAILED,
        ], true);

        $canQueueContact = $ready
            && $contactQueueableStatus
            && ! $rootContact->bitrix24_sync_pending;

        $canQueueDeal = $ready
            && $dealsSyncEnabled
            && filled($rootContact->bitrix24_contact_id)
            && $rootContact->bitrix24_sync_status === Contact::BITRIX24_SYNC_STATUS_SYNCED
            && ! $rootContact->bitrix24_sync_pending
            && in_array($rootContact->bitrix24_deal_sync_status, [
                Contact::BITRIX24_DEAL_SYNC_STATUS_NOT_SYNCED,
                Contact::BITRIX24_DEAL_SYNC_STATUS_FAILED,
            ], true)
            && ! $rootContact->bitrix24_deal_sync_pending;

        $canQueueHistory = $ready
            && $historySyncEnabled
            && filled($rootContact->bitrix24_contact_id)
            && $rootContact->bitrix24_sync_status === Contact::BITRIX24_SYNC_STATUS_SYNCED
            && ! $rootContact->bitrix24_sync_pending
            && in_array($rootContact->bitrix24_history_sync_status, [
                Contact::BITRIX24_HISTORY_SYNC_STATUS_NOT_SYNCED,
                Contact::BITRIX24_HISTORY_SYNC_STATUS_FAILED,
            ], true)
            && ! $rootContact->bitrix24_history_sync_pending;

        if ($ready
            && ! $canQueueContact
            && ! $canQueueDeal
            && ! $canQueueHistory
            && ! $rootContact->bitrix24_sync_pending
            && ! $rootContact->bitrix24_deal_sync_pending
            && ! $rootContact->bitrix24_history_sync_pending
            && $rootContact->bitrix24_sync_status === Contact::BITRIX24_SYNC_STATUS_SYNCED
            && ($rootContact->bitrix24_deal_sync_status === Contact::BITRIX24_DEAL_SYNC_STATUS_SYNCED || ! $dealsSyncEnabled)
            && ($rootContact->bitrix24_history_sync_status === Contact::BITRIX24_HISTORY_SYNC_STATUS_SYNCED || ! $historySyncEnabled)
        ) {
            $reasons[] = 'already_fully_synced';
        }

        return new Bitrix24RescueSyncDiagnosticData(
            ready: $ready,
            rootContactId: (int) $rootContact->id,
            requestedContactId: (int) $requestedContact->id,
            missingRequirements: array_values($missingRequirements),
            contactStatus: (string) $rootContact->bitrix24_sync_status,
            contactPending: (bool) $rootContact->bitrix24_sync_pending,
            dealStatus: (string) $rootContact->bitrix24_deal_sync_status,
            dealPending: (bool) $rootContact->bitrix24_deal_sync_pending,
            historyStatus: (string) $rootContact->bitrix24_history_sync_status,
            historyPending: (bool) $rootContact->bitrix24_history_sync_pending,
            dealsSyncEnabled: $dealsSyncEnabled,
            historySyncEnabled: $historySyncEnabled,
            canQueueContact: $canQueueContact,
            canQueueDeal: $canQueueDeal,
            canQueueHistory: $canQueueHistory,
            needsManualReview: $needsManualReview,
            lastContactError: $this->latestFailedError($rootContact, ['contact_sync']),
            lastDealError: $this->latestFailedError($rootContact, ['deal_sync']),
            lastHistoryError: $this->latestFailedError($rootContact, ['history_export']),
            reasons: array_values(array_unique($reasons)),
        );
    }

    /**
     * @param  list<string>  $operationPrefixes
     */
    private function latestFailedError(Contact $contact, array $operationPrefixes): ?string
    {
        $logs = Bitrix24SyncLog::query()
            ->where('status', Bitrix24SyncLog::STATUS_FAILED)
            ->where(function ($query) use ($operationPrefixes): void {
                foreach ($operationPrefixes as $prefix) {
                    $query->orWhere('operation', 'like', $prefix.'%');
                }
            })
            ->latest('id')
            ->limit(25)
            ->get();

        $log = $logs->first(function (Bitrix24SyncLog $log) use ($contact): bool {
            if ($log->entity_type === 'contact' && (string) $log->entity_id === (string) $contact->id) {
                return true;
            }

            return (int) data_get($log->request_payload, 'contact_id') === (int) $contact->id;
        });

        if (! $log instanceof Bitrix24SyncLog) {
            return null;
        }

        return filled($log->error_message)
            ? (string) $log->error_message
            : ($log->error_code === null ? null : (string) $log->error_code);
    }
}
