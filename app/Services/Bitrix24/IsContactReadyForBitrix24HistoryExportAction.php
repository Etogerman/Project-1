<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class IsContactReadyForBitrix24HistoryExportAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact|int $contact): bool
    {
        if (! config('bitrix24.features.timeline_history_import_enabled', false)) {
            return false;
        }

        $rootContact = $this->resolveRootContactAction->handle($contact);

        if ($rootContact->isMerged()) {
            return false;
        }

        if ($rootContact->data_collection_status !== Contact::DATA_COLLECTION_STATUS_COMPLETED) {
            return false;
        }

        if (! filled($rootContact->bitrix24_contact_id)) {
            return false;
        }

        if ($rootContact->bitrix24_sync_status !== Contact::BITRIX24_SYNC_STATUS_SYNCED) {
            return false;
        }

        if ($rootContact->bitrix24_sync_pending) {
            return false;
        }

        return true;
    }
}
