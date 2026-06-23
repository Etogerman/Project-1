<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24RescueSyncDiagnosticData;
use App\Data\Bitrix24\Bitrix24RescueSyncQueueResultData;
use App\Models\Bitrix24SyncLog;
use App\Models\Contact;
use App\Models\ContactTimelineEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class QueueBitrix24RescueSyncAction
{
    public function __construct(
        private readonly DiagnoseBitrix24RescueSyncAction $diagnoseBitrix24RescueSyncAction,
        private readonly QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
        private readonly QueueBitrix24DealSyncAction $queueBitrix24DealSyncAction,
        private readonly QueueBitrix24HistoryExportAction $queueBitrix24HistoryExportAction,
        private readonly LogBitrix24ApiCallAction $logBitrix24ApiCallAction,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(Contact|int $contact, User $actor): Bitrix24RescueSyncQueueResultData
    {
        if (! $actor->hasRolePermission('bitrix24.edit')) {
            throw new AuthorizationException('Для ручной синхронизации с Bitrix24 нужно право bitrix24.edit.');
        }

        $diagnostics = $this->diagnoseBitrix24RescueSyncAction->handle($contact);
        $rootContact = Contact::query()->findOrFail($diagnostics->rootContactId);

        $queuedContact = false;
        $queuedDeal = false;
        $queuedHistory = false;

        if ($diagnostics->canQueueContact) {
            $contactResult = $this->queueBitrix24ContactSyncAction->handle($rootContact, suppressDialogContinuation: true);
            $queuedContact = $contactResult->queued;
        } elseif ($diagnostics->ready && ! $diagnostics->needsManualReview) {
            if ($diagnostics->canQueueDeal) {
                $dealResult = $this->queueBitrix24DealSyncAction->handle($rootContact);
                $queuedDeal = $dealResult->queued;
            }

            if ($diagnostics->canQueueHistory) {
                $historyResult = $this->queueBitrix24HistoryExportAction->handle($rootContact);
                $queuedHistory = $historyResult->queued;
            }
        }

        $alreadyPending = $diagnostics->contactPending
            || $diagnostics->dealPending
            || $diagnostics->historyPending;

        $status = $this->resolveStatus($diagnostics, $queuedContact, $queuedDeal, $queuedHistory, $alreadyPending);

        $result = new Bitrix24RescueSyncQueueResultData(
            status: $status,
            queuedContact: $queuedContact,
            queuedDeal: $queuedDeal,
            queuedHistory: $queuedHistory,
            alreadyPending: $alreadyPending,
            needsManualReview: $diagnostics->needsManualReview,
            rootContactId: $diagnostics->rootContactId,
            requestedContactId: $diagnostics->requestedContactId,
            skippedReasons: $diagnostics->reasons,
            diagnostics: $diagnostics,
        );

        $this->recordRequest($rootContact, $actor, $result);

        return $result;
    }

    private function resolveStatus(
        Bitrix24RescueSyncDiagnosticData $diagnostics,
        bool $queuedContact,
        bool $queuedDeal,
        bool $queuedHistory,
        bool $alreadyPending,
    ): string {
        if ($queuedContact || $queuedDeal || $queuedHistory) {
            return 'queued';
        }

        if ($diagnostics->needsManualReview) {
            return 'needs_manual_review';
        }

        if (! $diagnostics->ready) {
            return 'not_ready';
        }

        if ($alreadyPending) {
            return 'already_pending';
        }

        if (in_array('already_fully_synced', $diagnostics->reasons, true)) {
            return 'synced';
        }

        return 'skipped';
    }

    private function recordRequest(Contact $rootContact, User $actor, Bitrix24RescueSyncQueueResultData $result): void
    {
        $payload = [
            'actor_user_id' => (int) $actor->id,
            'requested_contact_id' => $result->requestedContactId,
            'root_contact_id' => $result->rootContactId,
            'queued_contact' => $result->queuedContact,
            'queued_deal' => $result->queuedDeal,
            'queued_history' => $result->queuedHistory,
            'already_pending' => $result->alreadyPending,
            'needs_manual_review' => $result->needsManualReview,
            'skipped_reasons' => $result->skippedReasons,
            'result_status' => $result->status,
        ];

        ContactTimelineEvent::query()->create([
            'contact_id' => $rootContact->id,
            'event_type' => ContactTimelineEvent::EVENT_BITRIX24_RESCUE_SYNC_REQUESTED,
            'actor_user_id' => $actor->id,
            'body' => null,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);

        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'rescue_sync_requested',
            status: ($result->queuedContact || $result->queuedDeal || $result->queuedHistory)
                ? Bitrix24SyncLog::STATUS_SUCCESS
                : Bitrix24SyncLog::STATUS_SKIPPED,
            requestPayload: [
                'actor_user_id' => (int) $actor->id,
                'requested_contact_id' => $result->requestedContactId,
                'root_contact_id' => $result->rootContactId,
            ],
            responsePayload: $payload + [
                'diagnostics' => $result->diagnostics->toArray(),
            ],
            entityType: 'contact',
            entityId: (string) $rootContact->id,
        );
    }
}
