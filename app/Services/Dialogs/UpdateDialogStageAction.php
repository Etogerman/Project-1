<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DialogStageUpdateResultData;
use App\Models\Dialog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDialogStageAction
{
    public function __construct(
        private readonly ResolveDialogStageAction $resolveDialogStageAction,
        private readonly CreateDialogStageHistoryMessageAction $createDialogStageHistoryMessageAction,
    ) {}

    public function handle(Dialog $dialog, User $employee, string $targetStage): DialogStageUpdateResultData
    {
        return DB::transaction(function () use ($dialog, $employee, $targetStage): DialogStageUpdateResultData {
            if (! $employee->canReplyInDialogs()) {
                throw new AuthorizationException('Недостаточно прав для смены этапа диалога.');
            }

            $dialog = Dialog::query()
                ->with(['contact', 'channel', 'currentContactIdentity'])
                ->whereKey($dialog->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentStage = $this->resolveDialogStageAction->handle($dialog);

            if (! Dialog::canManuallyTransition($currentStage, $targetStage)) {
                throw ValidationException::withMessages([
                    'dialogStageSelection' => 'Недопустимый ручной переход этапа.',
                ]);
            }

            if (! $dialog->hasCompleteStageHistoryRouteContext()) {
                throw ValidationException::withMessages([
                    'dialogStageSelection' => 'Ручная смена этапа недоступна, пока не заполнен полный route context канала.',
                ]);
            }

            if ($currentStage === $targetStage) {
                return new DialogStageUpdateResultData(
                    stage: $currentStage,
                    historyMessage: null,
                );
            }

            $dialog->forceFill([
                'stage' => $targetStage,
            ])->save();

            $historyMessage = $this->createDialogStageHistoryMessageAction->handle(
                $dialog->fresh(['channel', 'currentContactIdentity']),
                $currentStage,
                $targetStage,
                CreateDialogStageHistoryMessageAction::SOURCE_TYPE_OPERATOR,
                $employee,
            );

            return new DialogStageUpdateResultData(
                stage: $targetStage,
                historyMessage: $historyMessage,
            );
        });
    }
}
