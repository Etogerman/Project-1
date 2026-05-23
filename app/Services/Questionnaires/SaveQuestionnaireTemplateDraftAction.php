<?php

namespace App\Services\Questionnaires;

use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveQuestionnaireTemplateDraftAction
{
    public function __construct(
        private readonly ValidateQuestionnaireFieldsPayloadAction $validateFieldsPayloadAction,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $fieldsPayload
     */
    public function handle(
        QuestionnaireTemplate $template,
        array $fieldsPayload,
        ?User $user = null,
        string $validationAttribute = 'fields_payload',
    ): QuestionnaireTemplateVersion {
        $fieldsPayload = $this->validateFieldsPayloadAction->handle($fieldsPayload, $validationAttribute);

        return DB::transaction(function () use ($template, $fieldsPayload, $user): QuestionnaireTemplateVersion {
            $template->refresh();

            $draftVersion = $template->versions()
                ->where('status', QuestionnaireTemplateVersion::STATUS_DRAFT)
                ->orderByDesc('version')
                ->first();

            if (! $draftVersion instanceof QuestionnaireTemplateVersion) {
                $nextVersion = ((int) $template->versions()->max('version')) + 1;

                $draftVersion = $template->versions()->create([
                    'version' => $nextVersion,
                    'status' => QuestionnaireTemplateVersion::STATUS_DRAFT,
                    'fields_payload' => $fieldsPayload,
                    'published_at' => null,
                    'created_by' => $user?->id,
                ]);
            } else {
                $draftVersion->forceFill([
                    'fields_payload' => $fieldsPayload,
                    'published_at' => null,
                ])->save();
            }

            if ($template->published_version_id === null) {
                $template->forceFill([
                    'status' => QuestionnaireTemplate::STATUS_DRAFT,
                    'updated_by' => $user?->id,
                ])->save();
            } elseif ($user instanceof User) {
                $template->forceFill([
                    'updated_by' => $user->id,
                ])->save();
            }

            return $draftVersion->fresh(['template']);
        });
    }
}
