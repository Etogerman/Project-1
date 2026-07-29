<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24DealSyncQueueResultData;
use App\Jobs\EnsureBitrix24DealJob;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class QueueBitrix24DealSyncAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly IsContactReadyForBitrix24DealSyncAction $isContactReadyForBitrix24DealSyncAction,
        private readonly Bitrix24OpenLineScopedMutation $scopedMutation,
    ) {}

    public function handle(Contact|int $contact): Bitrix24DealSyncQueueResultData
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $ready = $this->isContactReadyForBitrix24DealSyncAction->handle($rootContact);

        if (! $ready) {
            return new Bitrix24DealSyncQueueResultData(
                queued: false,
                alreadyPending: false,
                ready: false,
                rootContactId: $rootContact->id,
            );
        }

        if ($rootContact->bitrix24_deal_sync_pending) {
            return new Bitrix24DealSyncQueueResultData(
                queued: false,
                alreadyPending: true,
                ready: true,
                rootContactId: $rootContact->id,
            );
        }

        $attributes = [
            'bitrix24_deal_sync_pending' => true,
        ];

        if ($rootContact->bitrix24_deal_sync_status !== Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING_REVIEW) {
            $attributes['bitrix24_deal_sync_status'] = Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING;
        }

        $this->scopedMutation->run(
            fn () => $rootContact->forceFill($attributes)->save(),
        );

        $this->scopedMutation->assertCurrent();
        EnsureBitrix24DealJob::dispatch($rootContact->id);

        return new Bitrix24DealSyncQueueResultData(
            queued: true,
            alreadyPending: false,
            ready: true,
            rootContactId: $rootContact->id,
        );
    }
}
