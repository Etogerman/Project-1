<?php

namespace App\Services\Dialogs;

use App\Models\Contact;
use App\Models\Dialog;
use App\Services\Contacts\ResolveContactAggregateAction;
use App\Services\Contacts\ResolveRootContactAction;

class SyncDialogsStageForRootContactAction
{
    public function __construct(
        private readonly ResolveContactAggregateAction $resolveContactAggregateAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveDialogStageAction $resolveDialogStageAction,
    ) {}

    /**
     * @return array{
     *   dialogs_processed: int,
     *   dialogs_updated: int,
     *   dialogs_already_correct: int,
     * }
     */
    public function handle(Contact|int $contact, bool $apply = true): array
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
            $stage = $this->resolveDialogStageAction->handle($dialog, $rootContact);

            if ($dialog->stage === $stage) {
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
}
