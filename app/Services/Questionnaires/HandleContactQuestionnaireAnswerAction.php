<?php

namespace App\Services\Questionnaires;

use App\Data\Questionnaires\QuestionnaireStartResult;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\ContactQuestionnaireAnswer;
use App\Models\ContactQuestionnaireAttempt;
use App\Models\ContactQuestionnaireRun;
use App\Models\Message;
use App\Models\QuestionnaireTemplateVersion;
use App\Models\ScenarioRun;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use App\Services\Contacts\AddContactPhoneAction;
use App\Services\Contacts\ApplyContactFirstNameAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\DataCollection\DataCollectionPromptHelper;
use App\Services\DataCollection\ExtractFirstNameAction;
use App\Services\DataCollection\ExtractResidenceCityAction;
use App\Services\DataCollection\ResolveRussianRegionCandidatesLookupAction;
use App\Services\Scenarios\GenericDbScenarioRuntime;
use App\Services\Scenarios\LookupScenarioDataDictionaryAction;
use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HandleContactQuestionnaireAnswerAction
{
    private const FIELD_KEY_RUSSIAN_REGION_CONFIRM = 'russian_region_confirm';

    /**
     * @var list<string>
     */
    private const CANCEL_COMMANDS = ['стоп', 'отмена'];

    /**
     * @var list<string>
     */
    private const OPERATOR_COMMANDS = ['оператор'];

    /**
     * @var list<string>
     */
    private const SKIP_COMMANDS = ['пропустить', 'skip'];

    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly StartOrContinueContactQuestionnaireAction $startOrContinueContactQuestionnaireAction,
        private readonly LookupScenarioDataDictionaryAction $lookupScenarioDataDictionaryAction,
        private readonly ApplyContactFirstNameAction $applyContactFirstNameAction,
        private readonly AddContactPhoneAction $addContactPhoneAction,
        private readonly QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
        private readonly ResolveRussianRegionCandidatesLookupAction $resolveRussianRegionCandidatesLookupAction,
        private readonly ExtractResidenceCityAction $extractResidenceCityAction,
        private readonly ExtractFirstNameAction $extractFirstNameAction,
        private readonly DataCollectionPromptHelper $dataCollectionPromptHelper,
        private readonly ScenarioRegistry $scenarioRegistry,
    ) {}

    public function handle(Message $message): bool
    {
        if (! $this->questionnaireEngineEnabled() || $message->message_kind !== Message::KIND_INBOUND_USER) {
            return false;
        }

        $message->loadMissing(['contact', 'channel', 'dialog']);

        if (! $message->contact instanceof Contact || $message->dialog_id === null) {
            return false;
        }

        $result = DB::transaction(fn (): array => $this->handleLocked($message));

        if (($result['handled'] ?? false) !== true) {
            return false;
        }

        if (($result['continue'] ?? false) === true) {
            $this->continueAfterHandledAnswer($message, $result);

            return true;
        }

        if (is_string($result['resume_outcome'] ?? null)) {
            $this->resumeScenario($message, $result, (string) $result['resume_outcome']);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function handleLocked(Message $message): array
    {
        $contact = $this->resolveRootContactAction->handle($message->contact);
        $lockedContact = Contact::query()
            ->whereKey($contact->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedContact instanceof Contact) {
            return ['handled' => false];
        }

        $message->setRelation('contact', $lockedContact);

        $run = ContactQuestionnaireRun::query()
            ->with(['template', 'templateVersion', 'scenarioRun'])
            ->where('contact_id', $lockedContact->id)
            ->where('status', ContactQuestionnaireRun::STATUS_AWAITING_ANSWER)
            ->lockForUpdate()
            ->orderBy('id')
            ->first();

        if (! $run instanceof ContactQuestionnaireRun) {
            return ['handled' => false];
        }

        if ($this->messageAlreadyHandled($run, $message)) {
            return ['handled' => true];
        }

        $version = $run->templateVersion;
        $field = $version instanceof QuestionnaireTemplateVersion
            ? $this->fieldByKey($version, (string) $run->current_field_key, $lockedContact)
            : null;

        if ($field === null) {
            $this->failRun($run, 'current questionnaire field not found');

            return $this->handledTerminal($run, QuestionnaireStartResult::OUTCOME_FAILED);
        }

        $answer = $this->answerForField($run, $field);
        $attemptIndex = (int) $answer->attempts_count + 1;
        $rawAnswer = $this->rawAnswerText($message);
        $normalizedCommand = $this->normalizeAnswer($rawAnswer);
        $promptText = $this->promptText($field, (int) $answer->attempts_count);

        if (in_array($normalizedCommand, self::CANCEL_COMMANDS, true)) {
            $this->storeAttempt($run, $field, $attemptIndex, $message, $promptText, $rawAnswer, null, ContactQuestionnaireAttempt::STATUS_CANCELLED);
            $run->forceFill([
                'status' => ContactQuestionnaireRun::STATUS_CANCELLED,
                'current_field_key' => null,
                'awaiting_block_id' => null,
                'last_dialog_id' => $message->dialog_id,
                'cancelled_at' => now(),
            ])->save();

            return $this->handledTerminal($run, QuestionnaireStartResult::OUTCOME_CANCELLED);
        }

        if (in_array($normalizedCommand, self::OPERATOR_COMMANDS, true)) {
            $this->storeAttempt($run, $field, $attemptIndex, $message, $promptText, $rawAnswer, null, ContactQuestionnaireAttempt::STATUS_OPERATOR_REQUESTED);
            $run->forceFill([
                'status' => ContactQuestionnaireRun::STATUS_OPERATOR_REQUESTED,
                'current_field_key' => null,
                'awaiting_block_id' => null,
                'last_dialog_id' => $message->dialog_id,
                'operator_requested_at' => now(),
            ])->save();

            return $this->handledTerminal($run, QuestionnaireStartResult::OUTCOME_OPERATOR_REQUESTED);
        }

        if (in_array($normalizedCommand, self::SKIP_COMMANDS, true) && ($field['allow_skip'] ?? false) === true) {
            $this->storeAttempt($run, $field, $attemptIndex, $message, $promptText, $rawAnswer, null, ContactQuestionnaireAttempt::STATUS_SKIPPED);
            $answer->forceFill([
                'status' => ContactQuestionnaireAnswer::STATUS_SKIPPED,
                'attempts_count' => $attemptIndex,
                'target' => is_string($field['target'] ?? null) ? (string) $field['target'] : null,
            ])->save();

            $this->markRunReadyForNextField($run, $message);

            return $this->handledContinue($run);
        }

        $parsed = $this->parseAnswer($message, $run, $field);

        if (($parsed['accepted'] ?? false) === true) {
            $value = (string) $parsed['value'];
            $displayValue = (string) ($parsed['display_value'] ?? $value);

            $this->storeAttempt($run, $field, $attemptIndex, $message, $promptText, $rawAnswer, $value, ContactQuestionnaireAttempt::STATUS_ACCEPTED);

            $synced = $this->syncAnswerToContact($lockedContact, $field, $value, $displayValue, $message, $parsed);

            $answer->forceFill([
                'status' => ContactQuestionnaireAnswer::STATUS_FILLED,
                'attempts_count' => $attemptIndex,
                'value' => $value,
                'display_value' => $displayValue,
                'target' => is_string($field['target'] ?? null) ? (string) $field['target'] : null,
                'synced_to_contact_at' => $synced ? now() : $answer->synced_to_contact_at,
            ])->save();

            $this->markRunReadyForNextField($run, $message);

            return $this->handledContinue($run);
        }

        $error = is_string($parsed['error'] ?? null) ? (string) $parsed['error'] : 'answer rejected';
        $this->storeAttempt($run, $field, $attemptIndex, $message, $promptText, $rawAnswer, null, ContactQuestionnaireAttempt::STATUS_REJECTED, $error);

        $answer->forceFill([
            'status' => $attemptIndex >= (int) ($field['max_attempts'] ?? 1)
                ? ContactQuestionnaireAnswer::STATUS_FAILED
                : ContactQuestionnaireAnswer::STATUS_ASKED,
            'attempts_count' => $attemptIndex,
            'target' => is_string($field['target'] ?? null) ? (string) $field['target'] : null,
        ])->save();

        if ($attemptIndex >= (int) ($field['max_attempts'] ?? 1)) {
            $this->failRun($run, $error, $message);

            return $this->handledTerminal($run, QuestionnaireStartResult::OUTCOME_FAILED);
        }

        $run->forceFill([
            'last_dialog_id' => $message->dialog_id,
        ])->save();

        return $this->handledContinue($run);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function continueAfterHandledAnswer(Message $message, array $result): void
    {
        $scenarioRun = $this->scenarioRunFromResult($result);
        $blockId = is_string($result['awaiting_block_id'] ?? null) ? (string) $result['awaiting_block_id'] : null;
        $templateKey = is_string($result['template_key'] ?? null) ? (string) $result['template_key'] : 'profile';
        $runtime = $scenarioRun instanceof ScenarioRun ? $this->runtimeForRun($scenarioRun) : null;

        if ($scenarioRun instanceof ScenarioRun) {
            $runtime?->removeV3InlineButtonsForRun($message, $scenarioRun);
        }

        $next = $this->startOrContinueContactQuestionnaireAction->handle(
            message: $message,
            templateKey: $templateKey,
            scenarioRun: $scenarioRun,
            blockId: $blockId,
        );

        if ($scenarioRun instanceof ScenarioRun && $next->outcome === QuestionnaireStartResult::OUTCOME_WAITING) {
            $runtime?->dispatchV3QuestionnairePrompt($scenarioRun, $message, $next);

            return;
        }

        if ($scenarioRun instanceof ScenarioRun) {
            $this->resumeScenario($message, $result, $next->outcome);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function resumeScenario(Message $message, array $result, string $outcome): void
    {
        $scenarioRun = $this->scenarioRunFromResult($result);

        if (! $scenarioRun instanceof ScenarioRun) {
            return;
        }

        $runtime = $this->runtimeForRun($scenarioRun);

        if (! $runtime instanceof GenericDbScenarioRuntime) {
            return;
        }

        $runtime->removeV3InlineButtonsForRun($message, $scenarioRun);
        $runtime->resumeV3QuestionnaireOutcome($scenarioRun, $message, $outcome);
    }

    private function runtimeForRun(ScenarioRun $run): ?GenericDbScenarioRuntime
    {
        $runtime = $this->scenarioRegistry->makeRuntime($run->scenario_code);

        return $runtime instanceof GenericDbScenarioRuntime ? $runtime : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function scenarioRunFromResult(array $result): ?ScenarioRun
    {
        $scenarioRunId = $result['scenario_run_id'] ?? null;

        if (! is_numeric($scenarioRunId)) {
            return null;
        }

        return ScenarioRun::query()->find((int) $scenarioRunId);
    }

    /**
     * @return array<string, mixed>
     */
    private function handledContinue(ContactQuestionnaireRun $run): array
    {
        return [
            'handled' => true,
            'continue' => true,
            'run_id' => (int) $run->id,
            'template_key' => (string) ($run->template()->value('key') ?? 'profile'),
            'scenario_run_id' => $run->scenario_run_id,
            'awaiting_block_id' => $run->awaiting_block_id ?? $run->started_by_block_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handledTerminal(ContactQuestionnaireRun $run, string $outcome): array
    {
        return [
            'handled' => true,
            'resume_outcome' => $outcome,
            'run_id' => (int) $run->id,
            'scenario_run_id' => $run->scenario_run_id,
            'awaiting_block_id' => $run->awaiting_block_id ?? $run->started_by_block_id,
        ];
    }

    private function failRun(ContactQuestionnaireRun $run, string $error, ?Message $message = null): void
    {
        $run->forceFill([
            'status' => ContactQuestionnaireRun::STATUS_FAILED,
            'current_field_key' => null,
            'awaiting_block_id' => null,
            'last_dialog_id' => $message?->dialog_id ?? $run->last_dialog_id,
        ])->save();

        Log::warning('questionnaire.answer_failed', [
            'questionnaire_run_id' => $run->id,
            'message_id' => $message?->id,
            'error' => $error,
        ]);
    }

    private function markRunReadyForNextField(ContactQuestionnaireRun $run, Message $message): void
    {
        $run->forceFill([
            'status' => ContactQuestionnaireRun::STATUS_IN_PROGRESS,
            'current_field_key' => null,
            'awaiting_block_id' => null,
            'last_dialog_id' => $message->dialog_id,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array{accepted: bool, value?: string, display_value?: string, resolution_method?: string, error?: string}
     */
    private function parseAnswer(Message $message, ContactQuestionnaireRun $run, array $field): array
    {
        if (($field['field_key'] ?? null) === self::FIELD_KEY_RUSSIAN_REGION_CONFIRM) {
            return $this->parseRussianRegionConfirmAnswer($message, $run, $field);
        }

        if (($field['target'] ?? null) === 'contact.city') {
            return $this->parseCityAnswer($message);
        }

        return match ((string) ($field['type'] ?? '')) {
            'choice' => $this->parseSingleChoiceAnswer($message, $run, $field),
            'dictionary' => $this->parseDictionaryLookupAnswer($message, $field),
            'phone' => $this->parsePhoneAnswer($message),
            default => $this->parseTextAnswer($message),
        };
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array{accepted: bool, value?: string, display_value?: string, error?: string}
     */
    private function parseSingleChoiceAnswer(Message $message, ContactQuestionnaireRun $run, array $field): array
    {
        $callbackOutputId = $this->callbackOutputId($message, $run);

        foreach ($this->fieldOptions($field) as $option) {
            if ($callbackOutputId !== null && $callbackOutputId === $this->questionnaireOptionOutputId($option['value'])) {
                return [
                    'accepted' => true,
                    'value' => $option['value'],
                    'display_value' => $option['label'],
                ];
            }
        }

        if ($callbackOutputId !== null) {
            return ['accepted' => false, 'error' => 'unknown callback option'];
        }

        $answer = $this->normalizeAnswer($this->rawAnswerText($message));

        foreach ($this->fieldOptions($field) as $option) {
            if (
                $answer === $this->normalizeAnswer($option['value'])
                || $answer === $this->normalizeAnswer($option['label'])
            ) {
                return [
                    'accepted' => true,
                    'value' => $option['value'],
                    'display_value' => $option['label'],
                ];
            }
        }

        return ['accepted' => false, 'error' => 'unknown option'];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array{accepted: bool, value?: string, display_value?: string, error?: string}
     */
    private function parseRussianRegionConfirmAnswer(Message $message, ContactQuestionnaireRun $run, array $field): array
    {
        $choice = $this->parseSingleChoiceAnswer($message, $run, $field);

        if (($choice['accepted'] ?? false) === true) {
            return $choice;
        }

        if (! $message->contact instanceof Contact) {
            return ['accepted' => false, 'error' => 'unknown region'];
        }

        $resolved = $this->dataCollectionPromptHelper->resolveRussianRegionConfirmInput(
            $message->contact,
            $this->rawAnswerText($message),
        );

        if ($resolved === null || $resolved === 'skip' || ! array_key_exists($resolved, Contact::russianRegionOptions())) {
            return ['accepted' => false, 'error' => 'unknown region'];
        }

        return [
            'accepted' => true,
            'value' => $resolved,
            'display_value' => $resolved,
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array{accepted: bool, value?: string, display_value?: string, resolution_method?: string, error?: string}
     */
    private function parseDictionaryLookupAnswer(Message $message, array $field): array
    {
        $raw = trim($this->rawAnswerText($message));

        if ($raw === '') {
            return ['accepted' => false, 'error' => 'empty value'];
        }

        $dictionaryKey = (string) ($field['dictionary_key'] ?? 'names');
        $lookup = $this->lookupScenarioDataDictionaryAction->handle(
            $dictionaryKey,
            $raw,
            $message->contact?->gender,
        );

        if (($lookup['matched'] ?? false) === true && filled($lookup['value'] ?? null)) {
            return [
                'accepted' => true,
                'value' => (string) $lookup['value'],
                'display_value' => (string) $lookup['value'],
                'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_DICTIONARY_LOOKUP,
            ];
        }

        if ($this->isFirstNameDictionaryField($field)) {
            return $this->parseFirstNameWithAiAnswer(
                message: $message,
                field: $field,
                previousError: (string) ($lookup['status'] ?? LookupScenarioDataDictionaryAction::STATUS_NOT_FOUND),
            );
        }

        return [
            'accepted' => false,
            'error' => (string) ($lookup['status'] ?? LookupScenarioDataDictionaryAction::STATUS_NOT_FOUND),
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array{accepted: bool, value?: string, display_value?: string, resolution_method?: string, error?: string}
     */
    private function parseFirstNameWithAiAnswer(Message $message, array $field, string $previousError): array
    {
        try {
            $extraction = $this->extractFirstNameAction->handleWithAi(
                $this->rawAnswerText($message),
                $this->resolveFirstNameMessengerContext($message),
            );
        } catch (Throwable $throwable) {
            Log::warning('questionnaire.first_name_ai_extraction_failed', [
                'contact_id' => $message->contact_id,
                'message_id' => $message->id,
                'dictionary_error' => $previousError,
                'exception_class' => $throwable::class,
                'error' => $throwable->getMessage(),
            ]);

            return ['accepted' => false, 'error' => $previousError];
        }

        if (($extraction['decision'] ?? null) !== ExtractFirstNameAction::DECISION_ACCEPT) {
            return ['accepted' => false, 'error' => 'first_name_ai_rejected'];
        }

        $firstName = is_string($extraction['first_name'] ?? null)
            ? trim((string) $extraction['first_name'])
            : '';

        if ($firstName === '') {
            return ['accepted' => false, 'error' => 'first_name_ai_empty'];
        }

        $normalizedFirstName = $this->normalizeFirstNameThroughDictionary($firstName, $field, $message);

        return [
            'accepted' => true,
            'value' => $normalizedFirstName,
            'display_value' => $normalizedFirstName,
            'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS,
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function isFirstNameDictionaryField(array $field): bool
    {
        return ($field['target'] ?? null) === 'contact.first_name'
            && ($field['type'] ?? null) === 'dictionary';
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function normalizeFirstNameThroughDictionary(string $firstName, array $field, Message $message): string
    {
        $dictionaryKey = (string) ($field['dictionary_key'] ?? 'names');
        $lookup = $this->lookupScenarioDataDictionaryAction->handle(
            $dictionaryKey,
            $firstName,
            $message->contact?->gender,
        );

        return ($lookup['matched'] ?? false) === true && filled($lookup['value'] ?? null)
            ? trim((string) $lookup['value'])
            : $firstName;
    }

    private function resolveFirstNameMessengerContext(Message $message): ?string
    {
        if (! $message->contact instanceof Contact) {
            return null;
        }

        $message->loadMissing(['dialog.currentContactIdentity', 'contactIdentity']);

        $dialogIdentity = $message->dialog?->currentContactIdentity;

        if ($dialogIdentity instanceof ContactIdentity && filled($dialogIdentity->display_name)) {
            return trim((string) $dialogIdentity->display_name);
        }

        $messageIdentity = $message->contactIdentity;

        if ($messageIdentity instanceof ContactIdentity && filled($messageIdentity->display_name)) {
            return trim((string) $messageIdentity->display_name);
        }

        $latestDialogIdentityId = $message->contact->dialogs()
            ->whereNotNull('current_contact_identity_id')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->value('current_contact_identity_id');

        if ($latestDialogIdentityId === null) {
            return null;
        }

        $displayName = ContactIdentity::query()
            ->whereKey($latestDialogIdentityId)
            ->value('display_name');

        return filled($displayName) ? trim((string) $displayName) : null;
    }

    /**
     * @return array{accepted: bool, value?: string, display_value?: string, country?: string, country_confidence?: string, error?: string}
     */
    private function parseCityAnswer(Message $message): array
    {
        $value = $this->normalizeFreeTextAnswer($this->rawAnswerText($message));

        if ($value === '' || mb_strlen($value) > 255) {
            return ['accepted' => false, 'error' => 'invalid city'];
        }

        $lookup = $this->resolveRussianRegionCandidatesLookupAction->handle($value);
        $matchedCity = is_string($lookup['matched_city'] ?? null) ? trim((string) $lookup['matched_city']) : '';
        $candidateRegions = $this->normalizeRegionCandidates($lookup['candidate_regions'] ?? null);

        if ($matchedCity !== '' && $candidateRegions !== []) {
            return [
                'accepted' => true,
                'value' => $matchedCity,
                'display_value' => $matchedCity,
                'country' => 'RU',
                'country_confidence' => ExtractResidenceCityAction::COUNTRY_CONFIDENCE_HIGH,
            ];
        }

        try {
            $extraction = $this->extractResidenceCityAction->handle($value);
        } catch (Throwable $throwable) {
            Log::warning('questionnaire.city_extraction_failed', [
                'contact_id' => $message->contact_id,
                'message_id' => $message->id,
                'reply_preview' => mb_substr($value, 0, 120),
                'exception_class' => $throwable::class,
                'error' => $throwable->getMessage(),
            ]);

            return ['accepted' => false, 'error' => 'unknown city'];
        }

        if (($extraction['decision'] ?? null) !== ExtractResidenceCityAction::DECISION_ACCEPT) {
            return ['accepted' => false, 'error' => 'unknown city'];
        }

        $city = $this->normalizeFreeTextAnswer((string) ($extraction['city'] ?? ''));

        if ($city === '' || mb_strlen($city) > 255) {
            return ['accepted' => false, 'error' => 'invalid city'];
        }

        $result = [
            'accepted' => true,
            'value' => $city,
            'display_value' => $city,
        ];

        $country = $this->normalizeFreeTextAnswer((string) ($extraction['country'] ?? ''));
        $countryConfidence = is_string($extraction['country_confidence'] ?? null)
            ? (string) $extraction['country_confidence']
            : null;

        if ($country !== '' && $countryConfidence === ExtractResidenceCityAction::COUNTRY_CONFIDENCE_HIGH) {
            $result['country'] = $country;
            $result['country_confidence'] = ExtractResidenceCityAction::COUNTRY_CONFIDENCE_HIGH;
        }

        return $result;
    }

    /**
     * @return array{accepted: bool, value?: string, display_value?: string, error?: string}
     */
    private function parseTextAnswer(Message $message): array
    {
        $value = $this->normalizeFreeTextAnswer($this->rawAnswerText($message));

        if ($value === '' || mb_strlen($value) > 255) {
            return ['accepted' => false, 'error' => 'invalid text'];
        }

        return [
            'accepted' => true,
            'value' => $value,
            'display_value' => $value,
        ];
    }

    /**
     * @return array{accepted: bool, value?: string, display_value?: string, error?: string}
     */
    private function parsePhoneAnswer(Message $message): array
    {
        $raw = trim($this->rawAnswerText($message));

        try {
            $normalized = AddContactPhoneAction::normalizePhone($raw);
        } catch (Throwable) {
            $normalized = '';
        }

        if ($raw === '' || $normalized === '') {
            return ['accepted' => false, 'error' => 'invalid phone'];
        }

        return [
            'accepted' => true,
            'value' => $normalized,
            'display_value' => AddContactPhoneAction::maskPhone($normalized),
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function syncAnswerToContact(
        Contact $contact,
        array $field,
        string $value,
        string $displayValue,
        Message $message,
        array $parsed = [],
    ): bool {
        if (($field['overwrite_contact'] ?? false) !== true || ! is_string($field['target'] ?? null)) {
            return false;
        }

        return match ((string) $field['target']) {
            'contact.first_name' => $this->syncFirstName($contact, $field, $value, $message, $parsed),
            'contact.phone' => $this->syncPhone($contact, $value),
            'contact.gender' => $this->syncEnumContactField($contact, 'gender', $value, Contact::genderOptions()),
            'contact.country' => $this->syncStringContactField($contact, 'country', $value),
            'contact.city' => $this->syncCity($contact, $value, $parsed),
            'contact.region' => $this->syncRegion($contact, $value),
            'contact.age_range' => $this->syncEnumContactField($contact, 'age_range', $value, Contact::ageRangeOptions()),
            'contact.age_years' => $this->syncAgeYears($contact, $value),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function syncFirstName(Contact $contact, array $field, string $value, Message $message, array $parsed = []): bool
    {
        $method = is_string($parsed['resolution_method'] ?? null)
            ? (string) $parsed['resolution_method']
            : (($field['type'] ?? null) === 'dictionary'
            ? Contact::FIRST_NAME_RESOLUTION_METHOD_DICTIONARY_LOOKUP
            : Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT);

        $result = $this->applyContactFirstNameAction->handle(
            $contact,
            $value,
            Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            ApplyContactFirstNameAction::REASON_SCENARIO_CONFIRMED,
            $method,
        );

        if ($result->bitrix24RelevantChanged) {
            $this->queueBitrix24ContactSyncAction->handle($contact);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function syncCity(Contact $contact, string $value, array $parsed = []): bool
    {
        if (mb_strlen($value) > 255) {
            return false;
        }

        $payload = [
            'city' => $value,
            'country' => null,
            'region' => null,
            'region_status' => Contact::REGION_STATUS_UNKNOWN,
            'region_source' => null,
            'pending_region_candidates' => null,
        ];

        $resolved = $this->resolveRussianCityRegionFromLookup($value);
        $status = $resolved['status'];
        $candidateRegions = $resolved['candidate_regions'];

        if ($status === Contact::REGION_STATUS_RESOLVED && is_string($resolved['region'] ?? null)) {
            $region = trim((string) $resolved['region']);

            if (array_key_exists($region, Contact::russianRegionOptions())) {
                $payload = array_merge($payload, [
                    'country' => 'RU',
                    'region' => $region,
                    'region_status' => Contact::REGION_STATUS_RESOLVED,
                    'region_source' => Contact::REGION_SOURCE_DICTIONARY,
                    'pending_region_candidates' => null,
                ]);
            }
        } elseif (
            in_array($status, [Contact::REGION_STATUS_CLARIFICATION_PENDING, Contact::REGION_STATUS_AMBIGUOUS], true)
            && $candidateRegions !== []
        ) {
            $payload = array_merge($payload, [
                'country' => 'RU',
                'region' => null,
                'region_status' => $status,
                'region_source' => Contact::REGION_SOURCE_DICTIONARY,
                'pending_region_candidates' => $candidateRegions,
            ]);
        }

        $parsedCountry = $this->parsedHighConfidenceCountry($parsed);

        if ($parsedCountry !== null) {
            if ($this->isRussianCountry($parsedCountry)) {
                $payload['country'] = 'RU';
                $payload['region_source'] ??= Contact::REGION_SOURCE_AI;
            } else {
                $payload = array_merge($payload, [
                    'country' => $parsedCountry,
                    'region' => null,
                    'region_status' => Contact::REGION_STATUS_OUT_OF_SCOPE,
                    'region_source' => Contact::REGION_SOURCE_AI,
                    'pending_region_candidates' => null,
                ]);
            }
        }

        if (
            $contact->city === $payload['city']
            && $contact->country === $payload['country']
            && $contact->region === $payload['region']
            && $contact->region_status === $payload['region_status']
            && $contact->region_source === $payload['region_source']
            && $contact->pending_region_candidates === $payload['pending_region_candidates']
        ) {
            return true;
        }

        $contact->forceFill($payload)->save();
        $this->queueBitrix24ContactSyncAction->handle($contact);

        return true;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function parsedHighConfidenceCountry(array $parsed): ?string
    {
        if (($parsed['country_confidence'] ?? null) !== ExtractResidenceCityAction::COUNTRY_CONFIDENCE_HIGH) {
            return null;
        }

        if (! is_string($parsed['country'] ?? null)) {
            return null;
        }

        $country = $this->normalizeFreeTextAnswer((string) $parsed['country']);

        return $country !== '' && mb_strlen($country) <= 255 ? $country : null;
    }

    private function isRussianCountry(string $country): bool
    {
        $normalized = str_replace('ё', 'е', mb_strtolower($this->normalizeFreeTextAnswer($country)));

        return in_array($normalized, ['ru', 'rus', 'russia', 'россия', 'рф', 'российская федерация'], true);
    }

    /**
     * @return array{status: string, region: ?string, candidate_regions: list<string>}
     */
    private function resolveRussianCityRegionFromLookup(string $city): array
    {
        $lookup = $this->resolveRussianRegionCandidatesLookupAction->handle($city);
        $candidateRegions = $this->normalizeRegionCandidates($lookup['candidate_regions'] ?? null);

        if (count($candidateRegions) === 1) {
            return [
                'status' => Contact::REGION_STATUS_RESOLVED,
                'region' => $candidateRegions[0],
                'candidate_regions' => [],
            ];
        }

        if (count($candidateRegions) >= 2 && count($candidateRegions) <= 4) {
            return [
                'status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
                'region' => null,
                'candidate_regions' => $candidateRegions,
            ];
        }

        if (count($candidateRegions) >= 5) {
            return [
                'status' => Contact::REGION_STATUS_AMBIGUOUS,
                'region' => null,
                'candidate_regions' => $candidateRegions,
            ];
        }

        return [
            'status' => Contact::REGION_STATUS_UNKNOWN,
            'region' => null,
            'candidate_regions' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeRegionCandidates(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $allowed = Contact::russianRegionOptions();
        $regions = [];

        foreach ($value as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $region = trim($candidate);

            if ($region === '' || ! array_key_exists($region, $allowed) || in_array($region, $regions, true)) {
                continue;
            }

            $regions[] = $region;
        }

        return $regions;
    }

    private function normalizeFreeTextAnswer(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    }

    private function syncPhone(Contact $contact, string $value): bool
    {
        $this->addContactPhoneAction->handle($contact, $value, ContactPhoneNumber::SOURCE_V3_CAPTURE);

        return true;
    }

    /**
     * @param  array<string, string>  $options
     */
    private function syncEnumContactField(Contact $contact, string $attribute, string $value, array $options): bool
    {
        if (! array_key_exists($value, $options)) {
            return false;
        }

        return $this->syncStringContactField($contact, $attribute, $value);
    }

    private function syncStringContactField(Contact $contact, string $attribute, string $value): bool
    {
        if (mb_strlen($value) > 255) {
            return false;
        }

        if ($contact->getAttribute($attribute) === $value) {
            return true;
        }

        $contact->forceFill([$attribute => $value])->save();
        $this->queueBitrix24ContactSyncAction->handle($contact);

        return true;
    }

    private function syncRegion(Contact $contact, string $value): bool
    {
        if (! array_key_exists($value, Contact::russianRegionOptions())) {
            return false;
        }

        $payload = [
            'country' => 'RU',
            'region' => $value,
            'region_status' => Contact::REGION_STATUS_RESOLVED,
            'region_source' => Contact::REGION_SOURCE_CONFIRMED_BY_CONTACT,
            'pending_region_candidates' => null,
        ];

        if (
            $contact->country === $payload['country']
            && $contact->region === $payload['region']
            && $contact->region_status === $payload['region_status']
            && $contact->region_source === $payload['region_source']
            && $contact->pending_region_candidates === $payload['pending_region_candidates']
        ) {
            return true;
        }

        $contact->forceFill($payload)->save();
        $this->queueBitrix24ContactSyncAction->handle($contact);

        return true;
    }

    private function syncAgeYears(Contact $contact, string $value): bool
    {
        if (preg_match('/^\d{1,3}$/', $value) !== 1) {
            return false;
        }

        $age = (int) $value;

        if ($age < 1 || $age > 120) {
            return false;
        }

        if ((int) $contact->age_years === $age) {
            return true;
        }

        $contact->forceFill(['age_years' => $age])->save();
        $this->queueBitrix24ContactSyncAction->handle($contact);

        return true;
    }

    private function messageAlreadyHandled(ContactQuestionnaireRun $run, Message $message): bool
    {
        return ContactQuestionnaireAttempt::query()
            ->where('questionnaire_run_id', $run->id)
            ->where('message_id', $message->id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function storeAttempt(
        ContactQuestionnaireRun $run,
        array $field,
        int $attemptIndex,
        Message $message,
        ?string $promptText,
        ?string $rawAnswer,
        ?string $parsedValue,
        string $status,
        ?string $error = null,
    ): void {
        ContactQuestionnaireAttempt::query()->create([
            'questionnaire_run_id' => $run->id,
            'field_key' => (string) $field['field_key'],
            'attempt_index' => $attemptIndex,
            'dialog_id' => $message->dialog_id,
            'message_id' => $message->id,
            'prompt_text' => $promptText,
            'raw_answer' => $rawAnswer,
            'parsed_value' => $parsedValue,
            'status' => $status,
            'error' => $error,
        ]);
    }

    private function rawAnswerText(Message $message): string
    {
        $callbackData = data_get($message->raw_payload, 'callback_query.data');

        if (is_string($callbackData) && trim($callbackData) !== '') {
            return trim($callbackData);
        }

        $text = trim((string) $message->text);

        if ($text !== '') {
            return $text;
        }

        return trim((string) $message->message_parameter);
    }

    private function callbackOutputId(Message $message, ContactQuestionnaireRun $run): ?string
    {
        $callbackData = data_get($message->raw_payload, 'callback_query.data');

        if (! is_string($callbackData) || ! str_starts_with($callbackData, 'v3b:')) {
            return null;
        }

        $parts = explode(':', $callbackData, 3);

        if (count($parts) !== 3) {
            return null;
        }

        $blockId = $parts[1];
        $outputId = $parts[2];

        if (filled($run->awaiting_block_id) && $blockId !== $run->awaiting_block_id) {
            return null;
        }

        return $outputId !== '' ? $outputId : null;
    }

    private function questionnaireOptionOutputId(string $value): string
    {
        return 'q_'.substr(sha1($value), 0, 16);
    }

    private function normalizeAnswer(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value));
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

        return (string) $prompts->get(min($attemptsCount, $prompts->count() - 1));
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<array{value:string,label:string}>
     */
    private function fieldOptions(array $field): array
    {
        if (! is_array($field['options'] ?? null)) {
            return [];
        }

        return collect($field['options'])
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
    private function fieldByKey(QuestionnaireTemplateVersion $version, string $fieldKey, ?Contact $contact = null): ?array
    {
        if ($fieldKey === self::FIELD_KEY_RUSSIAN_REGION_CONFIRM && $contact instanceof Contact) {
            return $this->russianRegionConfirmField($contact);
        }

        foreach ($this->fields($version) as $field) {
            if ((string) ($field['field_key'] ?? '') === $fieldKey) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function russianRegionConfirmField(Contact $contact): ?array
    {
        if (! $this->dataCollectionPromptHelper->shouldAskRussianRegionConfirmation($contact)) {
            return null;
        }

        $candidates = $this->dataCollectionPromptHelper->russianRegionCandidates($contact);

        if ($candidates === []) {
            return null;
        }

        return [
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

    private function questionnaireEngineEnabled(): bool
    {
        return config('bots.data_collection.profile_collection_engine') === 'questionnaires';
    }
}
