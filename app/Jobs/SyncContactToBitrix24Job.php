<?php

namespace App\Jobs;

use App\Models\Bitrix24SyncLog;
use App\Models\Contact;
use App\Services\Bitrix24\IsContactReadyForBitrix24SyncAction;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use App\Services\Bitrix24\LogBitrix24RawContactPhoneSnapshotAction;
use App\Services\Bitrix24\QueueBitrix24DealSyncAction;
use App\Services\Bitrix24\QueueBitrix24HistoryExportAction;
use App\Services\Bitrix24\QueueMissedBitrix24OpenLinesRetryAction;
use App\Services\Bitrix24\SyncContactToBitrix24Action;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncContactToBitrix24Job implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(
        public readonly int $contactId,
    ) {}

    public function handle(
        ResolveRootContactAction $resolveRootContactAction,
        IsContactReadyForBitrix24SyncAction $isContactReadyForBitrix24SyncAction,
        SyncContactToBitrix24Action $syncContactToBitrix24Action,
        LogBitrix24ApiCallAction $logApiCallAction,
        LogBitrix24RawContactPhoneSnapshotAction $logBitrix24RawContactPhoneSnapshotAction,
        QueueBitrix24DealSyncAction $queueBitrix24DealSyncAction,
        QueueBitrix24HistoryExportAction $queueBitrix24HistoryExportAction,
        QueueMissedBitrix24OpenLinesRetryAction $queueMissedBitrix24OpenLinesRetryAction,
    ): void {
        $contact = Contact::query()->find($this->contactId);

        if (! $contact instanceof Contact) {
            return;
        }

        $rootContact = $resolveRootContactAction->handle($contact);
        $ready = $isContactReadyForBitrix24SyncAction->handle($rootContact);
        $wasLinkedBeforeSync = filled($rootContact->bitrix24_contact_id)
            && ($rootContact->bitrix24_linked_at !== null || $rootContact->bitrix24_last_synced_at !== null);

        if (! $ready) {
            $rootContact->forceFill([
                'bitrix24_sync_pending' => false,
            ])->save();

            return;
        }

        $syncSucceeded = false;

        try {
            $syncContactToBitrix24Action->handle($rootContact);
            $syncSucceeded = true;
        } catch (Throwable $throwable) {
            $rootContact->refresh();
            $rootContact->forceFill([
                'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_FAILED,
            ])->save();

            $logApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'contact_sync_failed',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'contact_id' => $rootContact->id,
                ],
                connection: null,
                errorMessage: $throwable->getMessage(),
                entityType: 'contact',
                entityId: (string) $rootContact->id,
            );
        } finally {
            $rootContact->refresh();

            if ($rootContact->bitrix24_sync_pending) {
                $rootContact->forceFill([
                    'bitrix24_sync_pending' => false,
                ])->save();
            }
        }

        if (! $syncSucceeded) {
            return;
        }

        $rootContact->refresh();
        $becameLinkedAfterSync = ! $wasLinkedBeforeSync
            && filled($rootContact->bitrix24_contact_id)
            && $rootContact->bitrix24_sync_status === Contact::BITRIX24_SYNC_STATUS_SYNCED
            && ! $rootContact->bitrix24_sync_pending;

        if ($becameLinkedAfterSync) {
            $logBitrix24RawContactPhoneSnapshotAction->handle($rootContact, 'after_contact_sync');
            $queueMissedBitrix24OpenLinesRetryAction->handle($rootContact);
        }

        $queueBitrix24DealSyncAction->handle($rootContact);
        $queueBitrix24HistoryExportAction->handle($rootContact);
    }
}
