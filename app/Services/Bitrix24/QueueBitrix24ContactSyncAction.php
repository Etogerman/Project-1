<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24ContactSyncQueueResultData;
use App\Jobs\SyncContactToBitrix24Job;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class QueueBitrix24ContactSyncAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly IsContactReadyForBitrix24SyncAction $isContactReadyForBitrix24SyncAction,
    ) {}

    public function handle(Contact|int $contact, bool $suppressDialogContinuation = false): Bitrix24ContactSyncQueueResultData
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $ready = $this->isContactReadyForBitrix24SyncAction->handle($rootContact);

        if (! $ready) {
            return new Bitrix24ContactSyncQueueResultData(
                queued: false,
                alreadyPending: false,
                ready: false,
                rootContactId: $rootContact->id,
            );
        }

        if ($rootContact->bitrix24_sync_pending) {
            return new Bitrix24ContactSyncQueueResultData(
                queued: false,
                alreadyPending: true,
                ready: true,
                rootContactId: $rootContact->id,
            );
        }

        $attributes = [
            'bitrix24_sync_pending' => true,
        ];

        if ($rootContact->bitrix24_sync_status !== Contact::BITRIX24_SYNC_STATUS_PENDING_REVIEW) {
            $attributes['bitrix24_sync_status'] = Contact::BITRIX24_SYNC_STATUS_PENDING;
        }

        $rootContact->forceFill($attributes)->save();

        SyncContactToBitrix24Job::dispatch($rootContact->id, $suppressDialogContinuation);

        return new Bitrix24ContactSyncQueueResultData(
            queued: true,
            alreadyPending: false,
            ready: true,
            rootContactId: $rootContact->id,
        );
    }
}
