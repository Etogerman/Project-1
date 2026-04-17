<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DialogStageData;
use App\Data\Dialogs\DialogStageUpdateResultData;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDialogStageAction
{
    public function __construct(
        private readonly ResolveDialogStageAction $resolveDialogStageAction,
    ) {}

    public function handle(Dialog $dialog, User $employee, string $targetStageCode): DialogStageUpdateResultData
    {
        return DB::transaction(function () use ($dialog, $employee, $targetStageCode): DialogStageUpdateResultData {
            $dialog = Dialog::query()
                ->with(['contact.phoneNumbers', 'contact.primaryIdentity', 'currentContactIdentity'])
                ->whereKey($dialog->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! array_key_exists($targetStageCode, Dialog::manualStageOptions())) {
                throw ValidationException::withMessages([
                    'dialogStageSelection' => 'Недопустимое значение стадии.',
                ]);
            }

            $currentStage = $this->resolveDialogStageAction->handle($dialog);

            if ($dialog->stage_code !== $currentStage->code) {
                $dialog->forceFill([
                    'stage_code' => $currentStage->code,
                ])->save();
            }

            if ($currentStage->code === $targetStageCode) {
                return new DialogStageUpdateResultData(
                    stage: $currentStage,
                    historyMessage: null,
                );
            }

            $dialog->forceFill([
                'stage_code' => $targetStageCode,
            ])->save();

            $targetStage = $this->makeStageData($targetStageCode);
            $historyMessage = $this->createHistoryMessage($dialog, $employee, $currentStage, $targetStage);

            return new DialogStageUpdateResultData(
                stage: $targetStage,
                historyMessage: $historyMessage,
            );
        });
    }

    protected function createHistoryMessage(
        Dialog $dialog,
        User $employee,
        DialogStageData $fromStage,
        DialogStageData $toStage,
    ): ?Message {
        $contactIdentityId = $this->resolveHistoryContactIdentityId($dialog);

        if ($contactIdentityId === null) {
            return null;
        }

        $changedAt = now();

        return $dialog->messages()->create([
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $contactIdentityId,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STAGE_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_user_id' => $employee->id,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
            'reply_to_message_id' => null,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => sprintf(
                'Оператор %s изменил стадию диалога: %s -> %s',
                $employee->name ?: 'без имени',
                $fromStage->label,
                $toStage->label,
            ),
            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
            'raw_payload' => [
                'event' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
                'from_stage' => [
                    'code' => $fromStage->code,
                    'label' => $fromStage->label,
                ],
                'to_stage' => [
                    'code' => $toStage->code,
                    'label' => $toStage->label,
                ],
                'dialog_id' => $dialog->id,
                'changed_by_user_id' => $employee->id,
            ],
            'received_at' => $changedAt,
        ]);
    }

    protected function resolveHistoryContactIdentityId(Dialog $dialog): ?int
    {
        $contactIdentityId = $dialog->current_contact_identity_id
            ?? $dialog->contact?->primaryIdentity?->id;

        return is_numeric($contactIdentityId)
            ? (int) $contactIdentityId
            : null;
    }

    protected function makeStageData(string $stageCode): DialogStageData
    {
        return new DialogStageData(
            code: $stageCode,
            label: Dialog::formatStageLabel($stageCode),
            tone: Dialog::stageTone($stageCode),
            isManual: Dialog::isManualStage($stageCode),
        );
    }
}
