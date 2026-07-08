<?php

namespace App\Services\Dialogs;

use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Scenarios\DispatchDialogStageChangedScenarioAction;
use Illuminate\Support\Carbon;

class CreateDialogStageHistoryMessageAction
{
    public const SOURCE_TYPE_SYSTEM = 'system';

    public const SOURCE_TYPE_OPERATOR = 'operator';

    public function __construct(
        private readonly DispatchDialogStageChangedScenarioAction $dispatchDialogStageChangedScenarioAction,
    ) {}

    public function handle(
        Dialog $dialog,
        ?string $fromStage,
        ?string $toStage,
        string $sourceType,
        ?User $changedBy = null,
    ): ?Message {
        if ($fromStage === null || $toStage === null || $fromStage === $toStage) {
            return null;
        }

        if (! $dialog->hasCompleteStageHistoryRouteContext()) {
            return null;
        }

        $occurredAt = now();
        $changedByUserId = $sourceType === self::SOURCE_TYPE_OPERATOR
            ? $changedBy?->id
            : null;

        $message = $dialog->messages()->create([
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_user_id' => $changedByUserId,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
            'external_chat_id' => (string) $dialog->external_chat_id,
            'text' => $this->buildHistoryText($sourceType, $changedBy, $fromStage, $toStage),
            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
            'raw_payload' => [
                'event' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
                'dialog_id' => $dialog->id,
                'from_stage' => $fromStage,
                'to_stage' => $toStage,
                'source_type' => $sourceType,
                'changed_by_user_id' => $changedByUserId,
                'occurred_at' => $occurredAt->toIso8601String(),
            ],
            'received_at' => $occurredAt,
        ]);

        $this->dispatchDialogStageChangedScenarioAction->handle($message);

        return $message;
    }

    private function buildHistoryText(
        string $sourceType,
        ?User $changedBy,
        string $fromStage,
        string $toStage,
    ): string {
        $fromLabel = Dialog::stageLabel($fromStage);
        $toLabel = Dialog::stageLabel($toStage);

        if ($sourceType === self::SOURCE_TYPE_OPERATOR) {
            return sprintf(
                'Оператор %s изменил этап диалога: %s -> %s',
                $changedBy?->name ?: 'без имени',
                $fromLabel,
                $toLabel,
            );
        }

        return sprintf(
            'Система изменила этап диалога: %s -> %s',
            $fromLabel,
            $toLabel,
        );
    }
}
