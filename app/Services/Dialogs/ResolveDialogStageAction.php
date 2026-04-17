<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DialogStageData;
use App\Models\Contact;
use App\Models\Dialog;

class ResolveDialogStageAction
{
    public const REASON_CONTACT_HAS_PHONE = 'contact_has_phone';

    public const REASON_QUESTIONNAIRE_COMPLETED = 'questionnaire_completed';

    public function handle(Dialog $dialog): DialogStageData
    {
        $code = $this->resolveStageCode($dialog);

        return new DialogStageData(
            code: $code,
            label: Dialog::formatStageLabel($code),
            tone: Dialog::stageTone($code),
            isManual: Dialog::isManualStage($code),
        );
    }

    /**
     * @return array{code:string,reason_code:?string}
     */
    public function resolveAutomaticTransition(Dialog $dialog): array
    {
        $dialog->loadMissing([
            'contact.phoneNumbers',
        ]);

        $currentStageCode = Dialog::normalizeStageCode($dialog->stage_code);

        if (Dialog::isManualStage($currentStageCode)) {
            return [
                'code' => $currentStageCode,
                'reason_code' => null,
            ];
        }

        if ($this->contactQuestionnaireCompleted($dialog->contact)) {
            return [
                'code' => Dialog::STAGE_QUESTIONNAIRE_COMPLETED,
                'reason_code' => $currentStageCode !== Dialog::STAGE_QUESTIONNAIRE_COMPLETED
                    ? self::REASON_QUESTIONNAIRE_COMPLETED
                    : null,
            ];
        }

        if ($this->dialogHasPhone($dialog)) {
            return [
                'code' => Dialog::STAGE_PHONE_RECEIVED,
                'reason_code' => $currentStageCode !== Dialog::STAGE_PHONE_RECEIVED
                    ? self::REASON_CONTACT_HAS_PHONE
                    : null,
            ];
        }

        return [
            'code' => Dialog::STAGE_NEW_DIALOG,
            'reason_code' => null,
        ];
    }

    public function resolveStageCode(Dialog $dialog): string
    {
        return $this->resolveAutomaticTransition($dialog)['code'];
    }

    protected function dialogHasPhone(Dialog $dialog): bool
    {
        if (
            filled($dialog->confirmed_phone_raw)
            || filled($dialog->confirmed_phone_normalized)
            || $dialog->phone_confirmed_at !== null
        ) {
            return true;
        }

        $contact = $dialog->contact;

        if (! $contact instanceof Contact) {
            return false;
        }

        if ($contact->relationLoaded('phoneNumbers')) {
            return $contact->phoneNumbers->isNotEmpty();
        }

        return $contact->phoneNumbers()->exists();
    }

    protected function contactQuestionnaireCompleted(?Contact $contact): bool
    {
        if (! $contact instanceof Contact) {
            return false;
        }

        return $contact->data_collection_status === Contact::DATA_COLLECTION_STATUS_COMPLETED
            || $contact->data_collection_completed_at !== null;
    }
}
