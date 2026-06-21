<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;
use App\Services\Contacts\ResolveContactDataCollectionCompletionRequirementsAction;
use App\Services\Contacts\ResolveRootContactAction;

class IsContactReadyForBitrix24SyncAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveContactDataCollectionCompletionRequirementsAction $completionRequirementsAction,
    ) {}

    public function handle(Contact|int $contact): bool
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);

        if (! $rootContact->isRoot()) {
            return false;
        }

        if ($rootContact->data_collection_status !== Contact::DATA_COLLECTION_STATUS_COMPLETED) {
            return false;
        }

        return $this->completionRequirementsAction->handle($rootContact) === [];
    }
}
