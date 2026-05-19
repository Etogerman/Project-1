<?php

namespace App\Services\Scenarios;

use App\Data\Scenarios\ScenarioInboundResult;
use App\Jobs\InferContactGenderFromFirstNameJob;
use App\Jobs\ProcessScenarioV3ScheduledTransitionJob;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioBuilderCondition;
use App\Models\ScenarioBuilderEdge;
use App\Models\ScenarioRun;
use App\Models\ScenarioV3ScheduledTransition;
use App\Models\ScenarioVersion;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use App\Services\Bots\SendBotDialogTextAction;
use App\Services\Bots\StoreOutboundScenarioMessageAction;
use App\Services\Contacts\AddContactPhoneAction;
use App\Services\Contacts\ApplyContactFirstNameAction;
use App\Services\Contacts\NormalizePhoneNumberAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\DataCollection\ExtractFirstNameAction;
use App\Services\Dialogs\SyncDialogConfirmedPhoneAction;
use App\Services\Messages\PrepareMessageContentAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class GenericDbScenarioRuntime implements PrioritizedScenarioRuntime, ResolvedScenarioRuntime
{
    private const IBIZA_SCENARIO_CODE = 'vip_ibiza';

    private const IBIZA_FIRST_NAME_STATE_KEY = 'run.first_name';

    private const PHONE_CAPTURE_BUTTON_TEXT = 'Поделиться номером телефона';

    private const V3_MATCH_EXACT_CALLBACK = 'exact_callback';

    private const V3_BUTTON_TYPE_TEXT = 'text';

    private const V3_BUTTON_TYPE_REQUEST_PHONE = 'request_phone';

    private const V3_BUTTON_TYPE_LINK = 'link';

    private const V3_BUTTON_PLACEMENT_AUTO = 'auto';

    private const V3_BUTTON_PLACEMENT_REPLY_KEYBOARD = 'reply_keyboard';

    private const V3_BUTTON_PLACEMENT_INLINE_MESSAGE = 'inline_message';

    private const V3_TELEGRAM_BUTTON_CALLBACK_PREFIX = 'v3b:';

    private const V3_TELEGRAM_BUTTON_CALLBACK_MAX_BYTES = 64;

    private const PENDING_PROMPT_DELIVERY_STATE_KEY = 'run.pending_prompt_delivery';

    private const PENDING_PROMPT_REMOVE_TELEGRAM_KEYBOARD_STATE_KEY = 'run.pending_prompt_remove_telegram_keyboard';

    private const V3_DIALOG_FIELDS_MAX_BYTES = 65536;

    private const V3_DIALOG_USER_FIELDS_MAX = 50;

    private const V3_DIALOG_FIELD_VALUE_MAX_LENGTH = 2000;

    private const V3_CONTACT_CAPTURE_FIELDS = [
        'phone',
        'first_name',
        'last_name',
        'country',
        'city',
        'gender',
        'age_years',
        'age_range',
    ];

    private ?int $matchedBuilderStartMessageId = null;

    private ?string $matchedBuilderRuntimeBlockId = null;

    private ?int $matchedV3StartMessageId = null;

    private ?string $matchedV3RuntimeBlockId = null;

    public function __construct(
        private readonly Scenario $scenario,
        private readonly ScenarioVersion $publishedVersion,
        private readonly ValidateScenarioSchemaPayloadAction $validateScenarioSchemaPayloadAction,
        private readonly ScenarioConditionEvaluator $scenarioConditionEvaluator,
        private readonly ApplyScenarioTagEffectsAction $applyScenarioTagEffectsAction,
        private readonly StoreOutboundScenarioMessageAction $storeOutboundScenarioMessageAction,
        private readonly SendBotDialogTextAction $sendBotDialogTextAction,
        private readonly PrepareMessageContentAction $prepareMessageContentAction,
        private readonly ExtractFirstNameAction $extractFirstNameAction,
        private readonly ApplyContactFirstNameAction $applyContactFirstNameAction,
        private readonly AddContactPhoneAction $addContactPhoneAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
        private readonly SyncDialogConfirmedPhoneAction $syncDialogConfirmedPhoneAction,
    ) {}

    public function code(): string
    {
        return (string) $this->scenario->code;
    }

    public function shouldStartBeforeActiveRun(Message $message): bool
    {
        return $this->v3RuntimeOrNull() !== null && $this->shouldStart($message);
    }

    public function shouldCancelActiveRunOnStart(Message $message): bool
    {
        $runtime = $this->v3RuntimeOrNull();

        if ($runtime === null) {
            return false;
        }

        $blockId = $this->v3StartBlockIdForMessage($message, $runtime);
        $block = $this->v3RuntimeBlock($runtime, $blockId);

        return ! is_array($block) || ! $this->isV3NonStateBlock($block);
    }

    public function startBeforeActiveRunWithoutCancelling(Message $message, ScenarioRun $activeRun): bool
    {
        $runtime = $this->v3RuntimeOrNull();

        if ($runtime === null) {
            return false;
        }

        $blockId = $this->v3StartBlockIdForMessage($message, $runtime);
        $block = $this->v3RuntimeBlock($runtime, $blockId);

        if (! is_array($block) || ! $this->isV3NonStateBlock($block)) {
            return false;
        }

        $this->advanceV3FromBlock(
            $message,
            $runtime,
            $blockId,
            $this->v3StatePayload($activeRun->state_payload),
            run: $activeRun,
            preservePreviousStateForTerminalNonState: true,
        );

        return true;
    }

    public function handleScheduledV3Transition(int|ScenarioV3ScheduledTransition $transition): void
    {
        $transition = $transition instanceof ScenarioV3ScheduledTransition
            ? $transition
            : ScenarioV3ScheduledTransition::query()->find((int) $transition);

        if (! $transition instanceof ScenarioV3ScheduledTransition) {
            return;
        }

        $transition = $this->claimScheduledV3Transition($transition);

        if (! $transition instanceof ScenarioV3ScheduledTransition) {
            return;
        }

        try {
            $this->processClaimedV3ScheduledTransition($transition);
        } catch (Throwable $throwable) {
            $safeErrorMessage = $this->safeV3ScheduledTransitionErrorMessage($throwable->getMessage(), $transition);

            Log::warning('scenario.v3.delayed_transition.exception', [
                'transition_id' => $transition->id,
                'scenario_code' => $transition->scenario_code,
                'scenario_run_id' => $transition->scenario_run_id,
                'dialog_id' => $transition->dialog_id,
                'published_version_id' => $transition->published_version_id,
                'edge_key' => $transition->edge_key,
                'exception' => get_class($throwable),
                'error_message' => $safeErrorMessage,
            ]);

            $transition->refresh();

            if ($transition->status === ScenarioV3ScheduledTransition::STATUS_PROCESSING) {
                $this->finishV3ScheduledTransition(
                    $transition,
                    ScenarioV3ScheduledTransition::STATUS_FAILED,
                    $safeErrorMessage,
                );
            }
        }
    }

    public function shouldStart(Message $message): bool
    {
        $this->matchedBuilderStartMessageId = null;
        $this->matchedBuilderRuntimeBlockId = null;
        $this->matchedV3StartMessageId = null;
        $this->matchedV3RuntimeBlockId = null;

        if (
            ! in_array($message->channel?->platform, [Channel::PLATFORM_TELEGRAM, Channel::PLATFORM_MAX], true)
            || $message->message_kind !== Message::KIND_INBOUND_USER
            || $message->dialog_id === null
            || ($message->contact !== null && ! $message->contact->isAutoReplyEnabled())
        ) {
            return false;
        }

        $v3Runtime = $this->v3RuntimeOrNull();

        if (
            $message->channel?->platform === Channel::PLATFORM_TELEGRAM
            && $this->messageIsV3Callback($message)
            && $v3Runtime === null
        ) {
            return false;
        }

        if ($v3Runtime !== null) {
            return $this->shouldStartV3($message, $v3Runtime);
        }

        $schema = $this->validatedSchemaOrNull();

        if ($schema === null) {
            return false;
        }

        $builderStartBlocks = $this->runtimeEligibleBuilderStartBlocks();

        if ($builderStartBlocks->isNotEmpty()) {
            foreach ($builderStartBlocks as $block) {
                /** @var ScenarioBuilderBlock $block */
                if (
                    $this->builderBlockAllowsChannel($block, $message)
                    && $this->builderBlockMatchesMessage($block, $message)
                ) {
                    $this->matchedBuilderStartMessageId = (int) $message->id;
                    $this->matchedBuilderRuntimeBlockId = $this->builderBlockRuntimeStartBlockId($block, $schema);

                    return true;
                }
            }

            return false;
        }

        foreach ($schema['triggers'] as $trigger) {
            if ($this->messageMatchesTrigger($message, $trigger)) {
                return true;
            }
        }

        return false;
    }

    private function matchingBuilderStartBlock(Message $message): ?ScenarioBuilderBlock
    {
        $blocks = $this->runtimeEligibleBuilderStartBlocks();

        foreach ($blocks as $block) {
            /** @var ScenarioBuilderBlock $block */
            if (! $this->builderBlockAllowsChannel($block, $message)) {
                continue;
            }

            if ($this->builderBlockMatchesMessage($block, $message)) {
                return $block;
            }
        }

        return null;
    }

    private function runtimeEligibleBuilderStartBlocks(): Collection
    {
        return $this->publishedVersion
            ->builderBlocks()
            ->where('type', ScenarioBuilderBlock::TYPE_START_CONDITION)
            ->with(['channels', 'conditions', 'outgoingEdges'])
            ->orderBy('id')
            ->get()
            ->filter(fn (ScenarioBuilderBlock $block): bool => $block->channels->isNotEmpty())
            ->values();
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function builderBlockRuntimeStartBlockId(ScenarioBuilderBlock $block, array $schema): string
    {
        $block->loadMissing('outgoingEdges');

        $edge = $block->outgoingEdges->first();
        $candidate = $edge instanceof ScenarioBuilderEdge && filled($edge->to_runtime_block_id)
            ? (string) $edge->to_runtime_block_id
            : (string) data_get($block->settings_payload, 'start_block_id', '');
        $candidate = trim($candidate);
        $blocks = is_array($schema['blocks'] ?? null) ? $schema['blocks'] : [];

        if ($candidate !== '' && array_key_exists($candidate, $blocks)) {
            return $candidate;
        }

        return (string) $schema['start_block_id'];
    }

    private function builderBlockAllowsChannel(ScenarioBuilderBlock $block, Message $message): bool
    {
        if ($message->channel_id === null) {
            return false;
        }

        return $block->channels
            ->contains(fn (Channel $channel): bool => (int) $channel->id === (int) $message->channel_id);
    }

    private function builderBlockMatchesMessage(ScenarioBuilderBlock $block, Message $message): bool
    {
        $conditionMatch = $this->normalizeTriggerMatchScope(data_get($block->settings_payload, 'condition.match'));

        if ($conditionMatch === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND && $block->conditions->isEmpty()) {
            return true;
        }

        foreach ($block->conditions as $condition) {
            /** @var ScenarioBuilderCondition $condition */
            if ($this->messageMatchesCondition(
                $message,
                $condition->match_operator ?? $conditionMatch,
                (string) $condition->value,
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{type: string, value?: string, match_scope?: string, match?: string}  $trigger
     */
    private function messageMatchesTrigger(Message $message, array $trigger): bool
    {
        $matchScope = $this->normalizeTriggerMatchScope($trigger['match_scope'] ?? ($trigger['match'] ?? null));

        if ($matchScope === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND) {
            return true;
        }

        return $this->messageMatchesCondition($message, $matchScope, (string) ($trigger['value'] ?? ''));
    }

    private function messageMatchesCondition(Message $message, mixed $matchScope, string $expectedValue): bool
    {
        $matchScope = $this->normalizeTriggerMatchScope($matchScope);

        if ($matchScope === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND) {
            return true;
        }

        $expectedValue = AutoReplyRule::normalizeKeyword($expectedValue);
        $messageText = AutoReplyRule::normalizeKeyword($message->text);
        $messageParameter = AutoReplyRule::normalizeKeyword($message->message_parameter);

        if (! filled($expectedValue)) {
            return false;
        }

        return match ($matchScope) {
            AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT => filled($messageText)
                && str_contains((string) $messageText, (string) $expectedValue),
            AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD => $messageText === $expectedValue,
            AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER => $messageText === $expectedValue
                || $messageParameter === $expectedValue,
            default => $messageParameter === $expectedValue,
        };
    }

    private function normalizeTriggerMatchScope(mixed $matchScope): string
    {
        $normalizedMatchScope = is_string($matchScope) && trim($matchScope) !== ''
            ? trim($matchScope)
            : AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER;

        return match ($normalizedMatchScope) {
            'exact' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'contains' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
            'starts_with', 'ends_with' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            default => array_key_exists($normalizedMatchScope, AutoReplyRule::matchScopeOptions())
                ? $normalizedMatchScope
                : AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
        };
    }

    public function start(ScenarioRun $run, Message $message): void
    {
        $v3Runtime = $this->v3RuntimeOrNull();

        if ($v3Runtime !== null) {
            $this->startV3($run, $message, $v3Runtime);

            return;
        }

        $schema = $this->validatedSchema();
        $startBlockId = $this->startBlockIdForMessage($message, $schema);

        $progress = $this->advanceFromBlock(
            $message,
            $schema,
            $startBlockId,
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

    /**
     * @param  array<string, mixed>  $schema
     */
    private function startBlockIdForMessage(Message $message, array $schema): string
    {
        if (
            $this->matchedBuilderStartMessageId === (int) $message->id
            && $this->matchedBuilderRuntimeBlockId !== null
            && array_key_exists($this->matchedBuilderRuntimeBlockId, $schema['blocks'])
        ) {
            return $this->matchedBuilderRuntimeBlockId;
        }

        $matchingBlock = $this->matchingBuilderStartBlock($message);

        if ($matchingBlock instanceof ScenarioBuilderBlock) {
            return $this->builderBlockRuntimeStartBlockId($matchingBlock, $schema);
        }

        return (string) $schema['start_block_id'];
    }

    public function supportsContactShareContinuation(ScenarioRun $run): bool
    {
        $currentStep = filled($run->current_step) ? trim((string) $run->current_step) : null;

        if ($currentStep === null) {
            return false;
        }

        $v3Runtime = $this->v3RuntimeOrNull();

        if ($v3Runtime !== null) {
            $statePayload = $this->v3StatePayload($run->state_payload);
            $currentStep = $this->v3RuntimeBlockId($v3Runtime, $currentStep, $statePayload);
            $block = $currentStep !== null ? $this->v3RuntimeBlock($v3Runtime, $currentStep) : null;
            $channel = $run->dialog?->channel;

            return is_array($block) && (
                $this->v3TargetForContactShare($block, $channel) !== null
                || $this->v3BlockAcceptsContactShareWaitReply($block)
            );
        }

        $schema = $this->validatedSchemaOrNull();
        $block = $schema['blocks'][$currentStep] ?? null;

        return is_array($block) && ($block['type'] ?? null) === 'phone_capture';
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function v3BlockAcceptsContactShareWaitReply(array $block): bool
    {
        return collect($this->v3WaitReplyEdges($block))
            ->contains(function (array $edge): bool {
                $match = is_array($edge['match'] ?? null) ? $edge['match'] : [];

                return ($match['type'] ?? 'any_inbound') === 'any_inbound';
            });
    }

    public function supportsTelegramCallbackContinuation(ScenarioRun $run, string $callbackData): bool
    {
        if (! str_starts_with($callbackData, self::V3_TELEGRAM_BUTTON_CALLBACK_PREFIX)) {
            return false;
        }

        $currentStep = filled($run->current_step) ? trim((string) $run->current_step) : null;

        if ($currentStep === null) {
            return false;
        }

        $v3Runtime = $this->v3RuntimeOrNull();

        if ($v3Runtime === null) {
            return false;
        }

        $statePayload = $this->v3StatePayload($run->state_payload);
        $currentStep = $this->v3RuntimeBlockId($v3Runtime, $currentStep, $statePayload);
        $block = $currentStep !== null ? $this->v3RuntimeBlock($v3Runtime, $currentStep) : null;

        if (! is_array($block)) {
            return false;
        }

        $channel = $run->dialog?->channel;

        if (! $channel instanceof Channel || $channel->platform !== Channel::PLATFORM_TELEGRAM) {
            return false;
        }

        return $this->v3TargetForButtonCallback($callbackData, $block, $channel) !== null;
    }

    public function handleInbound(ScenarioRun $run, Message $message): ScenarioInboundResult
    {
        $v3Runtime = $this->v3RuntimeOrNull();

        if ($v3Runtime !== null) {
            return $this->handleV3Inbound($run, $message, $v3Runtime);
        }

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

        if ($this->hasPendingPromptDelivery($statePayload)) {
            return $this->resumePendingPromptDelivery($message, $schema, $block, $currentStep, $statePayload);
        }

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
    ): array {
        $nextBlockId = $blockId;
        $remainingTransitions ??= count($schema['blocks']) + 1;

        if ($remainingTransitions < 1) {
            throw new RuntimeException("Scenario [{$this->code()}] exceeded safe linear transition limit.");
        }

        $block = $schema['blocks'][$nextBlockId] ?? null;

        if (! is_array($block)) {
            throw new RuntimeException("Scenario [{$this->code()}] references missing block [{$nextBlockId}].");
        }

        $ibizaSkipResult = $this->resolveIbizaSkipResult(
            $message,
            $schema,
            $block,
            $statePayload,
            $remainingTransitions,
            $removeTelegramKeyboard,
        );

        if ($ibizaSkipResult !== null) {
            return $ibizaSkipResult;
        }

        return match ($block['type'] ?? null) {
            'message' => $this->advanceAfterMessageBlock($message, $schema, $nextBlockId, $block, $statePayload, $remainingTransitions - 1, $removeTelegramKeyboard),
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
     * }|null
     */
    private function resolveIbizaSkipResult(
        Message $message,
        array $schema,
        array $block,
        array $statePayload,
        int $remainingTransitions,
        bool $removeTelegramKeyboard,
    ): ?array {
        if (
            $this->code() !== self::IBIZA_SCENARIO_CODE
            || ! is_array($block)
            || ! array_key_exists('next', $block)
            || ! $message->contact instanceof Contact
        ) {
            return null;
        }

        $contact = $this->resolveRootContactAction->handle($message->contact);

        if ($this->shouldSkipIbizaFirstNameBlock($block, $contact) || $this->shouldSkipIbizaPhoneCaptureBlock($message, $block, $contact)) {
            return $this->advanceFromBlock(
                $message,
                $schema,
                (string) $block['next'],
                $statePayload,
                $remainingTransitions - 1,
                $removeTelegramKeyboard,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function shouldSkipIbizaFirstNameBlock(array $block, Contact $contact): bool
    {
        if (($block['type'] ?? null) !== 'question' || ($block['save_to'] ?? null) !== self::IBIZA_FIRST_NAME_STATE_KEY) {
            return false;
        }

        return filled($contact->first_name)
            && in_array($contact->first_name_source, [
                Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
                Contact::FIRST_NAME_SOURCE_MANUAL,
            ], true);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function shouldSkipIbizaPhoneCaptureBlock(Message $message, array $block, Contact $contact): bool
    {
        if (($block['type'] ?? null) !== 'phone_capture') {
            return false;
        }

        return $contact->phoneNumbers()->exists();
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
        string $blockId,
        array $block,
        array $statePayload,
        int $remainingTransitions,
        bool $removeTelegramKeyboard = false,
    ): array {
        if (! $this->dispatchScenarioMessage(
            $message,
            (string) $block['text'],
            (string) $block['text_format'],
            removeTelegramKeyboard: $removeTelegramKeyboard,
        )) {
            return $this->activeProgress(
                $blockId,
                $this->markPendingPromptDelivery($statePayload, $removeTelegramKeyboard),
            );
        }

        $statePayload = $this->clearPendingPromptDelivery(
            $this->applyBlockActions($message, $block, $statePayload),
        );

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
    ): array {
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
    ): array {
        if (! $this->dispatchScenarioMessage(
            $message,
            (string) $block['text'],
            (string) $block['text_format'],
            removeTelegramKeyboard: $removeTelegramKeyboard,
        )) {
            return $this->activeProgress(
                $blockId,
                $this->markPendingPromptDelivery($statePayload, $removeTelegramKeyboard),
            );
        }

        return $this->activeProgress(
            $blockId,
            $this->clearPendingPromptDelivery($statePayload),
        );
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
        if (! $this->dispatchScenarioMessage(
            $message,
            (string) $block['text'],
            (string) $block['text_format'],
            requestPhone: true,
        )) {
            return $this->activeProgress(
                $blockId,
                $this->markPendingPromptDelivery($statePayload),
            );
        }

        return $this->activeProgress(
            $blockId,
            $this->clearPendingPromptDelivery($statePayload),
        );
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

            if ($contact->first_name_source === Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED) {
                return;
            }

            $rawFirstName = data_get($statePayload, self::IBIZA_FIRST_NAME_STATE_KEY);

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

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function shouldStartV3(Message $message, array $runtime): bool
    {
        $blockId = $this->matchingV3EntrypointBlockId($message, $runtime);

        if ($blockId === null) {
            return false;
        }

        $this->matchedV3StartMessageId = (int) $message->id;
        $this->matchedV3RuntimeBlockId = $blockId;

        return true;
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function startV3(ScenarioRun $run, Message $message, array $runtime): void
    {
        $startBlockId = $this->v3StartBlockIdForMessage($message, $runtime);
        $progress = $this->advanceV3FromBlock(
            $message,
            $runtime,
            $startBlockId,
            $this->v3StatePayload($run->state_payload),
            run: $run,
        );

        $run->forceFill([
            'status' => $progress['status'],
            'current_step' => $progress['current_step'],
            'state_payload' => $progress['state_payload'],
            'exit_outcome' => $progress['exit_outcome'],
            'finished_at' => $progress['status'] === ScenarioRun::STATUS_ACTIVE ? null : now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function handleV3Inbound(ScenarioRun $run, Message $message, array $runtime): ScenarioInboundResult
    {
        $statePayload = $this->v3StatePayload($run->state_payload);
        $rawRunCurrentBlockId = filled($run->current_step) ? trim((string) $run->current_step) : null;
        $rawStateCurrentBlockId = trim((string) data_get($statePayload, 'v3.current_block_id', ''));
        $rawCurrentBlockId = $rawRunCurrentBlockId ?: $rawStateCurrentBlockId;
        $currentBlockId = $this->v3RuntimeBlockId($runtime, $rawCurrentBlockId, $statePayload);

        if (
            $rawRunCurrentBlockId !== null
            && $rawStateCurrentBlockId !== ''
            && $this->v3RuntimeBlockId($runtime, $rawRunCurrentBlockId, $statePayload) !== $this->v3RuntimeBlockId($runtime, $rawStateCurrentBlockId, $statePayload)
        ) {
            Log::warning('scenario.v3_current_block_mismatch', [
                'scenario_code' => $this->code(),
                'scenario_run_id' => $run->id,
                'dialog_id' => $message->dialog_id,
                'current_step' => $run->current_step,
                'state_current_block_id' => $rawStateCurrentBlockId,
            ]);

            return new ScenarioInboundResult(
                consumed: false,
                status: $run->status,
                currentStep: $run->current_step,
                statePayload: $statePayload,
                exitOutcome: $run->exit_outcome,
            );
        }

        if ($currentBlockId === null) {
            return new ScenarioInboundResult(
                consumed: false,
                status: $run->status,
                currentStep: $run->current_step,
                statePayload: $statePayload,
                exitOutcome: $run->exit_outcome,
            );
        }

        $block = $this->v3RuntimeBlock($runtime, $currentBlockId) ?? [];
        $transition = $this->v3TransitionForMessage($message, is_array($block) ? $block : [], $message->dialog);

        if ($transition !== null) {
            return $this->handleV3TransitionInbound($run, $message, $runtime);
        }

        return new ScenarioInboundResult(
            consumed: true,
            status: ScenarioRun::STATUS_ACTIVE,
            currentStep: $currentBlockId,
            statePayload: $this->markV3Waiting($statePayload, $currentBlockId, $block, $message->channel),
            exitOutcome: null,
        );
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function handleV3TransitionInbound(ScenarioRun $run, Message $message, array $runtime): ScenarioInboundResult
    {
        if ($message->dialog_id === null) {
            return new ScenarioInboundResult(
                consumed: false,
                status: $run->status,
                currentStep: $run->current_step,
                statePayload: $this->v3StatePayload($run->state_payload),
                exitOutcome: $run->exit_outcome,
            );
        }

        return DB::transaction(function () use ($run, $message, $runtime): ScenarioInboundResult {
            $dialog = Dialog::query()
                ->whereKey($message->dialog_id)
                ->lockForUpdate()
                ->first();
            $lockedRun = ScenarioRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $dialog instanceof Dialog
                || ! $lockedRun instanceof ScenarioRun
                || ! $lockedRun->isActive()
                || (int) $lockedRun->dialog_id !== (int) $message->dialog_id
            ) {
                return new ScenarioInboundResult(
                    consumed: false,
                    status: $run->status,
                    currentStep: $run->current_step,
                    statePayload: $this->v3StatePayload($run->state_payload),
                    exitOutcome: $run->exit_outcome,
                );
            }

            $statePayload = $this->v3StatePayload($lockedRun->state_payload);
            $rawRunCurrentBlockId = filled($lockedRun->current_step) ? trim((string) $lockedRun->current_step) : null;
            $rawStateCurrentBlockId = trim((string) data_get($statePayload, 'v3.current_block_id', ''));
            $rawCurrentBlockId = $rawRunCurrentBlockId ?: $rawStateCurrentBlockId;
            $currentBlockId = $this->v3RuntimeBlockId($runtime, $rawCurrentBlockId, $statePayload);

            if (
                $rawRunCurrentBlockId !== null
                && $rawStateCurrentBlockId !== ''
                && $this->v3RuntimeBlockId($runtime, $rawRunCurrentBlockId, $statePayload) !== $this->v3RuntimeBlockId($runtime, $rawStateCurrentBlockId, $statePayload)
            ) {
                Log::warning('scenario.v3_current_block_mismatch', [
                    'scenario_code' => $this->code(),
                    'scenario_run_id' => $lockedRun->id,
                    'dialog_id' => $message->dialog_id,
                    'current_step' => $lockedRun->current_step,
                    'state_current_block_id' => $rawStateCurrentBlockId,
                ]);

                return new ScenarioInboundResult(
                    consumed: false,
                    status: $lockedRun->status,
                    currentStep: $lockedRun->current_step,
                    statePayload: $statePayload,
                    exitOutcome: $lockedRun->exit_outcome,
                );
            }

            if ($currentBlockId === null) {
                return new ScenarioInboundResult(
                    consumed: false,
                    status: $lockedRun->status,
                    currentStep: $lockedRun->current_step,
                    statePayload: $statePayload,
                    exitOutcome: $lockedRun->exit_outcome,
                );
            }

            $block = $this->v3RuntimeBlock($runtime, $currentBlockId) ?? [];
            $transition = $this->v3TransitionForMessage($message, is_array($block) ? $block : [], $dialog);

            if ($transition === null) {
                return new ScenarioInboundResult(
                    consumed: true,
                    status: ScenarioRun::STATUS_ACTIVE,
                    currentStep: $currentBlockId,
                    statePayload: $this->markV3Waiting($statePayload, $currentBlockId, is_array($block) ? $block : [], $message->channel),
                    exitOutcome: null,
                );
            }

            $transitionEdge = $transition['edge'];
            $capturedValue = $transition['captured_value'];

            if (! $this->applyV3TransitionSideEffectsToDialog($dialog, $message, $transitionEdge, $capturedValue)) {
                return new ScenarioInboundResult(
                    consumed: true,
                    status: ScenarioRun::STATUS_ACTIVE,
                    currentStep: $currentBlockId,
                    statePayload: $this->markV3Waiting($statePayload, $currentBlockId, $block, $message->channel),
                    exitOutcome: null,
                );
            }

            $targetBlockId = filled($transitionEdge['target_block_id'] ?? null) ? (string) $transitionEdge['target_block_id'] : null;

            if ($targetBlockId === null) {
                return new ScenarioInboundResult(
                    consumed: true,
                    status: ScenarioRun::STATUS_ACTIVE,
                    currentStep: $currentBlockId,
                    statePayload: $this->markV3Waiting($statePayload, $currentBlockId, $block, $message->channel),
                    exitOutcome: null,
                );
            }

            $progress = $this->advanceV3FromBlock($message, $runtime, $targetBlockId, $statePayload, run: $lockedRun);

            $lockedRun->forceFill([
                'status' => $progress['status'],
                'current_step' => $progress['current_step'],
                'state_payload' => $progress['state_payload'],
                'exit_outcome' => $progress['exit_outcome'],
                'finished_at' => $progress['status'] === ScenarioRun::STATUS_ACTIVE ? null : now(),
            ])->save();

            return new ScenarioInboundResult(
                consumed: true,
                status: $progress['status'],
                currentStep: $progress['current_step'],
                statePayload: $progress['state_payload'],
                exitOutcome: $progress['exit_outcome'],
                persisted: true,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{edge: array<string, mixed>, captured_value: array{valid: bool, value: string|null, phone_raw?: string|null, phone_normalized?: string|null}}|null
     */
    private function v3TransitionForMessage(Message $message, array $block, ?Dialog $dialog): ?array
    {
        $edges = collect($this->v3TransitionEdgesForMessage($message, $block))
            ->sort(fn (array $left, array $right): int => $this->compareV3TransitionEdges($left, $right))
            ->values();

        foreach ($edges as $edge) {
            if ($this->v3TransitionLimitReached($dialog, $edge)) {
                continue;
            }

            $capturedValue = $this->v3CapturedValueForEdge($message, $edge);

            if ($capturedValue['valid'] !== true) {
                continue;
            }

            return [
                'edge' => $edge,
                'captured_value' => $capturedValue,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    private function v3TransitionEdgesForMessage(Message $message, array $block): array
    {
        $edges = [];

        if ($message->message_kind === Message::KIND_INBOUND_CONTACT_SHARE) {
            $edges = array_merge($edges, $this->v3RequestPhoneButtonEdges($block));
        } else {
            $edges = array_merge(
                $edges,
                $this->v3ButtonCallbackEdges((string) $message->text, $block, $message->channel),
                $this->v3ButtonTextEdges($message, $block),
            );
        }

        return collect($edges)
            ->merge($this->v3WaitReplyEdges($block))
            ->filter(fn (array $edge): bool => filled($edge['target_block_id'] ?? null)
                && $this->v3EdgeAllowsContactPhone($message, $edge)
                && $this->v3EdgeAllowsFieldCondition($message, $edge)
                && (
                    ($edge['mode'] ?? null) !== 'wait_reply'
                    || $this->messageMatchesV3WaitReplyEdge($message, $edge)
                ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    private function v3WaitReplyEdges(array $block): array
    {
        $edges = is_array($block['wait_reply_edges'] ?? null) ? $block['wait_reply_edges'] : [];

        return collect($edges)
            ->filter(fn (mixed $edge): bool => is_array($edge)
                && ($edge['mode'] ?? null) === 'wait_reply'
                && filled($edge['target_block_id'] ?? null))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $edge
     */
    private function messageMatchesV3WaitReplyEdge(Message $message, array $edge): bool
    {
        $match = is_array($edge['match'] ?? null) ? $edge['match'] : [];
        $type = (string) ($match['type'] ?? 'any_inbound');

        if ($type === 'any_inbound') {
            return true;
        }

        $messageText = $this->normalizeV3ButtonText((string) $message->text);
        $messageParameter = $this->normalizeV3ButtonText((string) $message->message_parameter);
        $variants = collect($match['variants'] ?? [])
            ->map(fn (mixed $variant): string => $this->normalizeV3ButtonText((string) $variant))
            ->filter(fn (string $variant): bool => $variant !== '')
            ->values();

        if ($variants->isEmpty()) {
            return false;
        }

        if ($this->messageIsV3Callback($message) && $type !== self::V3_MATCH_EXACT_CALLBACK) {
            return false;
        }

        return match ($type) {
            'contains_text' => $messageText !== ''
                && $variants->contains(fn (string $variant): bool => str_contains($messageText, $variant)),
            AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER => $messageParameter !== ''
                && $variants->contains($messageParameter),
            AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER => ($messageText !== '' && $variants->contains($messageText))
                || ($messageParameter !== '' && $variants->contains($messageParameter)),
            self::V3_MATCH_EXACT_CALLBACK => $variants->contains(
                fn (string $variant): bool => $this->messageMatchesV3Callback($message, $variant),
            ),
            default => $messageText !== '' && $variants->contains($messageText),
        };
    }

    /**
     * @param  array<string, mixed>  $edge
     */
    private function v3EdgeAllowsContactPhone(Message $message, array $edge): bool
    {
        $condition = trim((string) ($edge['contact_phone_condition'] ?? ''));

        if ($condition === '') {
            return true;
        }

        if (! in_array($condition, [
            AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
        ], true)) {
            return false;
        }

        $hasPhone = $message->contact?->phoneNumbers()->exists() ?? false;

        return $condition === AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE
            ? $hasPhone
            : ! $hasPhone;
    }

    /**
     * @param  array<string, mixed>  $edge
     */
    private function v3EdgeAllowsFieldCondition(Message $message, array $edge): bool
    {
        $condition = is_array($edge['field_condition'] ?? null) ? $edge['field_condition'] : [];

        if ((bool) ($condition['enabled'] ?? false) !== true) {
            return true;
        }

        $fieldScope = (string) ($condition['field_scope'] ?? 'dialog');
        $fieldKey = trim((string) ($condition['field_key'] ?? ''));
        $operator = (string) ($condition['operator'] ?? 'filled');
        $expectedValue = (string) ($condition['value'] ?? '');
        $actualValue = $this->v3FieldConditionValue($message, $fieldScope, $fieldKey);

        return match ($operator) {
            'empty' => ! $this->v3FieldConditionFilled($actualValue),
            'equals' => $this->v3FieldConditionEquals($actualValue, $expectedValue),
            'not_equals' => ! $this->v3FieldConditionEquals($actualValue, $expectedValue),
            default => $this->v3FieldConditionFilled($actualValue),
        };
    }

    private function v3FieldConditionValue(Message $message, string $fieldScope, string $fieldKey): mixed
    {
        if ($fieldScope === 'dialog') {
            $dialog = $message->dialog instanceof Dialog
                ? $message->dialog
                : ($message->dialog_id !== null ? Dialog::query()->find($message->dialog_id) : null);
            $fieldsPayload = is_array($dialog?->fields_payload) ? $dialog->fields_payload : [];

            return $fieldsPayload[$fieldKey] ?? null;
        }

        if (! $message->contact instanceof Contact || ! in_array($fieldKey, self::V3_CONTACT_CAPTURE_FIELDS, true)) {
            return null;
        }

        $contact = $this->resolveRootContactAction->handle($message->contact);

        if ($fieldKey === 'phone') {
            return $contact->phoneNumbers()
                ->get(['phone_normalized', 'phone_raw'])
                ->flatMap(fn (ContactPhoneNumber $phone): array => [
                    $phone->phone_normalized,
                    $phone->phone_raw,
                ])
                ->filter(fn (mixed $value): bool => trim((string) $value) !== '')
                ->values()
                ->all();
        }

        return $contact->{$fieldKey} ?? null;
    }

    private function v3FieldConditionFilled(mixed $value): bool
    {
        if (is_array($value)) {
            return collect($value)
                ->contains(fn (mixed $item): bool => $this->v3FieldConditionFilled($item));
        }

        return trim((string) $value) !== '';
    }

    private function v3FieldConditionEquals(mixed $actualValue, string $expectedValue): bool
    {
        $expected = $this->normalizeV3ButtonText($expectedValue);

        if ($expected === '') {
            return ! $this->v3FieldConditionFilled($actualValue);
        }

        if (is_array($actualValue)) {
            return collect($actualValue)
                ->contains(fn (mixed $item): bool => $this->v3FieldConditionEquals($item, $expectedValue));
        }

        return $this->normalizeV3ButtonText((string) $actualValue) === $expected;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareV3TransitionEdges(array $left, array $right): int
    {
        return [
            (int) ($right['priority'] ?? 10),
            (int) ($right['id'] ?? 0),
        ] <=> [
            (int) ($left['priority'] ?? 10),
            (int) ($left['id'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $edge
     */
    private function v3TransitionLimitReached(?Dialog $dialog, array $edge): bool
    {
        $limit = max(0, (int) ($edge['transition_limit'] ?? 0));

        if ($limit === 0 || ! $dialog instanceof Dialog) {
            return false;
        }

        return $this->v3TransitionCount($dialog->fields_payload ?? [], $edge) >= $limit;
    }

    /**
     * @param  array<string, mixed>  $fieldsPayload
     * @param  array<string, mixed>  $edge
     */
    private function v3TransitionCount(array $fieldsPayload, array $edge): int
    {
        return (int) data_get($fieldsPayload, '_v3.transition_counts.'.$this->v3TransitionCountKey($edge), 0);
    }

    /**
     * @param  array<string, mixed>  $edge
     */
    private function v3TransitionCountKey(array $edge): string
    {
        return 'published_'.$this->publishedVersion->id.':'.(string) ($edge['edge_key'] ?? $edge['id'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $edge
     * @return array{valid: bool, value: string|null, phone_raw?: string|null, phone_normalized?: string|null}
     */
    private function v3CapturedValueForEdge(Message $message, array $edge): array
    {
        $capture = is_array($edge['input_capture'] ?? null) ? $edge['input_capture'] : [];

        if ((bool) ($capture['enabled'] ?? false) !== true) {
            return ['valid' => true, 'value' => null];
        }

        $dataType = (string) ($capture['data_type'] ?? 'any_text');
        $sharedPhone = $this->v3SharedPhoneData($message);
        $value = trim((string) (($sharedPhone['normalized'] ?? null) ?? $message->text));
        $rawValue = trim((string) (($sharedPhone['raw'] ?? null) ?? $message->text));

        if ($value === '') {
            return ['valid' => false, 'value' => null];
        }

        if ($dataType === 'email') {
            $email = mb_strtolower($value);

            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
                ? ['valid' => true, 'value' => $email]
                : ['valid' => false, 'value' => null];
        }

        if ($dataType === 'number') {
            $normalized = str_replace(',', '.', $value);

            return preg_match('/^-?\d{1,18}(?:\.\d{1,6})?$/', $normalized) === 1
                ? ['valid' => true, 'value' => (string) $normalized]
                : ['valid' => false, 'value' => null];
        }

        if ($dataType === 'phone') {
            $phone = app(NormalizePhoneNumberAction::class)->handle($rawValue);
            $digits = preg_replace('/\D/u', '', $phone) ?? '';

            return strlen($digits) >= 7
                ? [
                    'valid' => true,
                    'value' => $phone,
                    'phone_raw' => $rawValue,
                    'phone_normalized' => $phone,
                ]
                : ['valid' => false, 'value' => null];
        }

        return ['valid' => true, 'value' => $value];
    }

    private function v3SharedPhoneValue(Message $message): ?string
    {
        $phone = $this->v3SharedPhoneData($message);

        return $phone['normalized'] ?? null;
    }

    /**
     * @return array{raw: string, normalized: string}|null
     */
    private function v3SharedPhoneData(Message $message): ?array
    {
        if (
            $message->message_kind !== Message::KIND_INBOUND_CONTACT_SHARE
            || ! $message->contact instanceof Contact
        ) {
            return null;
        }

        $contact = $this->resolveRootContactAction->handle($message->contact);
        $phoneNumber = $contact->phoneNumbers()
            ->whereIn('source', [
                ContactPhoneNumber::SOURCE_TELEGRAM_CONTACT_SHARE,
                ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
            ])
            ->latest('id')
            ->first()
            ?? $contact->phoneNumbers()->first();

        if (! $phoneNumber instanceof ContactPhoneNumber) {
            return null;
        }

        $raw = trim((string) ($phoneNumber->phone_raw ?: $phoneNumber->phone_normalized));
        $normalized = trim((string) ($phoneNumber->phone_normalized ?: app(NormalizePhoneNumberAction::class)->handle($raw)));

        if ($raw === '' && $normalized === '') {
            return null;
        }

        return [
            'raw' => $raw !== '' ? $raw : $normalized,
            'normalized' => $normalized !== '' ? $normalized : $raw,
        ];
    }

    /**
     * @param  array<string, mixed>  $edge
     * @param  array{valid?: bool, value?: string|null, phone_raw?: string|null, phone_normalized?: string|null}|null  $capturedValue
     */
    private function applyV3TransitionSideEffectsToDialog(Dialog $dialog, Message $message, array $edge, ?array $capturedValue): bool
    {
        $fieldsPayload = is_array($dialog->fields_payload) ? $dialog->fields_payload : [];
        $limit = max(0, (int) ($edge['transition_limit'] ?? 0));
        $counterKey = $this->v3TransitionCountKey($edge);
        $currentCount = (int) data_get($fieldsPayload, '_v3.transition_counts.'.$counterKey, 0);

        if ($limit > 0 && $currentCount >= $limit) {
            return false;
        }

        $capture = is_array($edge['input_capture'] ?? null) ? $edge['input_capture'] : [];

        if (($edge['mode'] ?? null) === 'wait_reply' && (bool) ($capture['enabled'] ?? false) === true) {
            $captureValue = is_array($capturedValue) ? ($capturedValue['value'] ?? null) : null;
            $fieldScope = (string) ($capture['field_scope'] ?? 'dialog');
            $fieldKey = trim((string) ($capture['field_key'] ?? ''));

            if ($fieldScope === 'contact') {
                if (! $this->applyV3TransitionCaptureToContact($message, $capture, $capturedValue ?? [])) {
                    return false;
                }
            } elseif (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $fieldKey)) {
                return false;
            } elseif ($captureValue === null || mb_strlen($captureValue) > self::V3_DIALOG_FIELD_VALUE_MAX_LENGTH) {
                return false;
            } else {
                $userFieldCount = collect($fieldsPayload)
                    ->keys()
                    ->filter(fn (mixed $key): bool => is_string($key) && ! str_starts_with($key, '_'))
                    ->unique()
                    ->count();

                if (! array_key_exists($fieldKey, $fieldsPayload) && $userFieldCount >= self::V3_DIALOG_USER_FIELDS_MAX) {
                    return false;
                }

                $fieldsPayload[$fieldKey] = $captureValue;
            }
        }

        data_set($fieldsPayload, '_v3.transition_counts.'.$counterKey, $currentCount + 1);

        $encoded = json_encode($fieldsPayload);

        if ($encoded === false || strlen($encoded) > self::V3_DIALOG_FIELDS_MAX_BYTES) {
            Log::warning('scenario.v3_dialog_fields_payload_limit_exceeded', [
                'scenario_code' => $this->code(),
                'dialog_id' => $message->dialog_id,
                'edge_key' => $edge['edge_key'] ?? null,
            ]);

            return false;
        }

        $dialog->forceFill(['fields_payload' => $fieldsPayload])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $capture
     * @param  array{valid?: bool, value?: string|null, phone_raw?: string|null, phone_normalized?: string|null}  $capturedValue
     */
    private function applyV3TransitionCaptureToContact(Message $message, array $capture, array $capturedValue): bool
    {
        if (! $message->contact instanceof Contact) {
            return false;
        }

        $fieldKey = trim((string) ($capture['field_key'] ?? ''));
        $value = trim((string) ($capturedValue['value'] ?? ''));

        if (! in_array($fieldKey, self::V3_CONTACT_CAPTURE_FIELDS, true) || $value === '') {
            return false;
        }

        $contact = $this->resolveRootContactAction->handle($message->contact);
        $lockedContact = Contact::query()
            ->whereKey($contact->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedContact instanceof Contact) {
            return false;
        }

        return match ($fieldKey) {
            'phone' => $this->applyV3ContactPhoneCapture($message, $lockedContact, $capturedValue),
            'first_name' => $this->applyV3ContactFirstNameCapture($lockedContact, $value),
            'last_name', 'country', 'city' => $this->applyV3ContactStringCapture($lockedContact, $fieldKey, $value),
            'gender' => $this->applyV3ContactEnumCapture($lockedContact, $fieldKey, $value, Contact::genderOptions()),
            'age_range' => $this->applyV3ContactEnumCapture($lockedContact, $fieldKey, $value, Contact::ageRangeOptions()),
            'age_years' => $this->applyV3ContactAgeYearsCapture($lockedContact, $value),
            default => false,
        };
    }

    /**
     * @param  array{valid?: bool, value?: string|null, phone_raw?: string|null, phone_normalized?: string|null}  $capturedValue
     */
    private function applyV3ContactPhoneCapture(Message $message, Contact $contact, array $capturedValue): bool
    {
        $phoneNormalized = trim((string) ($capturedValue['phone_normalized'] ?? $capturedValue['value'] ?? ''));
        $phoneRaw = trim((string) ($capturedValue['phone_raw'] ?? $phoneNormalized));

        if ($phoneRaw === '' || $phoneNormalized === '') {
            return false;
        }

        try {
            $this->addContactPhoneAction->handle($contact, $phoneRaw, ContactPhoneNumber::SOURCE_V3_CAPTURE);
            $this->syncDialogConfirmedPhoneAction->handle($message, $phoneRaw, $phoneNormalized);
        } catch (Throwable $throwable) {
            Log::warning('scenario.v3_contact_phone_capture_failed', [
                'scenario_code' => $this->code(),
                'contact_id' => $contact->id,
                'message_id' => $message->id,
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    private function applyV3ContactFirstNameCapture(Contact $contact, string $value): bool
    {
        $result = $this->applyContactFirstNameAction->handle(
            $contact,
            $value,
            Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            ApplyContactFirstNameAction::REASON_SCENARIO_CONFIRMED,
        );

        if ($result->changed) {
            $this->queueBitrix24ContactSyncAction->handle($contact);
        }

        return true;
    }

    private function applyV3ContactStringCapture(Contact $contact, string $fieldKey, string $value): bool
    {
        if (mb_strlen($value) > 255) {
            return false;
        }

        return $this->updateV3ContactAttribute($contact, $fieldKey, $value);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function applyV3ContactEnumCapture(Contact $contact, string $fieldKey, string $value, array $options): bool
    {
        $normalizedValue = $this->normalizeV3ContactEnumValue($value, $options);

        if ($normalizedValue === null) {
            return false;
        }

        return $this->updateV3ContactAttribute($contact, $fieldKey, $normalizedValue);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function normalizeV3ContactEnumValue(string $value, array $options): ?string
    {
        $normalizedValue = $this->normalizeV3ButtonText($value);

        foreach ($options as $key => $label) {
            if ($normalizedValue === $this->normalizeV3ButtonText((string) $key)) {
                return (string) $key;
            }

            if ($normalizedValue === $this->normalizeV3ButtonText((string) $label)) {
                return (string) $key;
            }
        }

        return null;
    }

    private function applyV3ContactAgeYearsCapture(Contact $contact, string $value): bool
    {
        if (preg_match('/^\d{1,3}$/', $value) !== 1) {
            return false;
        }

        $age = (int) $value;

        if ($age < 1 || $age > 120) {
            return false;
        }

        return $this->updateV3ContactAttribute($contact, 'age_years', $age);
    }

    private function updateV3ContactAttribute(Contact $contact, string $fieldKey, mixed $value): bool
    {
        if ($contact->getAttribute($fieldKey) === $value) {
            return true;
        }

        $contact->forceFill([$fieldKey => $value])->save();
        $this->queueBitrix24ContactSyncAction->handle($contact);

        return true;
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @param  array<string, mixed>  $statePayload
     * @return array{
     *     status: string,
     *     current_step: ?string,
     *     state_payload: array<string, mixed>,
     *     exit_outcome: ?string,
     * }
     */
    private function advanceV3FromBlock(
        Message $message,
        array $runtime,
        string $blockId,
        array $statePayload,
        ?int $remainingTransitions = null,
        ?ScenarioRun $run = null,
        bool $preservePreviousStateForTerminalNonState = false,
    ): array {
        $remainingTransitions ??= count(is_array($runtime['blocks'] ?? null) ? $runtime['blocks'] : []) + 1;

        if ($remainingTransitions < 1) {
            throw new RuntimeException("Scenario [{$this->code()}] V3 exceeded safe transition limit.");
        }

        $resolvedBlockId = $this->v3RuntimeBlockId($runtime, $blockId, $statePayload);
        $block = $resolvedBlockId !== null ? $this->v3RuntimeBlock($runtime, $resolvedBlockId) : null;

        if (! is_array($block)) {
            throw new RuntimeException("Scenario [{$this->code()}] V3 references missing block [{$blockId}].");
        }

        $blockId = $resolvedBlockId;
        $isNonStateBlock = $this->isV3NonStateBlock($block);
        $previousBlockId = $this->v3CurrentRuntimeBlockId($runtime, $statePayload);

        if (! $isNonStateBlock) {
            $statePayload = $this->markV3Running($statePayload, $blockId);
        }

        $messagePayload = is_array($block['message'] ?? null) ? $block['message'] : null;
        $visibleButtonRows = $this->v3VisibleButtonRows($block, $message->channel);
        $waitingButtonRows = $this->v3WaitingButtonRows($block, $message->channel);
        $buttonPlacement = $this->v3ButtonPlacement($block);

        if ($messagePayload !== null) {
            $replyButtonRows = $visibleButtonRows !== [] ? $visibleButtonRows : null;

            if (! $this->dispatchScenarioMessage(
                $message,
                (string) ($messagePayload['text'] ?? ''),
                (string) ($messagePayload['text_format'] ?? Message::TEXT_FORMAT_PLAIN_TEXT),
                replyButtonRows: $replyButtonRows,
                buttonPlacement: $buttonPlacement,
                v3CallbackBlockId: $blockId,
            )) {
                $activeBlockId = $isNonStateBlock && $previousBlockId !== null ? $previousBlockId : $blockId;

                return $this->activeProgress(
                    $activeBlockId,
                    $this->markPendingPromptDelivery($statePayload),
                );
            }
        }

        if ($isNonStateBlock) {
            if ($previousBlockId !== null) {
                return $this->activeProgress($previousBlockId, $statePayload);
            }

            data_set($statePayload, 'v3.status', 'completed');
            data_set($statePayload, 'v3.current_block_id', null);
            data_set($statePayload, 'v3.waiting_output_ids', []);

            return [
                'status' => ScenarioRun::STATUS_COMPLETED,
                'current_step' => null,
                'state_payload' => $statePayload,
                'exit_outcome' => 'completed',
            ];
        }

        $automaticProgress = $this->advanceV3AutomaticEdges(
            $message,
            $runtime,
            $block,
            $statePayload,
            $remainingTransitions,
            $run,
        );

        if ($automaticProgress !== null) {
            return $automaticProgress;
        }

        if ($waitingButtonRows !== []) {
            return $this->activeProgress(
                $blockId,
                $this->markV3Waiting($statePayload, $blockId, $block, $message->channel),
            );
        }

        return $this->activeProgress(
            $blockId,
            $this->markV3Waiting($statePayload, $blockId, $block, $message->channel),
        );
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $statePayload
     * @return array{
     *     status: string,
     *     current_step: ?string,
     *     state_payload: array<string, mixed>,
     *     exit_outcome: ?string,
     * }|null
     */
    private function advanceV3AutomaticEdges(
        Message $message,
        array $runtime,
        array $block,
        array $statePayload,
        int $remainingTransitions,
        ?ScenarioRun $run,
    ): ?array {
        $progress = null;
        $sourceBlockId = filled($block['id'] ?? null) ? (string) $block['id'] : null;

        foreach ($this->v3AutomaticEdges($block) as $edge) {
            if (
                ! $this->v3EdgeAllowsContactPhone($message, $edge)
                || ! $this->v3EdgeAllowsFieldCondition($message, $edge)
            ) {
                continue;
            }

            $targetBlockId = filled($edge['target_block_id'] ?? null) ? (string) $edge['target_block_id'] : null;

            if ($targetBlockId === null) {
                continue;
            }

            if ($run instanceof ScenarioRun && $sourceBlockId !== null && $this->v3AutomaticEdgeUsesQueue($edge)) {
                $scheduledFor = $this->v3AutomaticEdgeScheduledFor($edge);

                if ($scheduledFor !== null) {
                    $this->scheduleV3DelayedAutomaticTransition($message, $run, $edge, $sourceBlockId, $scheduledFor);
                }

                continue;
            }

            $dialog = $message->dialog_id !== null
                ? Dialog::query()->find($message->dialog_id)
                : null;

            if (
                $dialog instanceof Dialog
                && ! $this->applyV3TransitionSideEffectsToDialog($dialog, $message, $edge, null)
            ) {
                continue;
            }

            $progress = $this->advanceV3FromBlock(
                $message,
                $runtime,
                $targetBlockId,
                $statePayload,
                $remainingTransitions - 1,
                $run,
            );
            $statePayload = $progress['state_payload'];
            $remainingTransitions -= 1;
        }

        return $progress;
    }

    /**
     * @param  array<string, mixed>  $edge
     */
    private function scheduleV3DelayedAutomaticTransition(
        Message $message,
        ScenarioRun $run,
        array $edge,
        string $sourceBlockId,
        \DateTimeInterface $scheduledFor,
    ): void {
        if ($message->dialog_id === null) {
            return;
        }

        $targetBlockId = filled($edge['target_block_id'] ?? null) ? (string) $edge['target_block_id'] : null;

        if ($targetBlockId === null) {
            return;
        }

        $transition = ScenarioV3ScheduledTransition::query()->create([
            'scenario_run_id' => $run->id,
            'dialog_id' => $message->dialog_id,
            'inbound_message_id' => $message->id,
            'scenario_code' => $this->code(),
            'published_version_id' => $this->publishedVersion->id,
            'edge_key' => (string) ($edge['edge_key'] ?? $edge['id'] ?? ''),
            'edge_id' => filled($edge['id'] ?? null) ? (string) $edge['id'] : null,
            'source_block_id' => $sourceBlockId,
            'target_block_id' => $targetBlockId,
            'delay_payload' => $this->v3EdgeDelay($edge),
            'scheduled_for' => $scheduledFor,
            'status' => ScenarioV3ScheduledTransition::STATUS_SCHEDULED,
        ]);

        ProcessScenarioV3ScheduledTransitionJob::dispatch($transition->id, $run->id)
            ->delay($scheduledFor)
            ->afterCommit();

        Log::info('scenario.v3.delayed_transition.scheduled', [
            'transition_id' => $transition->id,
            'scenario_code' => $this->code(),
            'scenario_run_id' => $run->id,
            'dialog_id' => $message->dialog_id,
            'published_version_id' => $this->publishedVersion->id,
            'edge_key' => $transition->edge_key,
            'source_block_id' => $sourceBlockId,
            'target_block_id' => $targetBlockId,
            'scheduled_for' => CarbonImmutable::instance($scheduledFor)->toJSON(),
        ]);
    }

    private function claimScheduledV3Transition(ScenarioV3ScheduledTransition $transition): ?ScenarioV3ScheduledTransition
    {
        return DB::transaction(function () use ($transition): ?ScenarioV3ScheduledTransition {
            $lockedTransition = ScenarioV3ScheduledTransition::query()
                ->whereKey($transition->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $lockedTransition instanceof ScenarioV3ScheduledTransition
                || $lockedTransition->status !== ScenarioV3ScheduledTransition::STATUS_SCHEDULED
            ) {
                return null;
            }

            if ($lockedTransition->scheduled_for !== null && $lockedTransition->scheduled_for->isFuture()) {
                return null;
            }

            $lockedTransition->forceFill([
                'status' => ScenarioV3ScheduledTransition::STATUS_PROCESSING,
                'processing_started_at' => now(),
            ])->save();

            return $lockedTransition;
        });
    }

    private function processClaimedV3ScheduledTransition(ScenarioV3ScheduledTransition $transition): void
    {
        DB::transaction(function () use ($transition): void {
            $lockedTransition = ScenarioV3ScheduledTransition::query()
                ->whereKey($transition->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $lockedTransition instanceof ScenarioV3ScheduledTransition
                || $lockedTransition->status !== ScenarioV3ScheduledTransition::STATUS_PROCESSING
            ) {
                return;
            }

            if ((int) $lockedTransition->published_version_id !== (int) $this->publishedVersion->id) {
                $this->finishV3ScheduledTransition(
                    $lockedTransition,
                    ScenarioV3ScheduledTransition::STATUS_CANCELLED,
                    'Версия сценария изменилась.',
                );

                return;
            }

            $dialog = Dialog::query()
                ->whereKey($lockedTransition->dialog_id)
                ->lockForUpdate()
                ->first();
            $run = ScenarioRun::query()
                ->whereKey($lockedTransition->scenario_run_id)
                ->lockForUpdate()
                ->first();
            $message = Message::query()
                ->with(['channel', 'contact', 'contactIdentity', 'dialog'])
                ->find($lockedTransition->inbound_message_id);

            if (
                ! $dialog instanceof Dialog
                || ! $run instanceof ScenarioRun
                || ! $run->isActive()
                || ! $message instanceof Message
                || (int) $run->dialog_id !== (int) $lockedTransition->dialog_id
            ) {
                $this->finishV3ScheduledTransition(
                    $lockedTransition,
                    ScenarioV3ScheduledTransition::STATUS_CANCELLED,
                    'Активный run, диалог или исходное сообщение не найдены.',
                );

                return;
            }

            $runtime = $this->v3RuntimeOrNull();

            if ($runtime === null) {
                $this->finishV3ScheduledTransition(
                    $lockedTransition,
                    ScenarioV3ScheduledTransition::STATUS_CANCELLED,
                    'Runtime V3 недоступен.',
                );

                return;
            }

            $statePayload = $this->v3StatePayload($run->state_payload);

            if ($this->v3ScheduledTransitionRequiresSourceBlock($lockedTransition)) {
                $currentBlockId = $this->v3CurrentRuntimeBlockId($runtime, $statePayload);

                if ($currentBlockId !== $lockedTransition->source_block_id) {
                    $this->finishV3ScheduledTransition(
                        $lockedTransition,
                        ScenarioV3ScheduledTransition::STATUS_CANCELLED,
                        'Диалог ушёл из исходного блока.',
                    );

                    return;
                }
            }

            $edge = $this->v3ScheduledAutomaticEdge($runtime, $lockedTransition);

            if ($edge === null) {
                $this->finishV3ScheduledTransition(
                    $lockedTransition,
                    ScenarioV3ScheduledTransition::STATUS_CANCELLED,
                    'Стрелка больше не найдена в опубликованном runtime.',
                );

                return;
            }

            if (! $this->applyV3TransitionSideEffectsToDialog($dialog, $message, $edge, null)) {
                $this->finishV3ScheduledTransition(
                    $lockedTransition,
                    ScenarioV3ScheduledTransition::STATUS_LIMIT_REACHED,
                    'Лимит переходов исчерпан или данные диалога не сохранены.',
                );

                return;
            }

            $progress = $this->advanceV3FromBlock(
                $message,
                $runtime,
                $lockedTransition->target_block_id,
                $statePayload,
                run: $run,
            );

            $run->forceFill([
                'status' => $progress['status'],
                'current_step' => $progress['current_step'],
                'state_payload' => $progress['state_payload'],
                'exit_outcome' => $progress['exit_outcome'],
                'finished_at' => $progress['status'] === ScenarioRun::STATUS_ACTIVE ? null : now(),
            ])->save();

            $this->finishV3ScheduledTransition($lockedTransition, ScenarioV3ScheduledTransition::STATUS_PASSED);
        });
    }

    private function finishV3ScheduledTransition(
        ScenarioV3ScheduledTransition $transition,
        string $status,
        ?string $errorMessage = null,
    ): void {
        $safeErrorMessage = $this->safeV3ScheduledTransitionErrorMessage($errorMessage, $transition);

        $transition->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'error_message' => $safeErrorMessage,
        ])->save();

        Log::info('scenario.v3.delayed_transition.finished', [
            'transition_id' => $transition->id,
            'status' => $status,
            'scenario_code' => $transition->scenario_code,
            'scenario_run_id' => $transition->scenario_run_id,
            'dialog_id' => $transition->dialog_id,
            'published_version_id' => $transition->published_version_id,
            'edge_key' => $transition->edge_key,
            'error_message' => $safeErrorMessage,
        ]);
    }

    private function safeV3ScheduledTransitionErrorMessage(
        ?string $message,
        ScenarioV3ScheduledTransition $transition,
    ): ?string {
        if (! filled($message)) {
            return null;
        }

        $safeMessage = trim((string) $message);
        $channel = $transition->inboundMessage()->with('channel')->first()?->channel;

        foreach ([$channel?->getToken(), $channel?->getWebhookSecret()] as $secret) {
            if (filled($secret)) {
                $safeMessage = str_replace((string) $secret, '[secret]', $safeMessage);
            }
        }

        $safeMessage = preg_replace('/bot[0-9A-Za-z:_-]+(?=\/)/u', 'bot[secret]', $safeMessage) ?? $safeMessage;
        $safeMessage = preg_replace('/([?&](?:token|access_token|auth|secret)=)[^&\s]+/iu', '$1[secret]', $safeMessage) ?? $safeMessage;

        return mb_substr($safeMessage, 0, 1000);
    }

    private function v3ScheduledTransitionRequiresSourceBlock(ScenarioV3ScheduledTransition $transition): bool
    {
        $delay = is_array($transition->delay_payload) ? $transition->delay_payload : [];

        return (bool) ($delay['cancel_if_left_source_block'] ?? true);
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return array<string, mixed>|null
     */
    private function v3ScheduledAutomaticEdge(array $runtime, ScenarioV3ScheduledTransition $transition): ?array
    {
        $sourceBlock = $this->v3RuntimeBlock($runtime, $transition->source_block_id);

        if (! is_array($sourceBlock)) {
            return null;
        }

        return collect($this->v3AutomaticEdges($sourceBlock))
            ->first(function (array $edge) use ($transition): bool {
                $edgeKey = (string) ($edge['edge_key'] ?? '');
                $edgeId = (string) ($edge['id'] ?? '');

                return $edgeKey === $transition->edge_key
                    && ($transition->edge_id === null || $edgeId === $transition->edge_id)
                    && (string) ($edge['target_block_id'] ?? '') === $transition->target_block_id;
            });
    }

    /**
     * @param  array<string, mixed>  $edge
     */
    private function v3AutomaticEdgeDelaySeconds(array $edge): int
    {
        $delay = $this->v3EdgeDelay($edge);
        $value = max(0, (int) ($delay['value'] ?? 0));

        if ($value < 1) {
            return 0;
        }

        return ($delay['unit'] ?? 'sec') === 'min' ? $value * 60 : $value;
    }

    /**
     * @param  array<string, mixed>  $edge
     */
    private function v3AutomaticEdgeUsesQueue(array $edge): bool
    {
        $delay = $this->v3EdgeDelay($edge);

        return ($delay['type'] ?? null) === 'scheduled'
            || $this->v3AutomaticEdgeDelaySeconds($edge) > 0;
    }

    /**
     * @param  array<string, mixed>  $edge
     */
    private function v3AutomaticEdgeScheduledFor(array $edge): ?CarbonImmutable
    {
        $delay = $this->v3EdgeDelay($edge);

        if (($delay['type'] ?? null) === 'scheduled') {
            try {
                $scheduledFor = CarbonImmutable::parse((string) ($delay['scheduled_at'] ?? ''), config('app.timezone', 'UTC'));
            } catch (Throwable $throwable) {
                Log::warning('scenario.v3.delayed_transition.invalid_scheduled_at', [
                    'scenario_code' => $this->code(),
                    'published_version_id' => $this->publishedVersion->id,
                    'edge_key' => (string) ($edge['edge_key'] ?? $edge['id'] ?? ''),
                    'scheduled_at' => $delay['scheduled_at'] ?? null,
                    'error' => $throwable->getMessage(),
                ]);

                return null;
            }

            if ($scheduledFor->lessThanOrEqualTo(CarbonImmutable::now())) {
                Log::warning('scenario.v3.delayed_transition.scheduled_at_in_past', [
                    'scenario_code' => $this->code(),
                    'published_version_id' => $this->publishedVersion->id,
                    'edge_key' => (string) ($edge['edge_key'] ?? $edge['id'] ?? ''),
                    'scheduled_at' => $scheduledFor->toJSON(),
                ]);

                return null;
            }

            return $scheduledFor->setTimezone(config('app.timezone', 'UTC'));
        }

        $delaySeconds = $this->v3AutomaticEdgeDelaySeconds($edge);

        return $delaySeconds > 0 ? CarbonImmutable::now()->addSeconds($delaySeconds) : null;
    }

    /**
     * @param  array<string, mixed>  $edge
     * @return array{type: string, value: int, unit: string, scheduled_at: string|null, cancel_if_left_source_block: bool}
     */
    private function v3EdgeDelay(array $edge): array
    {
        $delay = is_array($edge['delay'] ?? null) ? $edge['delay'] : [];
        $type = (string) ($delay['type'] ?? '');
        $value = max(0, (int) ($delay['value'] ?? 0));
        $unit = $value > 0 && ($delay['unit'] ?? 'sec') === 'min' ? 'min' : 'sec';

        if ($type === 'scheduled') {
            return [
                'type' => 'scheduled',
                'value' => 0,
                'unit' => 'sec',
                'scheduled_at' => filled($delay['scheduled_at'] ?? null) ? (string) $delay['scheduled_at'] : null,
                'cancel_if_left_source_block' => (bool) ($delay['cancel_if_left_source_block'] ?? true),
            ];
        }

        return [
            'type' => $value > 0 ? 'relative' : 'immediate',
            'value' => $value,
            'unit' => $unit,
            'scheduled_at' => null,
            'cancel_if_left_source_block' => (bool) ($delay['cancel_if_left_source_block'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    private function v3AutomaticEdges(array $block): array
    {
        $edges = is_array($block['automatic_edges'] ?? null) ? $block['automatic_edges'] : [];

        if ($edges === [] && filled($block['default_target_block_id'] ?? null)) {
            $edges[] = [
                'id' => 'default',
                'edge_key' => 'default',
                'mode' => 'automatic',
                'priority' => 10,
                'transition_limit' => 0,
                'target_block_id' => (string) $block['default_target_block_id'],
            ];
        }

        return collect($edges)
            ->filter(fn (mixed $edge): bool => is_array($edge)
                && ($edge['mode'] ?? null) === 'automatic'
                && filled($edge['target_block_id'] ?? null))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function v3StartBlockIdForMessage(Message $message, array $runtime): string
    {
        if (
            $this->matchedV3StartMessageId === (int) $message->id
            && $this->matchedV3RuntimeBlockId !== null
            && $this->v3RuntimeBlock($runtime, $this->matchedV3RuntimeBlockId) !== null
        ) {
            return $this->matchedV3RuntimeBlockId;
        }

        $blockId = $this->matchingV3EntrypointBlockId($message, $runtime);

        if ($blockId !== null) {
            return $blockId;
        }

        throw new RuntimeException("Scenario [{$this->code()}] V3 does not have a matching entrypoint.");
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function matchingV3EntrypointBlockId(Message $message, array $runtime): ?string
    {
        return collect($this->v3Entrypoints($runtime))
            ->map(fn (array $entrypoint): ?string => $this->matchingV3EntrypointBlockIdFromEntrypoint($message, $runtime, $entrypoint))
            ->filter()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @param  array<string, mixed>  $entrypoint
     */
    private function matchingV3EntrypointBlockIdFromEntrypoint(Message $message, array $runtime, array $entrypoint): ?string
    {
        $channelIds = collect($entrypoint['channel_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($message->channel_id === null || ! in_array((int) $message->channel_id, $channelIds, true)) {
            return null;
        }

        if (! $this->v3EntrypointAllowsContactPhone($message, $entrypoint)) {
            return null;
        }

        $match = (string) ($entrypoint['match'] ?? 'strict');

        foreach ($entrypoint['values'] ?? [] as $value) {
            if (! $this->messageMatchesV3Value($message, $match, (string) $value)) {
                continue;
            }

            $blockId = trim((string) ($entrypoint['block_id'] ?? ''));
            $resolvedBlockId = $this->v3RuntimeBlockId($runtime, $blockId);

            if ($resolvedBlockId !== null) {
                return $resolvedBlockId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     */
    private function v3EntrypointAllowsContactPhone(Message $message, array $entrypoint): bool
    {
        $condition = trim((string) ($entrypoint['contact_phone_condition'] ?? ''));

        if ($condition === '') {
            return true;
        }

        if (! in_array($condition, [
            AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
        ], true)) {
            return false;
        }

        $hasPhone = $message->contact?->phoneNumbers()->exists() ?? false;

        return $condition === AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE
            ? $hasPhone
            : ! $hasPhone;
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return list<array<string, mixed>>
     */
    private function v3Entrypoints(array $runtime): array
    {
        $entrypoints = is_array($runtime['entrypoints'] ?? null) ? $runtime['entrypoints'] : [];

        return collect($entrypoints)
            ->filter(fn (mixed $entrypoint): bool => is_array($entrypoint))
            ->sort(fn (array $left, array $right): int => $this->compareV3Entrypoints($left, $right))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareV3Entrypoints(array $left, array $right): int
    {
        return [
            (int) ($right['priority'] ?? 10),
            $this->v3EntrypointBlockOrder($right),
            (string) ($right['block_id'] ?? ''),
        ] <=> [
            (int) ($left['priority'] ?? 10),
            $this->v3EntrypointBlockOrder($left),
            (string) ($left['block_id'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     */
    private function v3EntrypointBlockOrder(array $entrypoint): int
    {
        $blockId = $entrypoint['block_id'] ?? null;

        return is_numeric($blockId) ? (int) $blockId : PHP_INT_MIN;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    private function v3ButtonTextEdges(Message $message, array $block): array
    {
        $messageText = $this->normalizeV3ButtonText((string) $message->text);

        if ($messageText === '') {
            return [];
        }

        $edges = [];

        foreach ($this->v3TextInputButtonRows($block) as $row) {
            foreach ($row as $button) {
                if ($messageText !== (string) ($button['normalized_text'] ?? '')) {
                    continue;
                }

                $edge = $this->v3ButtonTransitionEdge($button);

                if ($edge !== null) {
                    $edges[] = $edge;
                }
            }
        }

        return $edges;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function v3TargetForButtonCallback(string $callbackData, array $block, ?Channel $channel): ?string
    {
        $callback = $this->v3ButtonCallbackFromData($callbackData);

        if ($callback === null || ! $this->v3ButtonCallbackMatchesBlock($callback, $block)) {
            return null;
        }

        foreach ($this->v3VisibleButtonRows($block, $channel) as $row) {
            foreach ($row as $button) {
                if (($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) !== self::V3_BUTTON_TYPE_TEXT) {
                    continue;
                }

                if (
                    filled($button['target_block_id'] ?? null)
                    && (string) ($button['output_id'] ?? '') === $callback['output_id']
                ) {
                    return filled($button['target_block_id'] ?? null) ? (string) $button['target_block_id'] : null;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    private function v3ButtonCallbackEdges(string $callbackData, array $block, ?Channel $channel): array
    {
        $callback = $this->v3ButtonCallbackFromData($callbackData);

        if ($callback === null || ! $this->v3ButtonCallbackMatchesBlock($callback, $block)) {
            return [];
        }

        $edges = [];

        foreach ($this->v3VisibleButtonRows($block, $channel) as $row) {
            foreach ($row as $button) {
                if (($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) !== self::V3_BUTTON_TYPE_TEXT) {
                    continue;
                }

                if ((string) ($button['output_id'] ?? '') !== $callback['output_id']) {
                    continue;
                }

                $edge = $this->v3ButtonTransitionEdge($button);

                if ($edge !== null) {
                    $edges[] = $edge;
                }
            }
        }

        return $edges;
    }

    /**
     * @return array{block_id: string, output_id: string}|null
     */
    private function v3ButtonCallbackFromData(string $callbackData): ?array
    {
        if (strlen($callbackData) > self::V3_TELEGRAM_BUTTON_CALLBACK_MAX_BYTES) {
            return null;
        }

        if (! preg_match('/^v3b:([A-Za-z0-9_-]{1,64}):([A-Za-z0-9_-]{1,64})$/', $callbackData, $matches)) {
            return null;
        }

        return [
            'block_id' => (string) $matches[1],
            'output_id' => (string) $matches[2],
        ];
    }

    /**
     * @param  array{block_id: string, output_id: string}  $callback
     * @param  array<string, mixed>  $block
     */
    private function v3ButtonCallbackMatchesBlock(array $callback, array $block): bool
    {
        return (string) ($block['id'] ?? '') === $callback['block_id'];
    }

    private function v3TelegramButtonCallbackData(string $blockId, string $outputId): ?string
    {
        if (
            ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $blockId)
            || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $outputId)
        ) {
            return null;
        }

        $callbackData = self::V3_TELEGRAM_BUTTON_CALLBACK_PREFIX.$blockId.':'.$outputId;

        return strlen($callbackData) <= self::V3_TELEGRAM_BUTTON_CALLBACK_MAX_BYTES
            ? $callbackData
            : null;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function v3TargetForContactShare(array $block, ?Channel $channel = null): ?string
    {
        foreach ($this->v3RequestPhoneButtonRows($block) as $row) {
            foreach ($row as $button) {
                if (filled($button['target_block_id'] ?? null)) {
                    return (string) $button['target_block_id'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    private function v3RequestPhoneButtonEdges(array $block): array
    {
        $edges = [];

        foreach ($this->v3RequestPhoneButtonRows($block) as $row) {
            foreach ($row as $button) {
                $edge = $this->v3ButtonTransitionEdge($button);

                if ($edge !== null) {
                    $edges[] = $edge;
                }
            }
        }

        return $edges;
    }

    /**
     * @param  array<string, mixed>  $button
     * @return array<string, mixed>|null
     */
    private function v3ButtonTransitionEdge(array $button): ?array
    {
        if (! filled($button['target_block_id'] ?? null)) {
            return null;
        }

        $edge = is_array($button['edge'] ?? null) ? $button['edge'] : [];
        $outputId = (string) ($button['output_id'] ?? $button['id'] ?? '');

        $transitionEdge = array_merge([
            'id' => (string) ($button['edge_id'] ?? $outputId),
            'edge_key' => (string) ($button['edge_key'] ?? $outputId),
            'mode' => 'button',
            'priority' => 10,
            'transition_limit' => 0,
            'target_block_id' => (string) $button['target_block_id'],
            'from_output_id' => $outputId,
            'label' => (string) ($button['text'] ?? ''),
            'match' => [
                'type' => 'exact_text',
                'text' => (string) ($button['text'] ?? ''),
                'variants' => [(string) ($button['text'] ?? '')],
            ],
            'input_capture' => [
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'data_type' => 'any_text',
            ],
        ], $edge, [
            'mode' => 'button',
            'target_block_id' => filled($edge['target_block_id'] ?? null)
                ? (string) $edge['target_block_id']
                : (string) $button['target_block_id'],
            'from_output_id' => filled($edge['from_output_id'] ?? null)
                ? (string) $edge['from_output_id']
                : $outputId,
        ]);

        if (! filled($transitionEdge['id'] ?? null)) {
            $transitionEdge['id'] = $outputId;
        }

        if (! filled($transitionEdge['edge_key'] ?? null)) {
            $transitionEdge['edge_key'] = (string) ($transitionEdge['id'] ?? $outputId);
        }

        return $transitionEdge;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<list<array<string, mixed>>>
     */
    private function v3ButtonRows(array $block): array
    {
        return $this->normalizeV3ButtonRows(data_get($block, 'buttons.rows', []));
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<list<array<string, mixed>>>
     */
    private function v3VisibleButtonRows(array $block, ?Channel $channel): array
    {
        $placement = $this->v3ButtonPlacement($block);

        return collect($this->v3ButtonRows($block))
            ->map(fn (array $row): array => collect($row)
                ->filter(fn (array $button): bool => $this->v3ButtonVisibleForChannel($button, $placement, $channel))
                ->values()
                ->all())
            ->filter(fn (array $row): bool => $row !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<list<array<string, mixed>>>
     */
    private function v3WaitingButtonRows(array $block, ?Channel $channel = null): array
    {
        $placement = $this->v3ButtonPlacement($block);

        return collect($this->v3ButtonRows($block))
            ->map(fn (array $row): array => collect($row)
                ->filter(fn (array $button): bool => filled($button['target_block_id'] ?? null)
                    && (
                        ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) === self::V3_BUTTON_TYPE_TEXT
                        || (
                            ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) === self::V3_BUTTON_TYPE_REQUEST_PHONE
                            && $this->v3ButtonVisibleForChannel($button, $placement, $channel)
                        )
                    ))
                ->values()
                ->all())
            ->filter(fn (array $row): bool => $row !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<list<array<string, mixed>>>
     */
    private function v3TextInputButtonRows(array $block): array
    {
        return collect($this->v3ButtonRows($block))
            ->map(fn (array $row): array => collect($row)
                ->filter(fn (array $button): bool => filled($button['target_block_id'] ?? null)
                    && ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) === self::V3_BUTTON_TYPE_TEXT)
                ->values()
                ->all())
            ->filter(fn (array $row): bool => $row !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<list<array<string, mixed>>>
     */
    private function v3RequestPhoneButtonRows(array $block): array
    {
        return collect($this->v3ButtonRows($block))
            ->map(fn (array $row): array => collect($row)
                ->filter(fn (array $button): bool => filled($button['target_block_id'] ?? null)
                    && ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) === self::V3_BUTTON_TYPE_REQUEST_PHONE)
                ->values()
                ->all())
            ->filter(fn (array $row): bool => $row !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function v3ButtonPlacement(array $block): string
    {
        $placement = (string) data_get($block, 'buttons.placement', self::V3_BUTTON_PLACEMENT_AUTO);

        return in_array($placement, [
            self::V3_BUTTON_PLACEMENT_AUTO,
            self::V3_BUTTON_PLACEMENT_REPLY_KEYBOARD,
            self::V3_BUTTON_PLACEMENT_INLINE_MESSAGE,
        ], true) ? $placement : self::V3_BUTTON_PLACEMENT_AUTO;
    }

    /**
     * @param  array<string, mixed>  $button
     */
    private function v3ButtonVisibleForChannel(array $button, string $placement, ?Channel $channel): bool
    {
        if (! $channel instanceof Channel) {
            return true;
        }

        $type = (string) ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT);

        if ($channel->platform === Channel::PLATFORM_MAX) {
            return $placement !== self::V3_BUTTON_PLACEMENT_REPLY_KEYBOARD;
        }

        if ($channel->platform === Channel::PLATFORM_TELEGRAM) {
            if ($type === self::V3_BUTTON_TYPE_LINK) {
                return $placement !== self::V3_BUTTON_PLACEMENT_REPLY_KEYBOARD;
            }

            return $placement !== self::V3_BUTTON_PLACEMENT_INLINE_MESSAGE
                || $type !== self::V3_BUTTON_TYPE_REQUEST_PHONE;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function isV3NonStateBlock(array $block): bool
    {
        return ($block['kind'] ?? null) === 'non_state';
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function v3CurrentBlockId(array $statePayload): ?string
    {
        $blockId = trim((string) data_get($statePayload, 'v3.current_block_id', ''));

        return $blockId === '' ? null : $blockId;
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function v3CurrentRuntimeBlockId(array $runtime, array $statePayload): ?string
    {
        $blockId = $this->v3CurrentBlockId($statePayload);

        return $blockId !== null ? $this->v3RuntimeBlockId($runtime, $blockId, $statePayload) : null;
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @param  array<string, mixed>  $statePayload
     */
    private function v3RuntimeBlockId(array $runtime, string $blockId, array $statePayload = []): ?string
    {
        $blockId = trim($blockId);

        if ($blockId === '') {
            return null;
        }

        if ($this->v3RuntimeBlock($runtime, $blockId) !== null) {
            return $blockId;
        }

        foreach ($this->v3RuntimeBlocks($runtime) as $runtimeBlockKey => $runtimeBlock) {
            if (! is_array($runtimeBlock)) {
                continue;
            }

            $runtimeId = trim((string) ($runtimeBlock['id'] ?? $runtimeBlock['card_id'] ?? $runtimeBlockKey));
            $dbId = trim((string) ($runtimeBlock['db_id'] ?? ''));

            if ($runtimeId === $blockId || $dbId === $blockId) {
                return $runtimeId !== '' ? $runtimeId : (string) $runtimeBlockKey;
            }
        }

        $previousCardId = $this->v3PreviousPublishedCardIdForDbBlockId($blockId, $statePayload);

        if ($previousCardId !== null && $this->v3RuntimeBlock($runtime, $previousCardId) !== null) {
            return $previousCardId;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return array<string|int, mixed>
     */
    private function v3RuntimeBlocks(array $runtime): array
    {
        return is_array($runtime['blocks'] ?? null) ? $runtime['blocks'] : [];
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return array<string, mixed>|null
     */
    private function v3RuntimeBlock(array $runtime, string $blockId): ?array
    {
        $blocks = $this->v3RuntimeBlocks($runtime);

        if (array_key_exists($blockId, $blocks) && is_array($blocks[$blockId])) {
            return $blocks[$blockId];
        }

        if (is_numeric($blockId) && array_key_exists((int) $blockId, $blocks) && is_array($blocks[(int) $blockId])) {
            return $blocks[(int) $blockId];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function v3PreviousPublishedCardIdForDbBlockId(string $blockId, array $statePayload): ?string
    {
        if (! is_numeric($blockId)) {
            return null;
        }

        $publishedVersionId = (int) (
            data_get($statePayload, 'v3.previous_published_version_id')
            ?: data_get($statePayload, 'v3.published_version_id', 0)
        );

        if ($publishedVersionId <= 0 || $publishedVersionId === (int) $this->publishedVersion->id) {
            return null;
        }

        $block = ScenarioBuilderBlock::query()
            ->where('scenario_version_id', $publishedVersionId)
            ->whereKey((int) $blockId)
            ->first();

        if (! $block instanceof ScenarioBuilderBlock) {
            return null;
        }

        $cardId = trim((string) data_get($block->settings_payload, 'ui.card_id', ''));

        return $cardId !== '' ? $cardId : null;
    }

    /**
     * @return list<list<array<string, mixed>>>
     */
    private function normalizeV3ButtonRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => collect($row)
                ->filter(fn (mixed $button): bool => is_array($button) && filled($button['text'] ?? null))
                ->map(function (array $button): array {
                    $text = trim((string) $button['text']);
                    $type = match ($button['type'] ?? null) {
                        self::V3_BUTTON_TYPE_REQUEST_PHONE => self::V3_BUTTON_TYPE_REQUEST_PHONE,
                        self::V3_BUTTON_TYPE_LINK => self::V3_BUTTON_TYPE_LINK,
                        default => self::V3_BUTTON_TYPE_TEXT,
                    };

                    return [
                        'id' => (string) ($button['id'] ?? ''),
                        'type' => $type,
                        'text' => $text,
                        'url' => $type === self::V3_BUTTON_TYPE_LINK ? trim((string) ($button['url'] ?? '')) : null,
                        'normalized_text' => $this->normalizeV3ButtonText($text),
                        'output_id' => (string) ($button['output_id'] ?? ($button['id'] ?? '')),
                        'target_block_id' => filled($button['target_block_id'] ?? null)
                            ? (string) $button['target_block_id']
                            : null,
                    ];
                })
                ->values()
                ->all())
            ->filter(fn (array $row): bool => $row !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|mixed  $statePayload
     * @return array<string, mixed>
     */
    private function v3StatePayload(mixed $statePayload): array
    {
        $statePayload = $this->normalizeStatePayload($statePayload);
        $v3 = is_array($statePayload['v3'] ?? null) ? $statePayload['v3'] : [];
        $previousPublishedVersionId = (int) ($v3['published_version_id'] ?? 0);

        if ($previousPublishedVersionId > 0 && $previousPublishedVersionId !== (int) $this->publishedVersion->id) {
            $v3['previous_published_version_id'] = $previousPublishedVersionId;
        }

        $v3['published_version_id'] = (int) $this->publishedVersion->id;
        $v3['schema_version'] = BuildScenarioBuilderV3StateAction::SCHEMA_VERSION;
        $statePayload['v3'] = $v3;

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function markV3Running(array $statePayload, string $blockId): array
    {
        data_set($statePayload, 'v3.status', 'running');
        data_set($statePayload, 'v3.current_block_id', $blockId);
        data_set($statePayload, 'v3.waiting_output_ids', []);

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function markV3Waiting(array $statePayload, string $blockId, array $block, ?Channel $channel = null): array
    {
        data_set($statePayload, 'v3.status', 'waiting_input');
        data_set($statePayload, 'v3.current_block_id', $blockId);
        data_set($statePayload, 'v3.waiting_output_ids', collect($this->v3WaitingButtonRows($block, $channel))
            ->flatten(1)
            ->pluck('output_id')
            ->filter()
            ->values()
            ->all());

        return $statePayload;
    }

    private function messageMatchesV3Value(Message $message, string $match, string $expectedValue): bool
    {
        $expectedValue = $this->normalizeV3ButtonText($expectedValue);
        $messageText = $this->normalizeV3ButtonText((string) $message->text);
        $messageParameter = $this->normalizeV3ButtonText((string) $message->message_parameter);

        if (
            $this->messageIsV3Callback($message)
            && ! in_array($match, [self::V3_MATCH_EXACT_CALLBACK, AutoReplyRule::MATCH_SCOPE_ANY_INBOUND], true)
        ) {
            return false;
        }

        if ($match === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND) {
            return true;
        }

        if ($expectedValue === '') {
            return false;
        }

        return match ($match) {
            'contains', AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT => str_contains($messageText, $expectedValue)
                || str_contains($messageParameter, $expectedValue),
            'starts', 'starts_with' => str_starts_with($messageText, $expectedValue)
                || str_starts_with($messageParameter, $expectedValue),
            AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER => $messageParameter === $expectedValue,
            AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD => $messageText === $expectedValue,
            AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER => $messageText === $expectedValue
                || $messageParameter === $expectedValue,
            self::V3_MATCH_EXACT_CALLBACK => $this->messageMatchesV3Callback($message, $expectedValue),
            default => $messageText === $expectedValue || $messageParameter === $expectedValue,
        };
    }

    private function messageMatchesV3Callback(Message $message, string $expectedValue): bool
    {
        $callbackValues = collect([
            data_get($message->raw_payload, 'callback_query.data'),
            $message->text,
        ])
            ->map(fn (mixed $value): string => $this->normalizeV3ButtonText((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique();

        return $callbackValues->contains($expectedValue)
            && $this->messageIsV3Callback($message);
    }

    private function messageIsV3Callback(Message $message): bool
    {
        return is_array(data_get($message->raw_payload, 'callback_query'));
    }

    private function normalizeV3ButtonText(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function v3RuntimeOrNull(): ?array
    {
        $runtime = data_get($this->publishedVersion->schema_payload, 'builder_v3_runtime');

        if (! is_array($runtime) || (int) ($runtime['schema_version'] ?? 0) !== BuildScenarioBuilderV3StateAction::SCHEMA_VERSION) {
            return null;
        }

        return $runtime;
    }

    private function dispatchScenarioMessage(
        Message $message,
        string $text,
        string $textFormat,
        bool $requestPhone = false,
        bool $removeTelegramKeyboard = false,
        ?array $replyButtonRows = null,
        string $buttonPlacement = self::V3_BUTTON_PLACEMENT_AUTO,
        ?string $v3CallbackBlockId = null,
    ): bool {
        $channel = $message->channel;

        if (! $channel instanceof Channel) {
            throw new RuntimeException("Scenario [{$this->code()}] message does not have an active channel.");
        }

        $content = $this->prepareMessageContentAction->handle($text, $textFormat);

        $sendResult = $this->sendBotDialogTextAction->handleMessage(
            $message,
            $content->transportText,
            telegramReplyMarkup: $this->telegramReplyMarkup($requestPhone, $removeTelegramKeyboard, $replyButtonRows, $buttonPlacement, $v3CallbackBlockId),
            maxAttachments: $this->maxAttachments($requestPhone, $replyButtonRows, $buttonPlacement),
            textFormat: $content->textFormat,
        );

        if (! $sendResult->wasSent() || $sendResult->deliveryResult === null) {
            return false;
        }

        $this->storeOutboundScenarioMessageAction->handle(
            channel: $channel,
            inboundMessage: $message,
            deliveryResult: $sendResult->deliveryResult,
            systemCode: $this->systemCode(),
            routeDialog: $sendResult->dialog,
            content: $content,
        );

        $channel->markReplySent();

        return true;
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
        } catch (ValidationException) {
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
     * @param  array<string, mixed>  $statePayload
     * @return array{
     *     status: string,
     *     current_step: string,
     *     state_payload: array<string, mixed>,
     *     exit_outcome: null,
     * }
     */
    private function activeProgress(string $currentStep, array $statePayload): array
    {
        return [
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => $currentStep,
            'state_payload' => $statePayload,
            'exit_outcome' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function markPendingPromptDelivery(array $statePayload, bool $removeTelegramKeyboard = false): array
    {
        data_set($statePayload, self::PENDING_PROMPT_DELIVERY_STATE_KEY, true);
        data_set($statePayload, self::PENDING_PROMPT_REMOVE_TELEGRAM_KEYBOARD_STATE_KEY, $removeTelegramKeyboard);

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function clearPendingPromptDelivery(array $statePayload): array
    {
        data_forget($statePayload, self::PENDING_PROMPT_DELIVERY_STATE_KEY);
        data_forget($statePayload, self::PENDING_PROMPT_REMOVE_TELEGRAM_KEYBOARD_STATE_KEY);

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function hasPendingPromptDelivery(array $statePayload): bool
    {
        return (bool) data_get($statePayload, self::PENDING_PROMPT_DELIVERY_STATE_KEY, false);
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function pendingPromptRemoveTelegramKeyboard(array $statePayload): bool
    {
        return (bool) data_get($statePayload, self::PENDING_PROMPT_REMOVE_TELEGRAM_KEYBOARD_STATE_KEY, false);
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @param  array<string, mixed>  $block
     * @param  array{
     *     version: int,
     *     start_block_id: string,
     *     triggers: list<array{type: 'parameter', value: string}>,
     *     blocks: array<string, array<string, mixed>>,
     * }  $schema
     */
    private function resumePendingPromptDelivery(
        Message $message,
        array $schema,
        array $block,
        string $currentStep,
        array $statePayload,
    ): ScenarioInboundResult {
        $removeTelegramKeyboard = $this->pendingPromptRemoveTelegramKeyboard($statePayload);

        $progress = match ($block['type'] ?? null) {
            'message' => $this->advanceAfterMessageBlock(
                $message,
                $schema,
                $currentStep,
                $block,
                $statePayload,
                count($schema['blocks']) + 1,
                $removeTelegramKeyboard,
            ),
            'question' => $this->enterQuestionBlock(
                $message,
                $currentStep,
                $block,
                $statePayload,
                $removeTelegramKeyboard,
            ),
            'phone_capture' => $this->enterPhoneCaptureBlock(
                $message,
                $currentStep,
                $block,
                $statePayload,
            ),
            default => $this->activeProgress($currentStep, $statePayload),
        };

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
    private function telegramReplyMarkup(
        bool $requestPhone,
        bool $removeTelegramKeyboard,
        ?array $replyButtonRows = null,
        string $buttonPlacement = self::V3_BUTTON_PLACEMENT_AUTO,
        ?string $v3CallbackBlockId = null,
    ): ?array {
        if ($requestPhone) {
            return $this->telegramPhoneCaptureReplyMarkup();
        }

        if ($removeTelegramKeyboard) {
            return [
                'remove_keyboard' => true,
            ];
        }

        if ($replyButtonRows !== null && $replyButtonRows !== []) {
            $hasLinkButton = collect($replyButtonRows)
                ->flatten(1)
                ->contains(fn (mixed $button): bool => is_array($button)
                    && ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) === self::V3_BUTTON_TYPE_LINK
                    && filled($button['url'] ?? null));

            if (
                $buttonPlacement === self::V3_BUTTON_PLACEMENT_INLINE_MESSAGE
                || ($buttonPlacement === self::V3_BUTTON_PLACEMENT_AUTO && $hasLinkButton)
            ) {
                $inlineKeyboard = collect($replyButtonRows)
                    ->map(fn (array $row): array => collect($row)
                        ->map(function (array $button) use ($v3CallbackBlockId): ?array {
                            if (! filled($button['text'] ?? null)) {
                                return null;
                            }

                            if (($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) === self::V3_BUTTON_TYPE_LINK) {
                                return filled($button['url'] ?? null)
                                    ? [
                                        'text' => (string) $button['text'],
                                        'url' => (string) $button['url'],
                                    ]
                                    : null;
                            }

                            if (($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) !== self::V3_BUTTON_TYPE_TEXT || ! filled($button['output_id'] ?? null)) {
                                return null;
                            }

                            $callbackData = $this->v3TelegramButtonCallbackData(
                                (string) $v3CallbackBlockId,
                                (string) $button['output_id'],
                            );

                            return $callbackData !== null
                                ? [
                                    'text' => (string) $button['text'],
                                    'callback_data' => $callbackData,
                                ]
                                : null;
                        })
                        ->filter()
                        ->values()
                        ->all())
                    ->filter(fn (array $row): bool => $row !== [])
                    ->values()
                    ->all();

                return $inlineKeyboard !== [] ? ['inline_keyboard' => $inlineKeyboard] : null;
            }

            $keyboard = collect($replyButtonRows)
                ->map(fn (array $row): array => collect($row)
                    ->filter(fn (array $button): bool => filled($button['text'] ?? null)
                        && ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) !== self::V3_BUTTON_TYPE_LINK)
                    ->map(fn (array $button): array => array_filter([
                        'text' => (string) $button['text'],
                        'request_contact' => ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) === self::V3_BUTTON_TYPE_REQUEST_PHONE
                            ? true
                            : null,
                    ], static fn (mixed $value): bool => $value !== null))
                    ->values()
                    ->all())
                ->filter(fn (array $row): bool => $row !== [])
                ->values()
                ->all();

            if ($keyboard !== []) {
                return [
                    'keyboard' => $keyboard,
                    'resize_keyboard' => true,
                    'one_time_keyboard' => false,
                ];
            }
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

    /**
     * @param  list<list<array<string, mixed>>>|null  $replyButtonRows
     * @return array<int, array<string, mixed>>|null
     */
    private function maxAttachments(
        bool $requestPhone,
        ?array $replyButtonRows,
        string $buttonPlacement = self::V3_BUTTON_PLACEMENT_AUTO,
    ): ?array {
        if ($requestPhone) {
            return $this->maxPhoneCaptureAttachments();
        }

        if ($buttonPlacement === self::V3_BUTTON_PLACEMENT_REPLY_KEYBOARD) {
            return null;
        }

        if ($replyButtonRows === null || $replyButtonRows === []) {
            return null;
        }

        $buttons = collect($replyButtonRows)
            ->map(fn (array $row): array => collect($row)
                ->filter(fn (array $button): bool => filled($button['text'] ?? null))
                ->map(fn (array $button): array => array_filter([
                    'type' => match ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) {
                        self::V3_BUTTON_TYPE_REQUEST_PHONE => 'request_contact',
                        self::V3_BUTTON_TYPE_LINK => 'link',
                        default => 'message',
                    },
                    'text' => (string) $button['text'],
                    'url' => ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) === self::V3_BUTTON_TYPE_LINK
                        ? (string) ($button['url'] ?? '')
                        : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''))
                ->values()
                ->all())
            ->filter(fn (array $row): bool => $row !== [])
            ->values()
            ->all();

        if ($buttons === []) {
            return null;
        }

        return [[
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => $buttons,
            ],
        ]];
    }

    private function systemCode(): string
    {
        return 'scenario_'.$this->code();
    }
}
