<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactMergeLog;
use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeleteContactAggregateAction
{
    public function __construct(
        private readonly ResolveContactAggregateAction $resolveContactAggregateAction,
        private readonly BuildContactAggregateDeleteSummaryAction $buildContactAggregateDeleteSummaryAction,
        private readonly CleanupExternalDuplicateReviewsForDeletedAggregateAction $cleanupExternalDuplicateReviewsForDeletedAggregateAction,
        private readonly FindOpenCrossChannelIdentityAmbiguityReviewForContactsAction $findOpenCrossChannelIdentityAmbiguityReviewForContactsAction,
    ) {}

    public function handle(Contact|int $contact): void
    {
        $inputContactId = $contact instanceof Contact ? $contact->id : $contact;
        $deleteSummary = null;
        $cleanupSummary = null;

        try {
            DB::transaction(function () use ($contact, &$deleteSummary, &$cleanupSummary): void {
                $resolvedAggregate = $this->resolveContactAggregateAction->handle($contact);
                $deleteSummary = $this->buildContactAggregateDeleteSummaryAction->handle($resolvedAggregate);

                $lockedContacts = Contact::query()
                    ->whereKey($resolvedAggregate->aggregateContactIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($lockedContacts->count() !== count($resolvedAggregate->aggregateContactIds)) {
                    throw new RuntimeException('Contact aggregate changed during delete.');
                }

                $blockingReview = $this->findOpenCrossChannelIdentityAmbiguityReviewForContactsAction->handle(
                    $lockedContacts->all(),
                );

                if ($blockingReview !== null) {
                    throw ContactFrozenByOpenCrossChannelIdentityReviewException::forDelete($blockingReview);
                }

                $terminalBlockingReview = ContactDuplicateReview::query()
                    ->where('review_type', ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY)
                    ->whereIn('status', ContactDuplicateReview::terminalStatuses())
                    ->whereIn('contact_id', $resolvedAggregate->aggregateContactIds)
                    ->orderByDesc('resolved_at')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if ($terminalBlockingReview !== null) {
                    throw ContactPinnedByTerminalCrossChannelIdentityReviewException::forDelete($terminalBlockingReview);
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

                $cleanupSummary = $this->cleanupExternalDuplicateReviewsForDeletedAggregateAction->handle(
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

            Log::info('contact.aggregate_delete_succeeded', [
                ...$this->buildActorLogContext(),
                'input_contact_id' => $inputContactId,
                ...($deleteSummary ?? []),
                ...($cleanupSummary ?? []),
                'deleted_at' => now()->toIso8601String(),
            ]);
        } catch (BrokenContactMergeChainException $exception) {
            Log::error('contact.aggregate_delete_broken_merge_chain', [
                'contact_id' => $inputContactId,
                'error' => $exception->getMessage(),
            ]);
            Log::error('contact.aggregate_delete_failed', $this->buildFailureLogContext(
                inputContactId: $inputContactId,
                exception: $exception,
                deleteSummary: $deleteSummary,
            ));

            throw $exception;
        } catch (Throwable $exception) {
            Log::error('contact.aggregate_delete_failed', $this->buildFailureLogContext(
                inputContactId: $inputContactId,
                exception: $exception,
                deleteSummary: $deleteSummary,
            ));

            throw $exception;
        }
    }

    /**
     * @return array{actor_user_id:int|null,actor_user_name:string|null}
     */
    private function buildActorLogContext(): array
    {
        /** @var \App\Models\User|null $actor */
        $actor = auth()->user();

        return [
            'actor_user_id' => $actor?->id,
            'actor_user_name' => $actor?->name,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $deleteSummary
     * @return array<string, mixed>
     */
    private function buildFailureLogContext(int $inputContactId, Throwable $exception, ?array $deleteSummary): array
    {
        $context = [
            ...$this->buildActorLogContext(),
            'input_contact_id' => $inputContactId,
            'error_class' => $exception::class,
            'error_message' => $exception->getMessage(),
            'failed_at' => now()->toIso8601String(),
        ];

        if ($deleteSummary !== null) {
            $context += [
                'root_contact_id' => $deleteSummary['root_contact_id'] ?? null,
                'root_contact_label' => $deleteSummary['root_contact_label'] ?? null,
                'aggregate_contact_ids' => $deleteSummary['aggregate_contact_ids'] ?? null,
                'contacts_count' => $deleteSummary['contacts_count'] ?? null,
                'dialogs_count' => $deleteSummary['dialogs_count'] ?? null,
                'messages_count' => $deleteSummary['messages_count'] ?? null,
                'phones_count' => $deleteSummary['phones_count'] ?? null,
                'identities_count' => $deleteSummary['identities_count'] ?? null,
                'had_merge_history' => $deleteSummary['had_merge_history'] ?? null,
            ];
        }

        return $context;
    }
}
