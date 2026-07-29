<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24HistoryExportQueueResultData;
use App\Jobs\SyncChatHistoryToBitrix24Job;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class QueueBitrix24HistoryExportAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly IsContactReadyForBitrix24HistoryExportAction $isContactReadyForBitrix24HistoryExportAction,
        private readonly Bitrix24OpenLineScopedMutation $scopedMutation,
    ) {}

    public function handle(Contact|int $contact): Bitrix24HistoryExportQueueResultData
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $ready = $this->isContactReadyForBitrix24HistoryExportAction->handle($rootContact);

        if (! $ready) {
            return new Bitrix24HistoryExportQueueResultData(
                queued: false,
                alreadyPending: false,
                ready: false,
                rootContactId: $rootContact->id,
            );
        }

        if ($rootContact->bitrix24_history_sync_pending) {
            return new Bitrix24HistoryExportQueueResultData(
                queued: false,
                alreadyPending: true,
                ready: true,
                rootContactId: $rootContact->id,
            );
        }

        $attributes = [
            'bitrix24_history_sync_pending' => true,
        ];

        if ($rootContact->bitrix24_history_sync_status !== Contact::BITRIX24_HISTORY_SYNC_STATUS_SYNCED) {
            $attributes['bitrix24_history_sync_status'] = Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING;
        }

        $this->scopedMutation->run(
            fn () => $rootContact->forceFill($attributes)->save(),
        );

        $this->scopedMutation->assertCurrent();
        SyncChatHistoryToBitrix24Job::dispatch($rootContact->id);

        return new Bitrix24HistoryExportQueueResultData(
            queued: true,
            alreadyPending: false,
            ready: true,
            rootContactId: $rootContact->id,
        );
    }
}
