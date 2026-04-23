<?php

namespace App\Jobs;

use App\Models\Bitrix24SyncLog;
use App\Models\Contact;
use App\Services\Bitrix24\EnsureBitrix24DealAction;
use App\Services\Bitrix24\IsContactReadyForBitrix24DealSyncAction;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnsureBitrix24DealJob implements ShouldQueue
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
            (new WithoutOverlapping("bitrix24:deal-sync:{$this->contactId}"))
                ->releaseAfter(10)
                ->expireAfter(180),
        ];
    }

    public function handle(
        ResolveRootContactAction $resolveRootContactAction,
        IsContactReadyForBitrix24DealSyncAction $isContactReadyForBitrix24DealSyncAction,
        EnsureBitrix24DealAction $ensureBitrix24DealAction,
        LogBitrix24ApiCallAction $logApiCallAction,
    ): void {
        $contact = Contact::query()->find($this->contactId);

        if (! $contact instanceof Contact) {
            return;
        }

        $rootContact = $resolveRootContactAction->handle($contact);
        $ready = $isContactReadyForBitrix24DealSyncAction->handle($rootContact);

        if (! $ready) {
            $rootContact->forceFill([
                'bitrix24_deal_sync_pending' => false,
            ])->save();

            return;
        }

        $syncSucceeded = false;
        $finalFailure = false;

        try {
            $ensureBitrix24DealAction->handle($rootContact);
            $syncSucceeded = true;
        } catch (Throwable $throwable) {
            if (! $this->isFinalAttempt()) {
                throw $throwable;
            }

            $finalFailure = true;
            $rootContact->refresh();
            $rootContact->forceFill([
                'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_FAILED,
            ])->save();

            Log::critical('Bitrix24 deal sync job failed.', [
                'job' => self::class,
                'contact_id' => $this->contactId,
                'root_contact_id' => $rootContact->id,
                'bitrix24_contact_id' => $rootContact->bitrix24_contact_id,
                'bitrix24_deal_id' => $rootContact->bitrix24_deal_id,
                'exception_class' => $throwable::class,
                'exception_message' => $throwable->getMessage(),
            ]);

            $logApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'deal_sync_lookup_failed',
                status: Bitrix24SyncLog::STATUS_FAILED,
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

            if ($rootContact->bitrix24_deal_sync_pending && ($syncSucceeded || $finalFailure)) {
                $rootContact->forceFill([
                    'bitrix24_deal_sync_pending' => false,
                ])->save();
            }
        }
    }

    private function isFinalAttempt(): bool
    {
        return $this->attempts() >= $this->tries;
    }
}
