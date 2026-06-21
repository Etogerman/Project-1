<?php

namespace App\Services\Contacts;

use App\Data\Contacts\ContactDataCollectionCompletionResult;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

class CompleteContactDataCollectionIfReadyAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveContactDataCollectionCompletionRequirementsAction $completionRequirementsAction,
    ) {}

    public function handle(Contact|int $contact): ContactDataCollectionCompletionResult
    {
        return DB::transaction(function () use ($contact): ContactDataCollectionCompletionResult {
            $rootContact = $this->resolveRootContactAction->handle($contact);
            $lockedContact = Contact::query()
                ->whereKey($rootContact->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedContact instanceof Contact) {
                return new ContactDataCollectionCompletionResult(
                    status: 'missing_contact',
                    completed: false,
                    rootContactId: null,
                    reason: 'missing_root_contact',
                );
            }

            $missingRequirements = $this->completionRequirementsAction->handle($lockedContact);

            if ($missingRequirements !== []) {
                return new ContactDataCollectionCompletionResult(
                    status: 'not_ready',
                    completed: false,
                    rootContactId: $lockedContact->id,
                    missingRequirements: $missingRequirements,
                    reason: 'profile_not_ready',
                );
            }

            if ($lockedContact->data_collection_status === Contact::DATA_COLLECTION_STATUS_COMPLETED) {
                return new ContactDataCollectionCompletionResult(
                    status: 'already_completed',
                    completed: true,
                    rootContactId: $lockedContact->id,
                );
            }

            $lockedContact->completeDataCollection();

            return new ContactDataCollectionCompletionResult(
                status: 'completed',
                completed: true,
                rootContactId: $lockedContact->id,
            );
        });
    }
}
