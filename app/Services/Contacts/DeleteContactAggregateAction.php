<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactMergeLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeleteContactAggregateAction
{
    public function __construct(
        private readonly ResolveContactAggregateAction $resolveContactAggregateAction,
        private readonly CleanupExternalDuplicateReviewsForDeletedAggregateAction $cleanupExternalDuplicateReviewsForDeletedAggregateAction,
    ) {}

    public function handle(Contact|int $contact): void
    {
        $inputContactId = $contact instanceof Contact ? $contact->id : $contact;

        try {
            DB::transaction(function () use ($contact): void {
                $resolvedAggregate = $this->resolveContactAggregateAction->handle($contact);

                $lockedContacts = Contact::query()
                    ->whereKey($resolvedAggregate->aggregateContactIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($lockedContacts->count() !== count($resolvedAggregate->aggregateContactIds)) {
                    throw new RuntimeException('Contact aggregate changed during delete.');
                }

                ContactMergeLog::query()
                    ->where(function ($query) use ($resolvedAggregate): void {
                        $query
                            ->whereIn('primary_contact_id', $resolvedAggregate->aggregateContactIds)
                            ->orWhereIn('secondary_contact_id', $resolvedAggregate->aggregateContactIds);
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $this->cleanupExternalDuplicateReviewsForDeletedAggregateAction->handle(
                    $resolvedAggregate->aggregateContactIds,
                );

                ContactMergeLog::query()
                    ->where(function ($query) use ($resolvedAggregate): void {
                        $query
                            ->whereIn('primary_contact_id', $resolvedAggregate->aggregateContactIds)
                            ->orWhereIn('secondary_contact_id', $resolvedAggregate->aggregateContactIds);
                    })
                    ->delete();

                foreach ($resolvedAggregate->deletionOrder as $contactId) {
                    /** @var Contact $lockedContact */
                    $lockedContact = $lockedContacts->get($contactId)
                        ?? throw new RuntimeException("Contact [{$contactId}] is missing during aggregate delete.");

                    $lockedContact->delete();
                }
            });
        } catch (BrokenContactMergeChainException $exception) {
            Log::error('contact.aggregate_delete_broken_merge_chain', [
                'contact_id' => $inputContactId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
