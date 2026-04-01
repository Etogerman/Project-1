<?php

namespace App\Jobs;

use App\Models\Bitrix24SyncLog;
use App\Models\Contact;
use App\Services\Bitrix24\IsContactReadyForBitrix24SyncAction;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use App\Services\Bitrix24\QueueBitrix24DealSyncAction;
use App\Services\Bitrix24\QueueBitrix24HistoryExportAction;
use App\Services\Bitrix24\SyncContactToBitrix24Action;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncContactToBitrix24Job implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $contactId,
    ) {}

    public function handle(
        ResolveRootContactAction $resolveRootContactAction,
        IsContactReadyForBitrix24SyncAction $isContactReadyForBitrix24SyncAction,
        SyncContactToBitrix24Action $syncContactToBitrix24Action,
        LogBitrix24ApiCallAction $logApiCallAction,
        QueueBitrix24DealSyncAction $queueBitrix24DealSyncAction,
        QueueBitrix24HistoryExportAction $queueBitrix24HistoryExportAction,
    ): void {
        $contact = Contact::query()->find($this->contactId);

        if (! $contact instanceof Contact) {
            return;
        }

        $rootContact = $resolveRootContactAction->handle($contact);
        $ready = $isContactReadyForBitrix24SyncAction->handle($rootContact);

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
        $queueBitrix24DealSyncAction->handle($rootContact);
        $queueBitrix24HistoryExportAction->handle($rootContact);
    }
}
