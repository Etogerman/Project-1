<?php

namespace App\Jobs;

use App\Models\Bitrix24SyncLog;
use App\Models\Contact;
use App\Models\Dialog;
use App\Services\Bitrix24\IsDialogBitrix24OpenLinesRetryRequiredAction;
use App\Services\Bitrix24\IsContactReadyForBitrix24SyncAction;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use App\Services\Bitrix24\LogBitrix24RawContactPhoneSnapshotAction;
use App\Services\Bitrix24\QueueBitrix24DealSyncAction;
use App\Services\Bitrix24\QueueBitrix24HistoryExportAction;
use App\Services\Bitrix24\QueueMissedBitrix24OpenLinesRetryAction;
use App\Services\Bitrix24\SyncContactToBitrix24Action;
use App\Services\Bots\QueueDeferredParameterAutoReplyAction;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncContactToBitrix24Job implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 60;
    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly int $contactId,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("bitrix24:contact-sync:{$this->contactId}"))
                ->releaseAfter(10)
                ->expireAfter(180),
        ];
    }

    public function handle(
        ResolveRootContactAction $resolveRootContactAction,
        IsContactReadyForBitrix24SyncAction $isContactReadyForBitrix24SyncAction,
        SyncContactToBitrix24Action $syncContactToBitrix24Action,
        LogBitrix24ApiCallAction $logApiCallAction,
        LogBitrix24RawContactPhoneSnapshotAction $logBitrix24RawContactPhoneSnapshotAction,
        QueueBitrix24DealSyncAction $queueBitrix24DealSyncAction,
        QueueBitrix24HistoryExportAction $queueBitrix24HistoryExportAction,
        QueueMissedBitrix24OpenLinesRetryAction $queueMissedBitrix24OpenLinesRetryAction,
        IsDialogBitrix24OpenLinesRetryRequiredAction $isDialogBitrix24OpenLinesRetryRequiredAction,
        QueueDeferredParameterAutoReplyAction $queueDeferredParameterAutoReplyAction,
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
        $finalFailure = false;

        try {
            $syncContactToBitrix24Action->handle($rootContact);
            $syncSucceeded = true;
        } catch (Throwable $throwable) {
            if (! $this->isFinalAttempt()) {
                throw $throwable;
            }

            $finalFailure = true;
            $rootContact->refresh();
            $rootContact->forceFill([
                'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_FAILED,
            ])->save();

            Log::critical('Bitrix24 contact sync job failed.', [
                'job' => self::class,
                'contact_id' => $this->contactId,
                'root_contact_id' => $rootContact->id,
                'bitrix24_contact_id' => $rootContact->bitrix24_contact_id,
                'exception_class' => $throwable::class,
                'exception_message' => $throwable->getMessage(),
            ]);

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

            $this->fail($throwable);
        } finally {
            $rootContact->refresh();

            if ($rootContact->bitrix24_sync_pending && ($syncSucceeded || $finalFailure)) {
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
        $pendingDialogs = Dialog::query()
            ->where('contact_id', $rootContact->id)
            ->whereNotNull('pending_auto_reply_source_message_id')
            ->get();
        $hasPendingDialogs = $pendingDialogs->isNotEmpty();

        if ($becameLinkedAfterSync) {
            $logBitrix24RawContactPhoneSnapshotAction->handle($rootContact, 'after_contact_sync');
        }

        if ($becameLinkedAfterSync || $hasPendingDialogs) {
            $queueMissedBitrix24OpenLinesRetryAction->handle($rootContact);

            $pendingDialogs->each(function (Dialog $dialog) use (
                $isDialogBitrix24OpenLinesRetryRequiredAction,
                $queueDeferredParameterAutoReplyAction,
            ): void {
                if ($isDialogBitrix24OpenLinesRetryRequiredAction->handle($dialog)) {
                    return;
                }

                $queueDeferredParameterAutoReplyAction->handle($dialog);
            });
        }

        $queueBitrix24DealSyncAction->handle($rootContact);
        $queueBitrix24HistoryExportAction->handle($rootContact);
    }

    private function isFinalAttempt(): bool
    {
        return $this->attempts() >= $this->tries;
    }
}
