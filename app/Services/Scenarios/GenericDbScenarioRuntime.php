<?php

namespace App\Services\Scenarios;

use App\Data\Messages\PreparedMessageContentData;
use App\Data\Scenarios\ScenarioInboundResult;
use App\Jobs\InferContactGenderFromFirstNameJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioRun;
use App\Models\ScenarioVersion;
use App\Services\Bots\MaxBotApiService;
use App\Services\Bots\StoreOutboundScenarioMessageAction;
use App\Services\Bots\TelegramBotApiService;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use App\Services\Contacts\ApplyContactFirstNameAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\DataCollection\ExtractFirstNameAction;
use App\Services\Messages\PrepareMessageContentAction;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GenericDbScenarioRuntime implements ResolvedScenarioRuntime
{
    private const IBIZA_SCENARIO_CODE = 'vip_ibiza';

    private const PHONE_CAPTURE_BUTTON_TEXT = 'Поделиться номером телефона';

    public function __construct(
        private readonly Scenario $scenario,
        private readonly ScenarioVersion $publishedVersion,
        private readonly ValidateScenarioSchemaPayloadAction $validateScenarioSchemaPayloadAction,
        private readonly ScenarioConditionEvaluator $scenarioConditionEvaluator,
        private readonly ApplyScenarioTagEffectsAction $applyScenarioTagEffectsAction,
        private readonly StoreOutboundScenarioMessageAction $storeOutboundScenarioMessageAction,
        private readonly TelegramBotApiService $telegramBotApiService,
        private readonly MaxBotApiService $maxBotApiService,
        private readonly PrepareMessageContentAction $prepareMessageContentAction,
        private readonly ExtractFirstNameAction $extractFirstNameAction,
        private readonly ApplyContactFirstNameAction $applyContactFirstNameAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
    ) {}

    public function code(): string
    {
        return (string) $this->scenario->code;
    }

    public function shouldStart(Message $message): bool
    {
        if (
            ! in_array($message->channel?->platform, [Channel::PLATFORM_TELEGRAM, Channel::PLATFORM_MAX], true)
            || $message->message_kind !== Message::KIND_INBOUND_USER
            || $message->dialog_id === null
            || ! filled($message->message_parameter)
            || ($message->contact !== null && ! $message->contact->isAutoReplyEnabled())
        ) {
            return false;
        }

        if ($message->channel?->platform === Channel::PLATFORM_TELEGRAM && is_array(data_get($message->raw_payload, 'callback_query'))) {
            return false;
        }

        $schema = $this->validatedSchemaOrNull();

        if ($schema === null) {
            return false;
        }

        $messageParameter = trim((string) $message->message_parameter);

        foreach ($schema['triggers'] as $trigger) {
            if ($trigger['value'] === $messageParameter) {
                return true;
            }
        }

        return false;
    }

    public function start(ScenarioRun $run, Message $message): void
    {
        $schema = $this->validatedSchema();

        $progress = $this->advanceFromBlock(
            $message,
            $schema,
            $schema['start_block_id'],
            $this->normalizeStatePayload($run->state_payload),
        );

        $run->forceFill([
            'status' => $progress['status'],
            'current_step' => $progress['current_step'],
            'state_payload' => $progress['state_payload'],
            'exit_outcome' => $progress['exit_outcome'],
            'finished_at' => $progress['status'] === ScenarioRun::STATUS_ACTIVE ? null : now(),
        ])->save();
    }

    public function supportsContactShareContinuation(ScenarioRun $run): bool
    {
        $currentStep = filled($run->current_step) ? trim((string) $run->current_step) : null;

        if ($currentStep === null) {
            return false;
        }

        $schema = $this->validatedSchemaOrNull();
        $block = $schema['blocks'][$currentStep] ?? null;

        return is_array($block) && ($block['type'] ?? null) === 'phone_capture';
    }

    public function supportsTelegramCallbackContinuation(ScenarioRun $run, string $callbackData): bool
    {
        return false;
    }

    public function handleInbound(ScenarioRun $run, Message $message): ScenarioInboundResult
    {
        $schema = $this->validatedSchema();
        $statePayload = $this->normalizeStatePayload($run->state_payload);
        $currentStep = filled($run->current_step) ? trim((string) $run->current_step) : null;

        if ($currentStep === null || ! array_key_exists($currentStep, $schema['blocks'])) {
            return new ScenarioInboundResult(
                consumed: true,
                status: ScenarioRun::STATUS_CANCELLED,
                currentStep: null,
                statePayload: $statePayload,
                exitOutcome: 'invalid_current_step',
            );
        }

        $block = $schema['blocks'][$currentStep];

        return match ($block['type'] ?? null) {
            'question' => $this->handleQuestionInbound($message, $schema, $block, $currentStep, $statePayload),
            'phone_capture' => $this->handlePhoneCaptureInbound($message, $schema, $block, $currentStep, $statePayload),
            default => new ScenarioInboundResult(
                consumed: true,
                status: ScenarioRun::STATUS_CANCELLED,
                currentStep: null,
                statePayload: $statePayload,
                exitOutcome: 'invalid_current_step',
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @param  array{
     *     version: int,
     *     start_block_id: string,
     *     triggers: list<array{type: 'parameter', value: string}>,
     *     blocks: array<string, array<string, mixed>>,
     * }  $schema
     * @return array{
     *     status: string,
     *     current_step: ?string,
     *     state_payload: array<string, mixed>,
     *     exit_outcome: ?string,
     * }
     */
    private function advanceFromBlock(
        Message $message,
        array $schema,
        string $blockId,
        array $statePayload,
        ?int $remainingTransitions = null,
        bool $removeTelegramKeyboard = false,
    ): array
    {
        $nextBlockId = $blockId;
        $remainingTransitions ??= count($schema['blocks']) + 1;

        if ($remainingTransitions < 1) {
            throw new RuntimeException("Scenario [{$this->code()}] exceeded safe linear transition limit.");
        }

        $block = $schema['blocks'][$nextBlockId] ?? null;

        if (! is_array($block)) {
            throw new RuntimeException("Scenario [{$this->code()}] references missing block [{$nextBlockId}].");
        }

        return match ($block['type'] ?? null) {
            'message' => $this->advanceAfterMessageBlock($message, $schema, $block, $statePayload, $remainingTransitions - 1, $removeTelegramKeyboard),
            'question' => $this->enterQuestionBlock($message, $nextBlockId, $block, $statePayload, $removeTelegramKeyboard),
            'condition' => $this->advanceAfterConditionBlock($message, $schema, $block, $statePayload, $remainingTransitions - 1, $removeTelegramKeyboard),
            'phone_capture' => $this->enterPhoneCaptureBlock($message, $nextBlockId, $block, $statePayload),
            'complete' => $this->completeScenario($message, $block, $statePayload),
            default => throw new RuntimeException("Scenario [{$this->code()}] uses unsupported block type."),
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $statePayload
     * @param  array{
     *     version: int,
     *     start_block_id: string,
     *     triggers: list<array{type: 'parameter', value: string}>,
     *     blocks: array<string, array<string, mixed>>,
     * }  $schema
     * @return array{
     *     status: string,
     *     current_step: ?string,
     *     state_payload: array<string, mixed>,
     *     exit_outcome: ?string,
     * }
     */
    private function advanceAfterMessageBlock(
        Message $message,
        array $schema,
        array $block,
        array $statePayload,
        int $remainingTransitions,
        bool $removeTelegramKeyboard = false,
    ): array
    {
        $this->dispatchScenarioMessage(
            $message,
            (string) $block['text'],
            (string) $block['text_format'],
            removeTelegramKeyboard: $removeTelegramKeyboard,
        );

        $statePayload = $this->applyBlockActions($message, $block, $statePayload);

        return $this->advanceFromBlock(
            $message,
            $schema,
            (string) $block['next'],
            $statePayload,
            $remainingTransitions,
            false,
        );
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $statePayload
     * @param  array{
     *     version: int,
     *     start_block_id: string,
     *     triggers: list<array{type: 'parameter', value: string}>,
     *     blocks: array<string, array<string, mixed>>,
     * }  $schema
     * @return array{
     *     status: string,
     *     current_step: ?string,
     *     state_payload: array<string, mixed>,
     *     exit_outcome: ?string,
     * }
     */
    private function advanceAfterConditionBlock(
        Message $message,
        array $schema,
        array $block,
        array $statePayload,
        int $remainingTransitions,
        bool $removeTelegramKeyboard = false,
    ): array
    {
        $defaultBlockId = null;

        foreach ($block['branches'] as $branch) {
            if (array_key_exists('if', $branch) && $this->scenarioConditionEvaluator->handle($branch['if'], $statePayload)) {
                return $this->advanceFromBlock(
                    $message,
                    $schema,
                    (string) $branch['then'],
                    $statePayload,
                    $remainingTransitions,
                    $removeTelegramKeyboard,
                );
            }

            if (array_key_exists('default', $branch)) {
                $defaultBlockId = (string) $branch['default'];
            }
        }

        if ($defaultBlockId !== null) {
            return $this->advanceFromBlock(
                $message,
                $schema,
                $defaultBlockId,
                $statePayload,
                $remainingTransitions,
                $removeTelegramKeyboard,
            );
        }

        throw new RuntimeException("Scenario [{$this->code()}] condition block does not have a default branch.");
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $statePayload
     * @return array{
     *     status: string,
     *     current_step: string,
     *     state_payload: array<string, mixed>,
     *     exit_outcome: null,
     * }
     */
    private function enterQuestionBlock(
        Message $message,
        string $blockId,
        array $block,
        array $statePayload,
        bool $removeTelegramKeyboard = false,
    ): array
    {
        $this->dispatchScenarioMessage(
            $message,
            (string) $block['text'],
            (string) $block['text_format'],
            removeTelegramKeyboard: $removeTelegramKeyboard,
        );

        return [
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => $blockId,
            'state_payload' => $statePayload,
            'exit_outcome' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $statePayload
     * @return array{
     *     status: string,
     *     current_step: string,
     *     state_payload: array<string, mixed>,
     *     exit_outcome: null,
     * }
     */
    private function enterPhoneCaptureBlock(Message $message, string $blockId, array $block, array $statePayload): array
    {
        $this->dispatchScenarioMessage(
            $message,
            (string) $block['text'],
            (string) $block['text_format'],
            requestPhone: true,
        );

        return [
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => $blockId,
            'state_payload' => $statePayload,
            'exit_outcome' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $statePayload
     * @return array{
     *     status: string,
     *     current_step: null,
     *     state_payload: array<string, mixed>,
     *     exit_outcome: 'completed',
     * }
     */
    private function completeScenario(Message $message, array $block, array $statePayload): array
    {
        $statePayload = $this->applyBlockActions($message, $block, $statePayload);
        $this->applyIbizaFirstNameEnrichment($message, $statePayload);

        return [
            'status' => ScenarioRun::STATUS_COMPLETED,
            'current_step' => null,
            'state_payload' => $statePayload,
            'exit_outcome' => 'completed',
        ];
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function applyIbizaFirstNameEnrichment(Message $message, array $statePayload): void
    {
        if ($this->code() !== self::IBIZA_SCENARIO_CODE || ! $message->contact instanceof Contact) {
            return;
        }

        try {
            $contact = $this->resolveRootContactAction->handle($message->contact);
            $rawFirstName = data_get($statePayload, 'run.first_name');

            if (! is_string($rawFirstName) || trim($rawFirstName) === '') {
                return;
            }

            $extraction = $this->extractFirstNameAction->handle(
                $rawFirstName,
                $this->resolveFirstNameMessengerContext($message, $contact),
            );

            if (($extraction['decision'] ?? null) !== ExtractFirstNameAction::DECISION_ACCEPT) {
                return;
            }

            $firstName = is_string($extraction['first_name'] ?? null)
                ? trim((string) $extraction['first_name'])
                : '';

            if ($firstName === '') {
                return;
            }

            $result = $this->applyContactFirstNameAction->handle(
                $contact,
                $firstName,
                Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
                ApplyContactFirstNameAction::REASON_SCENARIO_CONFIRMED,
            );

            if (! $result->changed) {
                return;
            }

            $this->queueBitrix24ContactSyncAction->handle($contact);

            if (
                $result->newSource === Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED
                && ! filled($contact->gender)
                && filled($result->newValue)
            ) {
                InferContactGenderFromFirstNameJob::dispatch($contact->id, (string) $result->newValue);
            }
        } catch (Throwable $throwable) {
            Log::warning('scenario.ibiza_first_name_enrichment_failed', [
                'scenario_code' => $this->code(),
                'contact_id' => $message->contact_id,
                'message_id' => $message->id,
                'exception_class' => $throwable::class,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function resolveFirstNameMessengerContext(Message $message, Contact $contact): ?string
    {
        $message->loadMissing(['dialog.currentContactIdentity', 'contactIdentity']);

        $dialogIdentity = $message->dialog?->currentContactIdentity;

        if ($dialogIdentity instanceof ContactIdentity && filled($dialogIdentity->display_name)) {
            return trim((string) $dialogIdentity->display_name);
        }

        $messageIdentity = $message->contactIdentity;

        if ($messageIdentity instanceof ContactIdentity && filled($messageIdentity->display_name)) {
            return trim((string) $messageIdentity->display_name);
        }

        $latestDialogIdentityId = $contact->dialogs()
            ->whereNotNull('current_contact_identity_id')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->value('current_contact_identity_id');

        if ($latestDialogIdentityId !== null) {
            $displayName = ContactIdentity::query()
                ->whereKey($latestDialogIdentityId)
                ->value('display_name');

            if (filled($displayName)) {
                return trim((string) $displayName);
            }
        }
        return null;
    }

    private function dispatchScenarioMessage(
        Message $message,
        string $text,
        string $textFormat,
        bool $requestPhone = false,
        bool $removeTelegramKeyboard = false,
    ): void
    {
        $channel = $message->channel;

        if (! $channel instanceof Channel) {
            throw new RuntimeException("Scenario [{$this->code()}] message does not have an active channel.");
        }

        $content = $this->prepareMessageContentAction->handle($text, $textFormat);
        $deliveryResult = $this->deliverScenarioMessage($channel, $message, $content, $requestPhone, $removeTelegramKeyboard);

        $this->storeOutboundScenarioMessageAction->handle(
            $channel,
            $message,
            $deliveryResult,
            $this->systemCode(),
            $content,
        );

        $channel->markReplySent();
    }

    private function deliverScenarioMessage(
        Channel $channel,
        Message $message,
        PreparedMessageContentData $content,
        bool $requestPhone = false,
        bool $removeTelegramKeyboard = false,
    ): \App\Data\Bots\AutoReplyDeliveryResult {
        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->sendTextMessage(
                $channel,
                $message->external_chat_id,
                $message->contactIdentity?->external_user_id,
                $content->transportText,
                $this->telegramReplyMarkup($requestPhone, $removeTelegramKeyboard),
                $content->textFormat,
            ),
            Channel::PLATFORM_MAX => $this->maxBotApiService->sendTextMessage(
                $channel,
                $message->external_chat_id,
                $message->contactIdentity?->external_user_id,
                $content->transportText,
                $requestPhone ? $this->maxPhoneCaptureAttachments() : null,
                $content->textFormat,
            ),
            default => throw new RuntimeException("Scenario [{$this->code()}] does not support channel platform [{$channel->platform}]."),
        };
    }

    /**
     * @return array{
     *     version: int,
     *     start_block_id: string,
     *     triggers: list<array{type: 'parameter', value: string}>,
     *     blocks: array<string, array<string, mixed>>,
     * }|null
     */
    private function validatedSchemaOrNull(): ?array
    {
        try {
            return $this->validatedSchema();
        } catch (\Illuminate\Validation\ValidationException) {
            return null;
        }
    }

    /**
     * @return array{
     *     version: int,
     *     start_block_id: string,
     *     triggers: list<array{type: 'parameter', value: string}>,
     *     blocks: array<string, array<string, mixed>>,
     * }
     */
    private function validatedSchema(): array
    {
        return $this->validateScenarioSchemaPayloadAction->handle(
            is_array($this->publishedVersion->schema_payload) ? $this->publishedVersion->schema_payload : [],
        );
    }

    /**
     * @param  array<string, mixed>|mixed  $statePayload
     * @return array<string, mixed>
     */
    private function normalizeStatePayload(mixed $statePayload): array
    {
        return is_array($statePayload) ? $statePayload : [];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function applyBlockActions(Message $message, array $block, array $statePayload): array
    {
        $actions = $block['actions'] ?? [];

        if (! is_array($actions) || $actions === []) {
            return $statePayload;
        }

        if (! $message->contact instanceof Contact) {
            throw new RuntimeException("Scenario [{$this->code()}] action block requires a contact context.");
        }

        $this->applyScenarioTagEffectsAction->handle($message->contact, $actions);

        return $statePayload;
    }

    /**
     * @param  array{
     *     version: int,
     *     start_block_id: string,
     *     triggers: list<array{type: 'parameter', value: string}>,
     *     blocks: array<string, array<string, mixed>>,
     * }  $schema
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $statePayload
     */
    private function handleQuestionInbound(
        Message $message,
        array $schema,
        array $block,
        string $currentStep,
        array $statePayload,
    ): ScenarioInboundResult {
        $answer = is_string($message->text) ? trim($message->text) : '';

        if ($answer === '') {
            return new ScenarioInboundResult(
                consumed: true,
                status: ScenarioRun::STATUS_CANCELLED,
                currentStep: null,
                statePayload: $statePayload,
                exitOutcome: 'unsupported_inbound',
            );
        }

        data_set($statePayload, (string) $block['save_to'], $answer);

        $progress = $this->advanceFromBlock(
            $message,
            $schema,
            (string) $block['next'],
            $statePayload,
            removeTelegramKeyboard: $message->channel?->platform === Channel::PLATFORM_TELEGRAM,
        );

        return new ScenarioInboundResult(
            consumed: true,
            status: $progress['status'],
            currentStep: $progress['current_step'],
            statePayload: $progress['state_payload'],
            exitOutcome: $progress['exit_outcome'],
        );
    }

    /**
     * @param  array{
     *     version: int,
     *     start_block_id: string,
     *     triggers: list<array{type: 'parameter', value: string}>,
     *     blocks: array<string, array<string, mixed>>,
     * }  $schema
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $statePayload
     */
    private function handlePhoneCaptureInbound(
        Message $message,
        array $schema,
        array $block,
        string $currentStep,
        array $statePayload,
    ): ScenarioInboundResult {
        if ($message->message_kind !== Message::KIND_INBOUND_CONTACT_SHARE) {
            return new ScenarioInboundResult(
                consumed: true,
                status: ScenarioRun::STATUS_ACTIVE,
                currentStep: $currentStep,
                statePayload: $statePayload,
                exitOutcome: null,
            );
        }

        $progress = $this->advanceFromBlock(
            $message,
            $schema,
            (string) $block['next'],
            $statePayload,
            removeTelegramKeyboard: $message->channel?->platform === Channel::PLATFORM_TELEGRAM,
        );

        return new ScenarioInboundResult(
            consumed: true,
            status: $progress['status'],
            currentStep: $progress['current_step'],
            statePayload: $progress['state_payload'],
            exitOutcome: $progress['exit_outcome'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramPhoneCaptureReplyMarkup(): array
    {
        return [
            'keyboard' => [
                [[
                    'text' => self::PHONE_CAPTURE_BUTTON_TEXT,
                    'request_contact' => true,
                ]],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function telegramReplyMarkup(bool $requestPhone, bool $removeTelegramKeyboard): ?array
    {
        if ($requestPhone) {
            return $this->telegramPhoneCaptureReplyMarkup();
        }

        if ($removeTelegramKeyboard) {
            return [
                'remove_keyboard' => true,
            ];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function maxPhoneCaptureAttachments(): array
    {
        return [[
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => [[[
                    'type' => 'request_contact',
                    'text' => self::PHONE_CAPTURE_BUTTON_TEXT,
                ]]],
            ],
        ]];
    }

    private function systemCode(): string
    {
        return 'scenario_'.$this->code();
    }
}
