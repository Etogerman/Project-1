<?php

namespace App\Services\Contacts;

use App\Models\BotConstructorArrowRun;
use App\Models\BotConstructorDialogState;
use App\Models\BotConstructorExecution;
use App\Models\BotConstructorExecutionBlockRun;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactMergeLog;
use App\Models\Dialog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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

                $dialogIds = Dialog::query()
                    ->whereIn('contact_id', $resolvedAggregate->aggregateContactIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->all();

                $this->deleteBotConstructorRuntimeForDialogs($dialogIds);

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
     * @param  list<int>  $dialogIds
     */
    private function deleteBotConstructorRuntimeForDialogs(array $dialogIds): void
    {
        if ($dialogIds === []) {
            return;
        }

        $executionIds = BotConstructorExecution::query()
            ->whereIn('dialog_id', $dialogIds)
            ->orderByDesc('id')
            ->pluck('id')
            ->all();

        BotConstructorDialogState::query()
            ->whereIn('dialog_id', $dialogIds)
            ->delete();

        BotConstructorExecutionBlockRun::query()
            ->where(function ($query) use ($dialogIds, $executionIds): void {
                $query->whereIn('dialog_id', $dialogIds);

                if ($executionIds !== []) {
                    $query->orWhereIn('bot_constructor_execution_id', $executionIds);
                }
            })
            ->delete();

        BotConstructorArrowRun::query()
            ->where(function ($query) use ($dialogIds, $executionIds): void {
                $query->whereIn('dialog_id', $dialogIds);

                if ($executionIds !== []) {
                    $query->orWhereIn('bot_constructor_execution_id', $executionIds);
                }
            })
            ->delete();

        while ($executionIds !== []) {
            $deletableExecutionIds = BotConstructorExecution::query()
                ->whereIn('id', $executionIds)
                ->whereNotExists(function ($query) use ($executionIds): void {
                    $query
                        ->selectRaw('1')
                        ->from('bot_constructor_executions as child_executions')
                        ->whereColumn('child_executions.parent_execution_id', 'bot_constructor_executions.id')
                        ->whereIn('child_executions.id', $executionIds);
                })
                ->pluck('id')
                ->all();

            if ($deletableExecutionIds === []) {
                throw new RuntimeException('Unable to delete bot constructor executions for contact dialogs.');
            }

            BotConstructorExecution::query()
                ->whereIn('id', $deletableExecutionIds)
                ->delete();

            $executionIds = array_values(array_diff($executionIds, $deletableExecutionIds));
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
