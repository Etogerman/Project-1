<?php

namespace App\Console\Commands;

use App\Models\Dialog;
use App\Services\Contacts\ResolveContactAggregateAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\CreateDialogStageHistoryMessageAction;
use App\Services\Dialogs\ResolveDialogStageAction;
use Illuminate\Console\Command;

class AssignDialogsReviewStageCommand extends Command
{
    protected $signature = 'dialogs:assign-review-stage
        {--apply : Persist changes instead of running in dry-run mode}
        {--dry-run : Explicitly run without persisting changes}
        {--contact-id= : Restrict processing to a specific contact or root contact ID}';

    protected $description = 'Assign the review stage to existing dialogs.';

    public function __construct(
        private readonly ResolveDialogStageAction $resolveDialogStageAction,
        private readonly CreateDialogStageHistoryMessageAction $createDialogStageHistoryMessageAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveContactAggregateAction $resolveContactAggregateAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        $requestedContactId = $this->option('contact-id');

        $dialogsQuery = Dialog::query()
            ->with(['contact', 'channel', 'currentContactIdentity'])
            ->orderBy('id');

        if (filled($requestedContactId)) {
            $dialogsQuery->whereIn('contact_id', $this->resolveMemberContactIds((int) $requestedContactId));
        }

        if ($apply) {
            $dialogsQuery->lockForUpdate();
        }

        $dialogs = $dialogsQuery->get();

        $stats = [
            'dialogs_processed' => $dialogs->count(),
            'dialogs_updated' => 0,
            'dialogs_already_in_review' => 0,
            'history_rows_written' => 0,
        ];

        foreach ($dialogs as $dialog) {
            if ($dialog->stage === Dialog::STAGE_REQUIRES_REVIEW) {
                $stats['dialogs_already_in_review']++;

                continue;
            }

            $stats['dialogs_updated']++;

            if (! $apply) {
                continue;
            }

            $fromStage = $dialog->stage ?? $this->resolveDialogStageAction->handle($dialog);

            $dialog->forceFill([
                'stage' => Dialog::STAGE_REQUIRES_REVIEW,
            ])->save();

            $historyMessage = $this->createDialogStageHistoryMessageAction->handle(
                $dialog->fresh(['channel', 'currentContactIdentity']),
                $fromStage,
                Dialog::STAGE_REQUIRES_REVIEW,
                CreateDialogStageHistoryMessageAction::SOURCE_TYPE_SYSTEM,
            );

            if ($historyMessage !== null) {
                $stats['history_rows_written']++;
            }
        }

        $this->line($apply
            ? 'Dialog review stage assignment completed.'
            : 'Dialog review stage assignment dry-run completed.');

        $this->table(
            ['Metric', 'Count'],
            [
                ['dialogs_processed', $stats['dialogs_processed']],
                ['dialogs_updated', $stats['dialogs_updated']],
                ['dialogs_already_in_review', $stats['dialogs_already_in_review']],
                ['history_rows_written', $stats['history_rows_written']],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveMemberContactIds(int $contactId): array
    {
        $rootContact = $this->resolveRootContactAction->handle($contactId);

        return $this->resolveContactAggregateAction
            ->handle($rootContact)
            ->aggregateContactIds;
    }
}
