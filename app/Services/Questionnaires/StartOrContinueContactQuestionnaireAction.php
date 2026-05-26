<?php

namespace App\Services\Questionnaires;

use App\Data\Questionnaires\QuestionnaireStartResult;
use App\Models\Contact;
use App\Models\ContactQuestionnaireAnswer;
use App\Models\ContactQuestionnaireRun;
use App\Models\Message;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateVersion;
use App\Models\ScenarioRun;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\DataCollection\DataCollectionPromptHelper;
use App\Services\Scenarios\ScenarioEdgeExpressionCondition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class StartOrContinueContactQuestionnaireAction
{
    private const FIELD_KEY_RUSSIAN_REGION_CONFIRM = 'russian_region_confirm';

    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ScenarioEdgeExpressionCondition $expressionCondition,
        private readonly DataCollectionPromptHelper $dataCollectionPromptHelper,
    ) {}

    public function handle(
        Message $message,
        string $templateKey = QuestionnaireTemplate::KEY_PROFILE,
        ?ScenarioRun $scenarioRun = null,
        ?string $blockId = null,
    ): QuestionnaireStartResult {
        if (! $message->contact instanceof Contact || $message->dialog_id === null) {
            return new QuestionnaireStartResult(
                outcome: QuestionnaireStartResult::OUTCOME_FAILED,
                error: 'questionnaire requires contact and dialog context',
            );
        }

        return DB::transaction(fn (): QuestionnaireStartResult => $this->handleLocked(
            message: $message,
            templateKey: $templateKey,
            scenarioRun: $scenarioRun,
            blockId: $blockId,
        ));
    }

    private function handleLocked(
        Message $message,
        string $templateKey,
        ?ScenarioRun $scenarioRun,
        ?string $blockId,
    ): QuestionnaireStartResult {
        $contact = $this->resolveRootContactAction->handle($message->contact);
        $lockedContact = Contact::query()
            ->whereKey($contact->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedContact instanceof Contact) {
            return new QuestionnaireStartResult(
                outcome: QuestionnaireStartResult::OUTCOME_FAILED,
                error: 'contact not found',
            );
        }

        $message->setRelation('contact', $lockedContact);

        $template = QuestionnaireTemplate::query()
            ->where('key', QuestionnaireTemplate::normalizeKey($templateKey))
            ->where('status', QuestionnaireTemplate::STATUS_PUBLISHED)
            ->with('publishedVersion')
            ->lockForUpdate()
            ->first();

        if (! $template instanceof QuestionnaireTemplate || ! $template->publishedVersion instanceof QuestionnaireTemplateVersion) {
            return new QuestionnaireStartResult(
                outcome: QuestionnaireStartResult::OUTCOME_FAILED,
                error: 'published questionnaire template not found',
            );
        }

        $run = $this->activeRun($lockedContact, $template);
        $version = $run instanceof ContactQuestionnaireRun
            ? $run->templateVersion()->first()
            : $template->publishedVersion;

        if (! $version instanceof QuestionnaireTemplateVersion) {
            return new QuestionnaireStartResult(
                outcome: QuestionnaireStartResult::OUTCOME_FAILED,
                runId: $run instanceof ContactQuestionnaireRun ? (int) $run->id : null,
                error: 'questionnaire version not found',
            );
        }

        if ($run instanceof ContactQuestionnaireRun && $run->status === ContactQuestionnaireRun::STATUS_AWAITING_ANSWER) {
            $run->forceFill([
                'last_dialog_id' => $message->dialog_id,
                'scenario_run_id' => $scenarioRun?->id ?? $run->scenario_run_id,
                'awaiting_block_id' => $blockId ?: $run->awaiting_block_id,
            ])->save();

            return $this->waitingResultForRun($run, $version);
        }

        if (! $run instanceof ContactQuestionnaireRun) {
            if ($this->hasCompletedRunForVersion($lockedContact, $template, $version)) {
                return new QuestionnaireStartResult(
                    outcome: QuestionnaireStartResult::OUTCOME_ALREADY_COMPLETED,
                );
            }

            $run = ContactQuestionnaireRun::query()->create([
                'contact_id' => $lockedContact->id,
                'questionnaire_template_id' => $template->id,
                'questionnaire_template_version_id' => $version->id,
                'status' => ContactQuestionnaireRun::STATUS_IN_PROGRESS,
                'current_field_key' => null,
                'started_dialog_id' => $message->dialog_id,
                'last_dialog_id' => $message->dialog_id,
                'started_by_block_id' => $blockId,
                'awaiting_block_id' => null,
                'scenario_run_id' => $scenarioRun?->id,
                'started_at' => now(),
            ]);
        }

        $nextField = $this->nextRequiredField($run, $version, $message, $lockedContact);

        if ($run->fresh()->status === ContactQuestionnaireRun::STATUS_FAILED) {
            return new QuestionnaireStartResult(
                outcome: QuestionnaireStartResult::OUTCOME_FAILED,
                runId: (int) $run->id,
                error: 'questionnaire required_when failed at runtime',
            );
        }

        if ($nextField === null) {
            $run->forceFill([
                'status' => ContactQuestionnaireRun::STATUS_COMPLETED,
                'current_field_key' => null,
                'awaiting_block_id' => null,
                'last_dialog_id' => $message->dialog_id,
                'scenario_run_id' => $scenarioRun?->id ?? $run->scenario_run_id,
                'completed_at' => now(),
            ])->save();

            if ($template->key === QuestionnaireTemplate::KEY_PROFILE) {
                $lockedContact->completeDataCollection();
            }

            return new QuestionnaireStartResult(
                outcome: QuestionnaireStartResult::OUTCOME_COMPLETED,
                runId: (int) $run->id,
            );
        }

        $answer = $this->answerForField($run, $nextField);
        $promptText = $this->promptText($nextField, (int) $answer->attempts_count);

        $answer->forceFill([
            'status' => ContactQuestionnaireAnswer::STATUS_ASKED,
            'target' => is_string($nextField['target'] ?? null) ? (string) $nextField['target'] : null,
        ])->save();

        $run->forceFill([
            'status' => ContactQuestionnaireRun::STATUS_AWAITING_ANSWER,
            'current_field_key' => (string) $nextField['field_key'],
            'last_dialog_id' => $message->dialog_id,
            'awaiting_block_id' => $blockId,
            'scenario_run_id' => $scenarioRun?->id ?? $run->scenario_run_id,
        ])->save();

        return new QuestionnaireStartResult(
            outcome: QuestionnaireStartResult::OUTCOME_WAITING,
            runId: (int) $run->id,
            currentFieldKey: (string) $nextField['field_key'],
            promptText: $promptText,
            options: $this->fieldOptions($nextField),
        );
    }

    private function activeRun(Contact $contact, QuestionnaireTemplate $template): ?ContactQuestionnaireRun
    {
        return ContactQuestionnaireRun::query()
            ->where('contact_id', $contact->id)
            ->where('questionnaire_template_id', $template->id)
            ->whereIn('status', [
                ContactQuestionnaireRun::STATUS_IN_PROGRESS,
                ContactQuestionnaireRun::STATUS_AWAITING_ANSWER,
                ContactQuestionnaireRun::STATUS_PAUSED,
            ])
            ->lockForUpdate()
            ->orderBy('id')
            ->first();
    }

    private function hasCompletedRunForVersion(
        Contact $contact,
        QuestionnaireTemplate $template,
        QuestionnaireTemplateVersion $version,
    ): bool {
        return ContactQuestionnaireRun::query()
            ->where('contact_id', $contact->id)
            ->where('questionnaire_template_id', $template->id)
            ->where('questionnaire_template_version_id', $version->id)
            ->where('status', ContactQuestionnaireRun::STATUS_COMPLETED)
            ->exists();
    }

    private function waitingResultForRun(
        ContactQuestionnaireRun $run,
        QuestionnaireTemplateVersion $version,
    ): QuestionnaireStartResult {
        $contact = $run->contact()->first();
        $field = $this->fieldByKey($version, (string) $run->current_field_key);

        if ($field === null && $contact instanceof Contact) {
            $field = $this->russianRegionConfirmField($contact, $run, includeTerminalAnswer: true);
        }

        if ($field === null) {
            return new QuestionnaireStartResult(
                outcome: QuestionnaireStartResult::OUTCOME_FAILED,
                runId: (int) $run->id,
                error: 'current questionnaire field not found',
            );
        }

        $answer = $this->answerForField($run, $field);

        return new QuestionnaireStartResult(
            outcome: QuestionnaireStartResult::OUTCOME_WAITING,
            runId: (int) $run->id,
            currentFieldKey: (string) $field['field_key'],
            promptText: $this->promptText($field, (int) $answer->attempts_count),
            options: $this->fieldOptions($field),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nextRequiredField(
        ContactQuestionnaireRun $run,
        QuestionnaireTemplateVersion $version,
        Message $message,
        Contact $contact,
    ): ?array {
        foreach ($this->fields($version) as $field) {
            $fieldKey = (string) ($field['field_key'] ?? '');
            $answer = $this->answerForField($run, $field);

            if (in_array($answer->status, [
                ContactQuestionnaireAnswer::STATUS_FILLED,
                ContactQuestionnaireAnswer::STATUS_SKIPPED,
            ], true)) {
                if ($fieldKey === 'city' && ($regionField = $this->russianRegionConfirmField($contact, $run)) !== null) {
                    return $regionField;
                }

                continue;
            }

            try {
                $required = $this->fieldIsRequired($field, $message);
            } catch (Throwable $throwable) {
                $this->failRunForBrokenRequiredWhen($run, $field, $throwable);

                return null;
            }

            if (! $required) {
                $this->markFieldSkipped($answer, $field);

                if ($fieldKey === 'city' && ($regionField = $this->russianRegionConfirmField($contact, $run)) !== null) {
                    return $regionField;
                }

                continue;
            }

            return $field;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(QuestionnaireTemplateVersion $version): array
    {
        $fields = is_array($version->fields_payload) ? $version->fields_payload : [];

        return array_values(array_filter($fields, static fn (mixed $field): bool => is_array($field)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fieldByKey(QuestionnaireTemplateVersion $version, string $fieldKey): ?array
    {
        foreach ($this->fields($version) as $field) {
            if ((string) ($field['field_key'] ?? '') === $fieldKey) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function answerForField(ContactQuestionnaireRun $run, array $field): ContactQuestionnaireAnswer
    {
        return ContactQuestionnaireAnswer::query()->firstOrCreate(
            [
                'questionnaire_run_id' => $run->id,
                'field_key' => (string) $field['field_key'],
            ],
            [
                'status' => ContactQuestionnaireAnswer::STATUS_PENDING,
                'attempts_count' => 0,
                'target' => is_string($field['target'] ?? null) ? (string) $field['target'] : null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function fieldIsRequired(array $field, Message $message): bool
    {
        if (($field['required'] ?? true) !== true) {
            return false;
        }

        $requiredWhen = trim((string) ($field['required_when'] ?? ''));

        if ($requiredWhen === '') {
            return true;
        }

        return $this->expressionCondition->evaluate($requiredWhen, $message);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function failRunForBrokenRequiredWhen(ContactQuestionnaireRun $run, array $field, Throwable $throwable): void
    {
        $fieldKey = (string) ($field['field_key'] ?? '');
        $answer = $this->answerForField($run, $field);

        $answer->forceFill([
            'status' => ContactQuestionnaireAnswer::STATUS_FAILED,
        ])->save();

        $run->forceFill([
            'status' => ContactQuestionnaireRun::STATUS_FAILED,
            'current_field_key' => $fieldKey !== '' ? $fieldKey : null,
            'awaiting_block_id' => null,
        ])->save();

        Log::warning('questionnaire.required_when_runtime_error', [
            'questionnaire_run_id' => $run->id,
            'field_key' => $fieldKey,
            'exception' => get_class($throwable),
            'error_message' => $throwable->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function markFieldSkipped(ContactQuestionnaireAnswer $answer, array $field): void
    {
        $answer->forceFill([
            'status' => ContactQuestionnaireAnswer::STATUS_SKIPPED,
            'target' => is_string($field['target'] ?? null) ? (string) $field['target'] : null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function promptText(array $field, int $attemptsCount): string
    {
        $prompts = collect(is_array($field['prompts'] ?? null) ? $field['prompts'] : [])
            ->filter(fn (mixed $prompt): bool => is_string($prompt) && trim($prompt) !== '')
            ->values();

        if ($prompts->isEmpty()) {
            return (string) ($field['label'] ?? 'Ответь на вопрос анкеты');
        }

        $index = min($attemptsCount, $prompts->count() - 1);

        return (string) $prompts->get($index);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<array{value:string,label:string}>
     */
    private function fieldOptions(array $field): array
    {
        if (($field['type'] ?? null) !== 'choice') {
            return [];
        }

        return collect(is_array($field['options'] ?? null) ? $field['options'] : [])
            ->filter(fn (mixed $option): bool => is_array($option)
                && is_string($option['value'] ?? null)
                && trim((string) $option['value']) !== ''
                && is_string($option['label'] ?? null)
                && trim((string) $option['label']) !== '')
            ->map(fn (array $option): array => [
                'value' => trim((string) $option['value']),
                'label' => trim((string) $option['label']),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function russianRegionConfirmField(
        Contact $contact,
        ContactQuestionnaireRun $run,
        bool $includeTerminalAnswer = false,
    ): ?array {
        if (! $this->dataCollectionPromptHelper->shouldAskRussianRegionConfirmation($contact)) {
            return null;
        }

        $candidates = $this->dataCollectionPromptHelper->russianRegionCandidates($contact);

        if ($candidates === []) {
            return null;
        }

        $field = [
            'field_key' => self::FIELD_KEY_RUSSIAN_REGION_CONFIRM,
            'label' => 'Регион',
            'type' => count($candidates) <= 4 ? 'choice' : 'russian_region_confirm',
            'required' => true,
            'allow_skip' => true,
            'max_attempts' => 3,
            'target' => 'contact.region',
            'overwrite_contact' => true,
            'prompts' => [
                $this->dataCollectionPromptHelper->russianRegionConfirmQuestionText($contact),
                $this->dataCollectionPromptHelper->russianRegionConfirmRetryText($contact),
                $this->dataCollectionPromptHelper->russianRegionConfirmRetryText($contact),
            ],
            'options' => count($candidates) <= 4
                ? array_map(
                    static fn (string $candidate): array => ['value' => $candidate, 'label' => $candidate],
                    $candidates,
                )
                : [],
        ];

        $answer = $this->answerForField($run, $field);

        if (! $includeTerminalAnswer && in_array($answer->status, [
            ContactQuestionnaireAnswer::STATUS_FILLED,
            ContactQuestionnaireAnswer::STATUS_SKIPPED,
            ContactQuestionnaireAnswer::STATUS_FAILED,
        ], true)) {
            return null;
        }

        return $field;
    }
}
