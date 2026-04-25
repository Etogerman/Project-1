<?php

namespace App\Services\Dialogs;

use App\Models\Contact;
use App\Models\Dialog;

class ResolveDialogStageAction
{
    public function handle(
        Dialog $dialog,
        ?Contact $contact = null,
    ): string
    {
        $contact ??= $dialog->relationLoaded('contact')
            ? $dialog->contact
            : $dialog->contact()->firstOrFail();

        return $this->forAttributes(
            currentStage: $dialog->stage,
            contact: $contact,
            phoneConfirmedAt: $dialog->phone_confirmed_at,
        );
    }

    public function forAttributes(
        ?string $currentStage,
        Contact $contact,
        mixed $phoneConfirmedAt,
    ): string
    {
        if ($this->isManualStage($currentStage)) {
            return $currentStage;
        }

        if ($this->isQuestionnaireCompleted($contact)) {
            return Dialog::STAGE_QUESTIONNAIRE_COMPLETED;
        }

        if ($phoneConfirmedAt !== null) {
            return Dialog::STAGE_PHONE_RECEIVED;
        }

        return Dialog::STAGE_NEW_DIALOG;
    }

    public function isManualStage(?string $stage): bool
    {
        return in_array($stage, Dialog::manualStages(), true);
    }

    private function isQuestionnaireCompleted(Contact $contact): bool
    {
        return $contact->data_collection_status === Contact::DATA_COLLECTION_STATUS_COMPLETED
            || $contact->data_collection_completed_at !== null;
    }
}
