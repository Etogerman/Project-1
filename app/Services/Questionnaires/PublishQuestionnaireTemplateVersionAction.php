<?php

namespace App\Services\Questionnaires;

use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishQuestionnaireTemplateVersionAction
{
    public function __construct(
        private readonly ValidateQuestionnaireFieldsPayloadAction $validateFieldsPayloadAction,
    ) {}

    public function handle(QuestionnaireTemplateVersion $version, ?User $user = null): QuestionnaireTemplateVersion
    {
        if ($version->status !== QuestionnaireTemplateVersion::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'version' => 'Опубликовать можно только черновик анкеты.',
            ]);
        }

        $fieldsPayload = $this->validateFieldsPayloadAction->handle($version->fields_payload);

        return DB::transaction(function () use ($version, $fieldsPayload, $user): QuestionnaireTemplateVersion {
            $version->refresh();
            $template = $version->template()->lockForUpdate()->first();

            if (! $template instanceof QuestionnaireTemplate) {
                throw ValidationException::withMessages([
                    'version' => 'Шаблон анкеты не найден.',
                ]);
            }

            $template->versions()
                ->where('status', QuestionnaireTemplateVersion::STATUS_PUBLISHED)
                ->whereKeyNot($version->getKey())
                ->update([
                    'status' => QuestionnaireTemplateVersion::STATUS_ARCHIVED,
                    'updated_at' => now(),
                ]);

            $version->forceFill([
                'status' => QuestionnaireTemplateVersion::STATUS_PUBLISHED,
                'fields_payload' => $fieldsPayload,
                'published_at' => now(),
            ])->save();

            $template->forceFill([
                'status' => QuestionnaireTemplate::STATUS_PUBLISHED,
                'published_version_id' => $version->id,
                'updated_by' => $user?->id,
            ])->save();

            return $version->fresh(['template']);
        });
    }
}
