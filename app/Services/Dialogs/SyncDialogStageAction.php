<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DialogStageData;
use App\Data\Dialogs\DialogStageUpdateResultData;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class SyncDialogStageAction
{
    public function __construct(
        private readonly ResolveDialogStageAction $resolveDialogStageAction,
    ) {}

    public function handle(Dialog $dialog, bool $writeHistory = true): DialogStageUpdateResultData
    {
        return DB::transaction(function () use ($dialog, $writeHistory): DialogStageUpdateResultData {
            $dialog = Dialog::query()
                ->with(['contact.phoneNumbers', 'contact.primaryIdentity', 'currentContactIdentity'])
                ->whereKey($dialog->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentStageCode = Dialog::normalizeStageCode($dialog->stage_code);
            $currentStage = $this->makeStageData($currentStageCode);
            $resolvedTransition = $this->resolveDialogStageAction->resolveAutomaticTransition($dialog);
            $targetStage = $this->makeStageData($resolvedTransition['code']);

            if ($currentStage->code === $targetStage->code) {
                if ($dialog->stage_code !== $currentStage->code) {
                    $dialog->forceFill([
                        'stage_code' => $currentStage->code,
                    ])->save();
                }

                return new DialogStageUpdateResultData(
                    stage: $targetStage,
                    historyMessage: null,
                );
            }

            $dialog->forceFill([
                'stage_code' => $targetStage->code,
            ])->save();

            $historyMessage = null;

            if ($writeHistory) {
                $historyMessage = $this->createHistoryMessage(
                    dialog: $dialog,
                    fromStage: $currentStage,
                    toStage: $targetStage,
                    reasonCode: $resolvedTransition['reason_code'],
                );
            }

            return new DialogStageUpdateResultData(
                stage: $targetStage,
                historyMessage: $historyMessage,
            );
        });
    }

    protected function createHistoryMessage(
        Dialog $dialog,
        DialogStageData $fromStage,
        DialogStageData $toStage,
        ?string $reasonCode,
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
            'sent_by_user_id' => null,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
            'reply_to_message_id' => null,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => sprintf(
                'Система изменила стадию диалога: %s -> %s',
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
                'reason_code' => $reasonCode,
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
