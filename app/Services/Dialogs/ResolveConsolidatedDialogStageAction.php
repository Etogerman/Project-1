<?php

namespace App\Services\Dialogs;

use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Support\Collection;

class ResolveConsolidatedDialogStageAction
{
    public function __construct(
        private readonly ResolveDialogStageAction $resolveDialogStageAction,
        private readonly MessageChronology $messageChronology,
    ) {}

    /**
     * @param  Collection<int, Dialog>  $dialogs
     * @param  Collection<int, Message>  $messages
     */
    public function handle(
        Contact $rootContact,
        Dialog $survivingDialog,
        Collection $dialogs,
        Collection $messages,
        mixed $phoneConfirmedAt,
    ): string {
        if ($survivingDialog->stage === Dialog::STAGE_REQUIRES_REVIEW) {
            return Dialog::STAGE_REQUIRES_REVIEW;
        }

        if (Dialog::isManualStage($survivingDialog->stage)) {
            return (string) $survivingDialog->stage;
        }

        $redundantManualDialogs = $dialogs
            ->filter(fn (Dialog $dialog): bool => $dialog->id !== $survivingDialog->id)
            ->filter(fn (Dialog $dialog): bool => Dialog::isManualStage($dialog->stage))
            ->values();

        if ($redundantManualDialogs->isNotEmpty()) {
            return $this->resolveWinningManualStage($redundantManualDialogs, $messages);
        }

        return $this->resolveDialogStageAction->forAttributes(
            currentStage: $survivingDialog->stage,
            contact: $rootContact,
            phoneConfirmedAt: $phoneConfirmedAt,
        );
    }

    /**
     * @param  Collection<int, Dialog>  $manualDialogs
     * @param  Collection<int, Message>  $messages
     */
    private function resolveWinningManualStage(Collection $manualDialogs, Collection $messages): string
    {
        /** @var Dialog $winner */
        $winner = $manualDialogs
            ->sort(function (Dialog $left, Dialog $right) use ($messages): int {
                $leftTuple = $this->resolveLatestManualHistoryTuple($left, $messages);
                $rightTuple = $this->resolveLatestManualHistoryTuple($right, $messages);
                $comparison = $this->messageChronology->compareSortTuple(
                    $leftTuple['occurred_at'],
                    $leftTuple['dialog_id'],
                    $rightTuple['occurred_at'],
                    $rightTuple['dialog_id'],
                );

                if ($comparison !== 0) {
                    return $comparison;
                }

                return $left->id <=> $right->id;
            })
            ->last();

        return (string) $winner->stage;
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return array{occurred_at:mixed,dialog_id:int}
     */
    private function resolveLatestManualHistoryTuple(Dialog $dialog, Collection $messages): array
    {
        /** @var ?Message $latestHistory */
        $latestHistory = $messages
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE)
            ->where('sent_by_system_code', Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE)
            ->sortBy(fn (Message $message): string => $this->messageChronology->timestampAndIdSortKey(
                $message->raw_payload['occurred_at'] ?? $message->received_at,
                $message->id,
            ))
            ->last();

        return [
            'occurred_at' => $latestHistory?->raw_payload['occurred_at'] ?? null,
            'dialog_id' => $dialog->id,
        ];
    }
}
