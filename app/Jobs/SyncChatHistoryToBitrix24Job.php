<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use App\Services\Bitrix24\IsContactReadyForBitrix24HistoryExportAction;
use App\Services\Bitrix24\SyncChatHistoryToBitrix24Action;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class SyncChatHistoryToBitrix24Job implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 75;
    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly int $contactId,
    ) {}

    public function handle(
        ResolveRootContactAction $resolveRootContactAction,
        IsContactReadyForBitrix24HistoryExportAction $isContactReadyForBitrix24HistoryExportAction,
        SyncChatHistoryToBitrix24Action $syncChatHistoryToBitrix24Action,
        LogBitrix24ApiCallAction $logApiCallAction,
    ): void {
        $contact = Contact::query()->find($this->contactId);

        if (! $contact instanceof Contact) {
            return;
        }

        $rootContact = $resolveRootContactAction->handle($contact);
        $ready = $isContactReadyForBitrix24HistoryExportAction->handle($rootContact);

        if (! $ready) {
            $rootContact->forceFill([
                'bitrix24_history_sync_pending' => false,
            ])->save();

            return;
        }

        $syncSucceeded = false;
        $finalFailure = false;

        try {
            $syncChatHistoryToBitrix24Action->handle($rootContact);
            $syncSucceeded = true;
        } catch (Throwable $throwable) {
            if (! $this->isFinalAttempt()) {
                throw $throwable;
            }

            $finalFailure = true;
            $rootContact->refresh();
            $rootContact->forceFill([
                'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_FAILED,
            ])->save();

            $logApiCallAction->handle(
                direction: 'system',
                operation: 'history_export_failed',
                status: 'failed',
                requestPayload: [
                    'contact_id' => $rootContact->id,
                    'bitrix24_contact_id' => $rootContact->bitrix24_contact_id,
                ],
                connection: null,
                errorMessage: $throwable->getMessage(),
                entityType: 'contact',
                entityId: (string) $rootContact->id,
            );

            $this->fail($throwable);
        } finally {
            $rootContact->refresh();

            if ($rootContact->bitrix24_history_sync_pending && ($syncSucceeded || $finalFailure)) {
                $rootContact->forceFill([
                    'bitrix24_history_sync_pending' => false,
                ])->save();
            }
        }
    }

    private function isFinalAttempt(): bool
    {
        return $this->attempts() >= $this->tries;
    }
}
