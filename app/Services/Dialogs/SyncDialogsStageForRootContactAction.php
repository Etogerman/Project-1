<?php

namespace App\Services\Dialogs;

use App\Models\Contact;
use App\Models\Dialog;
use App\Services\Contacts\ResolveContactAggregateAction;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Support\Facades\DB;

class SyncDialogsStageForRootContactAction
{
    public function __construct(
        private readonly ResolveContactAggregateAction $resolveContactAggregateAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveDialogStageAction $resolveDialogStageAction,
        private readonly CreateDialogStageHistoryMessageAction $createDialogStageHistoryMessageAction,
    ) {}

    /**
     * @return array{
     *   dialogs_processed: int,
     *   dialogs_updated: int,
     *   dialogs_already_correct: int,
     * }
     */
    public function handle(
        Contact|int $contact,
        bool $apply = true,
        bool $writeHistory = true,
        ?Contact $historySourceContact = null,
    ): array
    {
        if ($apply) {
            return DB::transaction(fn (): array => $this->syncStages(
                contact: $contact,
                apply: true,
                writeHistory: $writeHistory,
                historySourceContact: $historySourceContact,
            ));
        }

        return $this->syncStages(
            contact: $contact,
            apply: false,
            writeHistory: $writeHistory,
            historySourceContact: $historySourceContact,
        );
    }

    /**
     * @return array{
     *   dialogs_processed: int,
     *   dialogs_updated: int,
     *   dialogs_already_correct: int,
     * }
     */
    private function syncStages(
        Contact|int $contact,
        bool $apply,
        bool $writeHistory,
        ?Contact $historySourceContact,
    ): array
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $memberContactIds = $this->resolveMemberContactIds($rootContact);

        $dialogsQuery = Dialog::query()
            ->whereIn('contact_id', $memberContactIds)
            ->orderBy('id');

        if ($apply) {
            $dialogsQuery->lockForUpdate();
        }

        $dialogs = $dialogsQuery->get();

        $stats = [
            'dialogs_processed' => $dialogs->count(),
            'dialogs_updated' => 0,
            'dialogs_already_correct' => 0,
        ];

        foreach ($dialogs as $dialog) {
            $fromStage = $this->resolveHistoryFromStage(
                $dialog,
                $historySourceContact ?? $rootContact,
            );
            $stage = $this->resolveDialogStageAction->handle(
                $dialog,
                $rootContact,
            );
            $needsPersistedStageRewrite = $dialog->stage !== $stage;

            if (! $needsPersistedStageRewrite && $fromStage === $stage) {
                $stats['dialogs_already_correct']++;

                continue;
            }

            $stats['dialogs_updated']++;

            if (! $apply) {
                continue;
            }

            $dialog->forceFill([
                'stage' => $stage,
            ])->save();

            if ($writeHistory && $fromStage !== $stage) {
                $this->createDialogStageHistoryMessageAction->handle(
                    $dialog->fresh(['channel', 'currentContactIdentity']),
                    $fromStage,
                    $stage,
                    CreateDialogStageHistoryMessageAction::SOURCE_TYPE_SYSTEM,
                );
            }
        }

        return $stats;
    }

    /**
     * @return list<int>
     */
    private function resolveMemberContactIds(Contact $rootContact): array
    {
        return $this->resolveContactAggregateAction
            ->handle($rootContact)
            ->aggregateContactIds;
    }

    private function resolveHistoryFromStage(Dialog $dialog, Contact $historySourceContact): string
    {
        return $this->resolveDialogStageAction->handle($dialog, $historySourceContact);
    }
}
