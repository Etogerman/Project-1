<?php

namespace App\Services\Scenarios;

use App\Data\AI\AiGenerationContext;
use App\Data\AI\AiStructuredGenerationResult;
use App\Data\Bitrix24\Bitrix24ContactSyncQueueResultData;
use App\Data\Bitrix24\Bitrix24DealSyncQueueResultData;
use App\Data\Bitrix24\Bitrix24HistoryExportQueueResultData;
use App\Data\Contacts\ContactDataCollectionCompletionResult;
use App\Data\Contacts\FirstNameResolutionWriteContext;
use App\Data\Scenarios\ScenarioInboundResult;
use App\Jobs\InferContactGenderFromFirstNameJob;
use App\Jobs\ProcessScenarioV3AiAnalysisJob;
use App\Jobs\ProcessScenarioV3OutboundMessageJob;
use App\Jobs\ProcessScenarioV3ScheduledTransitionJob;
use App\Jobs\RetryScenarioV3AiAnalysisJob;
use App\Models\AiRequest;
use App\Models\AiTask;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactEmail;
use App\Models\ContactFirstNameResolutionEvent;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\DataDictionaryEntry;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioBuilderCondition;
use App\Models\ScenarioBuilderEdge;
use App\Models\ScenarioRun;
use App\Models\ScenarioV3OutboundMessage;
use App\Models\ScenarioV3ScheduledTransition;
use App\Models\ScenarioVersion;
use App\Models\Tag;
use App\Services\AI\AiRequestAnalyticsService;
use App\Services\AI\AiStructuredGenerationException;
use App\Services\AI\AiStructuredGenerationService;
use App\Services\Analytics\FirstNameResolutionAnalyticsService;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use App\Services\Bitrix24\QueueBitrix24DealSyncAction;
use App\Services\Bitrix24\QueueBitrix24HistoryExportAction;
use App\Services\Bots\SendBotDialogTextAction;
use App\Services\Bots\StoreOutboundScenarioMessageAction;
use App\Services\Bots\TelegramBotApiService;
use App\Services\Contacts\AddContactPhoneAction;
use App\Services\Contacts\ApplyContactFirstNameAction;
use App\Services\Contacts\CompleteContactDataCollectionIfReadyAction;
use App\Services\Contacts\NormalizePhoneNumberAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Contacts\SyncContactDistanceToMoscowAction;
use App\Services\DataCollection\ExtractFirstNameAction;
use App\Services\Dialogs\DeleteLastOutboundDialogMessageAction;
use App\Services\Geo\ApplyGeoResolutionToContactAction;
use App\Services\Geo\ResolveAndApplyGeoCityAction;
use App\Services\Geo\ResolveGeoCityAction;
use App\Services\Messages\PrepareMessageContentAction;
use App\Services\TelegramAccount\QueueTelegramAccountSystemReplyAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class GenericDbScenarioRuntime implements PrioritizedScenarioRuntime, ResolvedScenarioRuntime
{
    private const IBIZA_SCENARIO_CODE = 'vip_ibiza';

    private const IBIZA_FIRST_NAME_STATE_KEY = 'run.first_name';

    private const PHONE_CAPTURE_BUTTON_TEXT = 'Поделиться номером телефона';

    private const V3_MATCH_EXACT_CALLBACK = 'exact_callback';

    private const V3_START_PRIORITY_MIN = 1;

    private const V3_START_PRIORITY_MAX = 100;

    private const V3_BUTTON_TYPE_TEXT = 'text';

    private const V3_BUTTON_TYPE_REQUEST_PHONE = 'request_phone';

    private const V3_BUTTON_TYPE_LINK = 'link';

    private const V3_BUTTON_PLACEMENT_AUTO = 'auto';

    private const V3_BUTTON_PLACEMENT_REPLY_KEYBOARD = 'reply_keyboard';

    private const V3_BUTTON_PLACEMENT_INLINE_MESSAGE = 'inline_message';

    private const V3_AI_MESSAGE_BUNDLE_LIMIT = 10;

    private const V3_AI_FAILED_OUTPUT_ID = 'ai_failed';

    private const V3_AI_RETRY_MAX_CYCLES = 4;

    private const V3_AI_RETRY_BACKOFF_SECONDS = [
        2 => 10,
        3 => 30,
        4 => 60,
    ];

    private const V3_TELEGRAM_BUTTON_CALLBACK_PREFIX = 'v3b:';

    private const V3_TELEGRAM_BUTTON_CALLBACK_MAX_BYTES = 64;

    private const PENDING_PROMPT_DELIVERY_STATE_KEY = 'run.pending_prompt_delivery';

    private const PENDING_PROMPT_REMOVE_TELEGRAM_KEYBOARD_STATE_KEY = 'run.pending_prompt_remove_telegram_keyboard';

    private const V3_PENDING_REMOVE_TELEGRAM_KEYBOARD_STATE_KEY = 'v3.pending_remove_telegram_keyboard';

    private const V3_DIALOG_FIELDS_MAX_BYTES = 65536;

    private const V3_DIALOG_USER_FIELDS_MAX = 50;

    private const V3_DIALOG_FIELD_VALUE_MAX_LENGTH = 2000;

    private const V3_OUTBOUND_MAX_ATTEMPTS = 5;

    private const V3_OUTBOUND_PROCESSING_TIMEOUT_SECONDS = 600;

    private const V3_SCHEDULED_TRANSITION_PROCESSING_TIMEOUT_SECONDS = 600;

    private const V3_OUTBOUND_RETRY_BACKOFF_SECONDS = [10, 30, 60, 180];

    private const V3_BITRIX24_SYNC_OPERATIONS = [
        'contact_sync',
        'deal_sync',
        'history_export',
        'contact_sync_with_followups',
    ];

    private const TELEGRAM_REPLY_KEYBOARD_CLEANUP_TEXT = "\u{2060}";

    private const V3_DISTANCE_TO_MOSCOW_OUTPUT_RESOLVED = 'distance_resolved';

    private const V3_DISTANCE_TO_MOSCOW_OUTPUT_PENDING = 'distance_pending';

    private const V3_DISTANCE_TO_MOSCOW_OUTPUT_UNKNOWN = 'distance_unknown';

    private const V3_DISTANCE_TO_MOSCOW_OUTPUT_OUT_OF_SCOPE = 'distance_out_of_scope';

    private const V3_DISTANCE_TO_MOSCOW_OUTPUT_FAILED = 'distance_failed';

    private const V3_GEO_CITY_OUTPUT_FOUND = 'geo_found';

    private const V3_GEO_CITY_OUTPUT_MANUAL_REQUIRED = 'geo_manual_required';

    private const V3_GEO_CITY_OUTPUT_AMBIGUOUS = 'geo_ambiguous';

    private const V3_GEO_CITY_OUTPUT_NOT_FOUND = 'geo_not_found';

    private const V3_GEO_CITY_OUTPUT_BELOW_THRESHOLD = 'geo_below_threshold';

    private const V3_GEO_CITY_OUTPUT_INACTIVE = 'geo_inactive';

    private const V3_GEO_CITY_OUTPUT_FAILED = 'geo_failed';

    private const V3_GEO_CITY_LEGACY_OUTPUTS_BY_STATUS = [
        ResolveGeoCityAction::STATUS_MANUAL_REQUIRED => self::V3_GEO_CITY_OUTPUT_MANUAL_REQUIRED,
        ResolveGeoCityAction::STATUS_AMBIGUOUS => self::V3_GEO_CITY_OUTPUT_AMBIGUOUS,
        ResolveGeoCityAction::STATUS_BELOW_THRESHOLD => self::V3_GEO_CITY_OUTPUT_BELOW_THRESHOLD,
        ResolveGeoCityAction::STATUS_INACTIVE => self::V3_GEO_CITY_OUTPUT_INACTIVE,
        ResolveGeoCityAction::STATUS_FAILED => self::V3_GEO_CITY_OUTPUT_FAILED,
    ];

    private const V3_GEO_CITY_MANUAL_REQUIRED_STATUSES = [
        ResolveGeoCityAction::STATUS_MANUAL_REQUIRED,
        ResolveGeoCityAction::STATUS_AMBIGUOUS,
        ResolveGeoCityAction::STATUS_BELOW_THRESHOLD,
        ResolveGeoCityAction::STATUS_INACTIVE,
    ];

    private ?int $matchedBuilderStartMessageId = null;

    private ?string $matchedBuilderRuntimeBlockId = null;

    private ?int $matchedV3StartMessageId = null;

    private ?string $matchedV3RuntimeBlockId = null;

    private int $v3SimulateStartDepth = 0;

    /**
     * @var array<string, array<int, list<int>>>
     */
    private array $v3RootContactTagIdsByMessage = [];

    private int $v3ScenarioMessageDeferralDepth = 0;

    /**
     * @var list<int>
     */
    private array $deferredV3ScenarioOutboundMessageIds = [];

    private ?int $activeV3ScheduledTransitionId = null;

    public function __construct(
        private readonly Scenario $scenario,
        private readonly ScenarioVersion $publishedVersion,
        private readonly ValidateScenarioSchemaPayloadAction $validateScenarioSchemaPayloadAction,
        private readonly ScenarioConditionEvaluator $scenarioConditionEvaluator,
        private readonly ApplyScenarioTagEffectsAction $applyScenarioTagEffectsAction,
        private readonly StoreOutboundScenarioMessageAction $storeOutboundScenarioMessageAction,
        private readonly SendBotDialogTextAction $sendBotDialogTextAction,
        private readonly QueueTelegramAccountSystemReplyAction $queueTelegramAccountSystemReplyAction,
        private readonly TelegramBotApiService $telegramBotApiService,
        private readonly DeleteLastOutboundDialogMessageAction $deleteLastOutboundDialogMessageAction,
        private readonly PrepareMessageContentAction $prepareMessageContentAction,
        private readonly AiStructuredGenerationService $aiStructuredGenerationService,
        private readonly AiRequestAnalyticsService $aiRequestAnalyticsService,
        private readonly FirstNameResolutionAnalyticsService $firstNameResolutionAnalyticsService,
        private readonly ExtractFirstNameAction $extractFirstNameAction,
        private readonly ApplyContactFirstNameAction $applyContactFirstNameAction,
        private readonly AddContactPhoneAction $addContactPhoneAction,
        private readonly CompleteContactDataCollectionIfReadyAction $completeContactDataCollectionIfReadyAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
        private readonly QueueBitrix24DealSyncAction $queueBitrix24DealSyncAction,
        private readonly QueueBitrix24HistoryExportAction $queueBitrix24HistoryExportAction,
        private readonly SyncContactDistanceToMoscowAction $syncContactDistanceToMoscowAction,
        private readonly ResolveAndApplyGeoCityAction $resolveAndApplyGeoCityAction,
        private readonly ResolveGeoCityAction $resolveGeoCityAction,
        private readonly ApplyGeoResolutionToContactAction $applyGeoResolutionToContactAction,
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

        $this->withDeferredV3ScenarioMessages(function () use ($message, $runtime, $blockId, $activeRun): void {
            DB::transaction(function () use ($message, $runtime, $blockId, $activeRun): void {
                $lockedRun = ScenarioRun::query()
                    ->whereKey($activeRun->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedRun instanceof ScenarioRun || ! $lockedRun->isActive()) {
                    return;
                }

                $this->advanceV3FromBlock(
                    $message,
                    $runtime,
                    $blockId,
                    $this->v3StatePayload($lockedRun->state_payload),
                    run: $lockedRun,
                    preservePreviousStateForTerminalNonState: true,
                );
            });
        });

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

    public function handleDelayedV3AiAnalysis(
        int $scenarioRunId,
        int $dialogId,
        int $inboundMessageId,
        string $blockId,
        string $token,
    ): void {
        $this->withDeferredV3ScenarioMessages(function () use ($scenarioRunId, $dialogId, $inboundMessageId, $blockId, $token): void {
            DB::transaction(function () use ($scenarioRunId, $dialogId, $inboundMessageId, $blockId, $token): void {
                $dialog = Dialog::query()
                    ->whereKey($dialogId)
                    ->lockForUpdate()
                    ->first();
                $run = ScenarioRun::query()
                    ->whereKey($scenarioRunId)
                    ->lockForUpdate()
                    ->first();
                $message = Message::query()
                    ->with(['channel', 'contact', 'contactIdentity', 'dialog'])
                    ->find($inboundMessageId);

                if (
                    ! $dialog instanceof Dialog
                    || ! $run instanceof ScenarioRun
                    || ! $run->isActive()
                    || ! $message instanceof Message
                    || (int) $run->dialog_id !== $dialogId
                ) {
                    return;
                }

                $runtime = $this->v3RuntimeOrNull();

                if ($runtime === null) {
                    return;
                }

                $statePayload = $this->v3StatePayload($run->state_payload);
                $currentBlockId = $this->v3RuntimeBlockId(
                    $runtime,
                    trim((string) data_get($statePayload, 'v3.current_block_id', '')),
                    $statePayload,
                );

                if (
                    $currentBlockId !== $blockId
                    || (string) data_get($statePayload, "v3.ai_analysis_pending.$blockId.token", '') !== $token
                ) {
                    return;
                }

                $pendingOutputId = (string) data_get($statePayload, "v3.ai_analysis_pending.$blockId.output_id", '');
                $pendingDelaySeconds = max(0, (int) data_get($statePayload, "v3.ai_analysis_pending.$blockId.delay_seconds", 0));

                if (
                    $pendingOutputId !== ''
                    && ! $this->v3DelayedAiAnalysisWasAlreadyScheduled($statePayload, $blockId, (int) $message->id, $pendingOutputId)
                ) {
                    $pendingScheduledFor = CarbonImmutable::now();

                    try {
                        $pendingScheduledForRaw = data_get($statePayload, "v3.ai_analysis_pending.$blockId.scheduled_for");
                        $pendingScheduledFor = is_string($pendingScheduledForRaw) && $pendingScheduledForRaw !== ''
                            ? CarbonImmutable::parse($pendingScheduledForRaw)
                            : $pendingScheduledFor;
                    } catch (Throwable) {
                        $pendingScheduledFor = CarbonImmutable::now();
                    }

                    $statePayload = $this->rememberV3DelayedAiAnalysisSchedule(
                        $statePayload,
                        $blockId,
                        (int) $message->id,
                        $pendingOutputId,
                        $pendingDelaySeconds,
                        $pendingScheduledFor,
                    );
                }

                $progress = $this->advanceV3FromBlock(
                    $message,
                    $runtime,
                    $blockId,
                    $this->clearV3AiAnalysisPending($statePayload, $blockId),
                    run: $run,
                    suppressMessage: true,
                    allowDelayedAiOutputs: false,
                );

                $run->forceFill([
                    'status' => $progress['status'],
                    'current_step' => $progress['current_step'],
                    'state_payload' => $progress['state_payload'],
                    'exit_outcome' => $progress['exit_outcome'],
                    'finished_at' => $progress['status'] === ScenarioRun::STATUS_ACTIVE ? null : now(),
                ])->save();
            });
        });
    }

    public function handleRetryV3AiAnalysis(
        int $scenarioRunId,
        int $dialogId,
        int $inboundMessageId,
        int $publishedVersionId,
        string $blockId,
        string $token,
        int $cycle,
    ): void {
        $this->withDeferredV3ScenarioMessages(function () use ($scenarioRunId, $dialogId, $inboundMessageId, $publishedVersionId, $blockId, $token, $cycle): void {
            DB::transaction(function () use ($scenarioRunId, $dialogId, $inboundMessageId, $publishedVersionId, $blockId, $token, $cycle): void {
                if ((int) $this->publishedVersion->id !== $publishedVersionId) {
                    return;
                }

                $dialog = Dialog::query()
                    ->whereKey($dialogId)
                    ->lockForUpdate()
                    ->first();
                $run = ScenarioRun::query()
                    ->whereKey($scenarioRunId)
                    ->lockForUpdate()
                    ->first();
                $message = Message::query()
                    ->with(['channel', 'contact', 'contactIdentity', 'dialog'])
                    ->find($inboundMessageId);

                if (
                    ! $dialog instanceof Dialog
                    || ! $run instanceof ScenarioRun
                    || ! $run->isActive()
                    || ! $message instanceof Message
                    || (int) $run->dialog_id !== $dialogId
                ) {
                    return;
                }

                $runtime = $this->v3RuntimeOrNull();

                if ($runtime === null) {
                    return;
                }

                $statePayload = $this->v3StatePayload($run->state_payload);
                $currentBlockId = $this->v3RuntimeBlockId(
                    $runtime,
                    trim((string) data_get($statePayload, 'v3.current_block_id', '')),
                    $statePayload,
                );

                if (
                    $currentBlockId !== $blockId
                    || (string) data_get($statePayload, "v3.ai_analysis_retry.$blockId.token", '') !== $token
                    || (int) data_get($statePayload, "v3.ai_analysis_retry.$blockId.cycle", 0) !== $cycle
                ) {
                    return;
                }

                if ($this->v3HasNewerInboundMessage($message)) {
                    $statePayload = $this->cancelV3AiAnalysisRetry($statePayload, $blockId, 'newer_inbound_message_exists');
                    $run->forceFill(['state_payload' => $statePayload])->save();

                    return;
                }

                $progress = $this->advanceV3FromBlock(
                    $message,
                    $runtime,
                    $blockId,
                    $statePayload,
                    run: $run,
                    suppressMessage: true,
                );

                $run->forceFill([
                    'status' => $progress['status'],
                    'current_step' => $progress['current_step'],
                    'state_payload' => $progress['state_payload'],
                    'exit_outcome' => $progress['exit_outcome'],
                    'finished_at' => $progress['status'] === ScenarioRun::STATUS_ACTIVE ? null : now(),
                ])->save();
            });
        });
    }

    public function shouldStart(Message $message): bool
    {
        $this->matchedBuilderStartMessageId = null;
        $this->matchedBuilderRuntimeBlockId = null;
        $this->matchedV3StartMessageId = null;
        $this->matchedV3RuntimeBlockId = null;
        $this->v3SimulateStartDepth = 0;

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
                || $this->v3AutomaticEdges($block) !== []
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

        return $this->v3TargetForButtonCallback($callbackData, $block, $channel) !== null
            || $this->v3WaitReplyTargetForButtonCallback($callbackData, $block, $channel) !== null;
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
                is_string($extraction['resolution_method'] ?? null) ? $extraction['resolution_method'] : null,
            );

            if (! $result->changed) {
                return;
            }

            if ($result->bitrix24RelevantChanged) {
                $this->queueBitrix24ContactSyncAction->handle($contact);
            }

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
        $this->withDeferredV3ScenarioMessages(function () use ($run, $message, $runtime): void {
            DB::transaction(function () use ($run, $message, $runtime): void {
                $lockedRun = ScenarioRun::query()
                    ->whereKey($run->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $startBlockId = $this->v3StartBlockIdForMessage($message, $runtime);
                $statePayload = $this->v3StatePayload($lockedRun->state_payload);
                data_set($statePayload, 'v3.entrypoint.parameter', $this->v3StartParameterFromMessage($message));

                $progress = $this->advanceV3FromBlock(
                    $message,
                    $runtime,
                    $startBlockId,
                    $statePayload,
                    run: $lockedRun,
                );

                $lockedRun->forceFill([
                    'status' => $progress['status'],
                    'current_step' => $progress['current_step'],
                    'state_payload' => $progress['state_payload'],
                    'exit_outcome' => $progress['exit_outcome'],
                    'finished_at' => $progress['status'] === ScenarioRun::STATUS_ACTIVE ? null : now(),
                ])->save();
            });
        });
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

        if (is_array($block) && $this->v3BlockHasAiAnalysis($block)) {
            return $this->handleV3AiAnalysisInbound($run, $message, $runtime, $currentBlockId);
        }

        $transition = $this->v3TransitionForMessage($message, is_array($block) ? $block : [], $message->dialog);

        if (
            $transition !== null
            || $this->v3AutomaticEdges(is_array($block) ? $block : []) !== []
        ) {
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
    private function handleV3AiAnalysisInbound(
        ScenarioRun $run,
        Message $message,
        array $runtime,
        string $currentBlockId,
    ): ScenarioInboundResult {
        if ($message->dialog_id === null) {
            return new ScenarioInboundResult(
                consumed: false,
                status: $run->status,
                currentStep: $run->current_step,
                statePayload: $this->v3StatePayload($run->state_payload),
                exitOutcome: $run->exit_outcome,
            );
        }

        return $this->withDeferredV3ScenarioMessages(function () use ($run, $message, $runtime, $currentBlockId): ScenarioInboundResult {
            return DB::transaction(function () use ($run, $message, $runtime, $currentBlockId): ScenarioInboundResult {
                $lockedRun = ScenarioRun::query()
                    ->whereKey($run->id)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $lockedRun instanceof ScenarioRun
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
                $resolvedBlockId = $this->v3RuntimeBlockId(
                    $runtime,
                    trim((string) data_get($statePayload, 'v3.current_block_id', $currentBlockId)),
                    $statePayload,
                );

                if ($resolvedBlockId !== $currentBlockId) {
                    return new ScenarioInboundResult(
                        consumed: true,
                        status: ScenarioRun::STATUS_ACTIVE,
                        currentStep: $currentBlockId,
                        statePayload: $statePayload,
                        exitOutcome: null,
                    );
                }

                $statePayload = $this->cancelV3AiAnalysisRetry($statePayload, $currentBlockId, 'new_inbound_message');

                $progress = $this->advanceV3FromBlock(
                    $message,
                    $runtime,
                    $currentBlockId,
                    $statePayload,
                    run: $lockedRun,
                    suppressMessage: true,
                );

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
        });
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

        return $this->withDeferredV3ScenarioMessages(function () use ($run, $message, $runtime): ScenarioInboundResult {
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
                $transitions = $this->v3TransitionCandidatesForMessage($message, is_array($block) ? $block : [], $dialog);

                if ($transitions === []) {
                    if ($this->v3AutomaticEdges(is_array($block) ? $block : []) !== []) {
                        if ($this->v3ShouldRemoveTelegramReplyKeyboardAfterInbound($message, is_array($block) ? $block : [])) {
                            $statePayload = $this->markV3PendingTelegramKeyboardRemoval($statePayload);
                        }

                        $automaticProgress = $this->advanceV3AutomaticEdges(
                            $message,
                            $runtime,
                            is_array($block) ? $block : [],
                            $statePayload,
                            count(is_array($runtime['blocks'] ?? null) ? $runtime['blocks'] : []) + 1,
                            $lockedRun,
                        );

                        if ($automaticProgress !== null) {
                            $lockedRun->forceFill([
                                'status' => $automaticProgress['status'],
                                'current_step' => $automaticProgress['current_step'],
                                'state_payload' => $automaticProgress['state_payload'],
                                'exit_outcome' => $automaticProgress['exit_outcome'],
                                'finished_at' => $automaticProgress['status'] === ScenarioRun::STATUS_ACTIVE ? null : now(),
                            ])->save();

                            return new ScenarioInboundResult(
                                consumed: true,
                                status: $automaticProgress['status'],
                                currentStep: $automaticProgress['current_step'],
                                statePayload: $automaticProgress['state_payload'],
                                exitOutcome: $automaticProgress['exit_outcome'],
                                persisted: true,
                            );
                        }
                    }

                    return new ScenarioInboundResult(
                        consumed: true,
                        status: ScenarioRun::STATUS_ACTIVE,
                        currentStep: $currentBlockId,
                        statePayload: $this->markV3Waiting($statePayload, $currentBlockId, is_array($block) ? $block : [], $message->channel),
                        exitOutcome: null,
                    );
                }

                $transition = null;

                foreach ($transitions as $candidate) {
                    if ($this->applyV3TransitionSideEffectsToDialog($dialog, $message, $candidate['edge'], $candidate['captured_value'])) {
                        $transition = $candidate;

                        break;
                    }
                }

                if ($transition === null) {
                    return new ScenarioInboundResult(
                        consumed: true,
                        status: ScenarioRun::STATUS_ACTIVE,
                        currentStep: $currentBlockId,
                        statePayload: $this->markV3Waiting($statePayload, $currentBlockId, $block, $message->channel),
                        exitOutcome: null,
                    );
                }

                $transitionEdge = $transition['edge'];
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

                if ($this->v3ShouldRemoveTelegramReplyKeyboardAfterInbound($message, $block)) {
                    $statePayload = $this->markV3PendingTelegramKeyboardRemoval($statePayload);
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
        });
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{edge: array<string, mixed>, captured_value: array{valid: bool, value: string|null, phone_raw?: string|null, phone_normalized?: string|null}}|null
     */
    private function v3TransitionForMessage(Message $message, array $block, ?Dialog $dialog): ?array
    {
        return $this->v3TransitionCandidatesForMessage($message, $block, $dialog)[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array{edge: array<string, mixed>, captured_value: array{valid: bool, value: string|null, phone_raw?: string|null, phone_normalized?: string|null}}>
     */
    private function v3TransitionCandidatesForMessage(Message $message, array $block, ?Dialog $dialog): array
    {
        $edges = collect($this->v3TransitionEdgesForMessage($message, $block))
            ->sort(fn (array $left, array $right): int => $this->compareV3TransitionEdges($left, $right))
            ->values();
        $candidates = [];

        foreach ($edges as $edge) {
            if ($this->v3TransitionLimitReached($dialog, $edge)) {
                continue;
            }

            $capturedValue = $this->v3CapturedValueForEdge($message, $edge, $block);

            if ($capturedValue['valid'] !== true) {
                continue;
            }

            $candidates[] = [
                'edge' => $edge,
                'captured_value' => $capturedValue,
            ];
        }

        return $candidates;
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
                && $this->v3EdgeAllowsDialogPhone($message, $edge)
                && $this->v3EdgeAllowsFieldCondition($message, $edge)
                && $this->v3EdgeAllowsTagCondition($message, $edge)
                && (
                    ($edge['mode'] ?? null) !== 'wait_reply'
                    || $this->messageMatchesV3WaitReplyEdge($message, $edge, $block)
                )
                && $this->v3EdgeAllowsExpression($message, $edge))
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
    private function messageMatchesV3WaitReplyEdge(Message $message, array $edge, ?array $block = null): bool
    {
        $match = is_array($edge['match'] ?? null) ? $edge['match'] : [];
        $type = (string) ($match['type'] ?? 'any_inbound');

        if ($type === 'any_inbound') {
            return true;
        }

        $callbackAnswerText = $this->v3SelectedButtonTextForCallbackMessage($message, $block);
        $messageText = $this->normalizeV3ButtonText((string) ($callbackAnswerText ?? $message->text));
        $messageParameter = $this->normalizeV3ButtonText((string) $message->message_parameter);
        $variants = collect($match['variants'] ?? [])
            ->map(fn (mixed $variant): string => $this->normalizeV3ButtonText((string) $variant))
            ->filter(fn (string $variant): bool => $variant !== '')
            ->values();

        if ($variants->isEmpty()) {
            return false;
        }

        if (
            $this->messageIsV3Callback($message)
            && $type !== self::V3_MATCH_EXACT_CALLBACK
            && $callbackAnswerText === null
        ) {
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
    private function v3EdgeAllowsDialogPhone(Message $message, array $edge): bool
    {
        $condition = trim((string) ($edge['dialog_phone_condition'] ?? ''));

        return $this->v3PhoneConditionAllows($condition, $this->messageDialogHasConfirmedPhone($message));
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

    /**
     * @param  array<string, mixed>  $edge
     */
    private function v3EdgeAllowsTagCondition(Message $message, array $edge): bool
    {
        $condition = is_array($edge['tag_condition'] ?? null) ? $edge['tag_condition'] : [];

        return $this->v3AllowsTagCondition($message, $condition);
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function v3AllowsTagCondition(Message $message, array $condition): bool
    {
        if ((bool) ($condition['enabled'] ?? false) !== true) {
            return true;
        }

        $tagIds = $this->v3TagEffectIds($condition['tag_ids'] ?? []);

        if ($tagIds === [] || ! $message->contact instanceof Contact) {
            return false;
        }

        $contactTagIds = $this->v3RootContactTagIds($message);
        $mode = (string) ($condition['mode'] ?? 'has_all');

        return match ($mode) {
            'has_any' => array_intersect($tagIds, $contactTagIds) !== [],
            'has_none' => array_intersect($tagIds, $contactTagIds) === [],
            default => count(array_intersect($tagIds, $contactTagIds)) === count($tagIds),
        };
    }

    /**
     * @return list<int>
     */
    private function v3RootContactTagIds(Message $message): array
    {
        if (! $message->contact instanceof Contact) {
            return [];
        }

        $contact = $this->resolveRootContactAction->handle($message->contact);
        $messageKey = $this->v3MessageCacheKey($message);
        $contactId = (int) $contact->id;

        if (! array_key_exists($messageKey, $this->v3RootContactTagIdsByMessage)) {
            $this->v3RootContactTagIdsByMessage[$messageKey] = [];
        }

        if (! array_key_exists($contactId, $this->v3RootContactTagIdsByMessage[$messageKey])) {
            $this->v3RootContactTagIdsByMessage[$messageKey][$contactId] = $contact->tags()
                ->pluck('tags.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        }

        return $this->v3RootContactTagIdsByMessage[$messageKey][$contactId];
    }

    private function forgetV3RootContactTagIds(Message $message, Contact $contact): void
    {
        $messageKey = $this->v3MessageCacheKey($message);
        $contactId = (int) $contact->id;

        unset($this->v3RootContactTagIdsByMessage[$messageKey][$contactId]);

        if (($this->v3RootContactTagIdsByMessage[$messageKey] ?? []) === []) {
            unset($this->v3RootContactTagIdsByMessage[$messageKey]);
        }
    }

    private function v3MessageCacheKey(Message $message): string
    {
        return (string) ($message->id ?: 'new').':'.spl_object_id($message);
    }

    private function v3FieldConditionValue(Message $message, string $fieldScope, string $fieldKey): mixed
    {
        if ($fieldScope === 'dialog') {
            return $this->v3DialogReadableFieldValue($message, $fieldKey);
        }

        if (! $message->contact instanceof Contact || ! in_array($fieldKey, EngineFieldRegistry::CONTACT_FIELD_CONDITION_FIELDS, true)) {
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

        if ($fieldKey === 'emails') {
            return $contact->emails()
                ->get(['email_normalized', 'email_raw'])
                ->flatMap(fn (ContactEmail $email): array => [
                    $email->email_normalized,
                    $email->email_raw,
                ])
                ->filter(fn (mixed $value): bool => trim((string) $value) !== '')
                ->values()
                ->all();
        }

        $fieldKey = EngineFieldRegistry::resolveReadAlias(EngineFieldRegistry::ENTITY_CONTACT, $fieldKey);

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
     * @param  array<string, mixed>  $edge
     */
    private function v3EdgeAllowsExpression(Message $message, array $edge): bool
    {
        $expression = trim((string) ($edge['expression'] ?? ''));

        if ($expression === '') {
            return true;
        }

        try {
            return app(ScenarioEdgeExpressionCondition::class)->evaluate($expression, $message);
        } catch (Throwable $exception) {
            Log::warning('V3 edge expression condition failed.', [
                'scenario_id' => $this->scenario->id,
                'edge_id' => $edge['id'] ?? null,
                'edge_key' => $edge['edge_key'] ?? null,
                'message_id' => $message->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
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
    private function v3CapturedValueForEdge(Message $message, array $edge, ?array $block = null): array
    {
        $capture = is_array($edge['input_capture'] ?? null) ? $edge['input_capture'] : [];

        if ((bool) ($capture['enabled'] ?? false) !== true) {
            return ['valid' => true, 'value' => null];
        }

        $dataType = (string) ($capture['data_type'] ?? 'any_text');
        $sharedPhone = $this->v3SharedPhoneData($message);
        $callbackAnswerText = $this->v3SelectedButtonTextForCallbackMessage($message, $block);
        $answerText = $callbackAnswerText ?? $message->text;
        $value = trim((string) (($sharedPhone['normalized'] ?? null) ?? $answerText));
        $rawValue = trim((string) (($sharedPhone['raw'] ?? null) ?? $answerText));

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
        try {
            return DB::transaction(fn (): bool => $this->applyV3TransitionSideEffectsToDialogInTransaction(
                $dialog,
                $message,
                $edge,
                $capturedValue,
            ));
        } catch (Throwable $throwable) {
            Log::warning('scenario.v3_transition_side_effects_failed', [
                'scenario_code' => $this->code(),
                'dialog_id' => $message->dialog_id,
                'message_id' => $message->id,
                'edge_key' => $edge['edge_key'] ?? null,
                'exception_class' => $throwable::class,
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $edge
     * @param  array{valid?: bool, value?: string|null, phone_raw?: string|null, phone_normalized?: string|null}|null  $capturedValue
     */
    private function applyV3TransitionSideEffectsToDialogInTransaction(Dialog $dialog, Message $message, array $edge, ?array $capturedValue): bool
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
            } elseif (! $this->validV3DialogVariableKey($fieldKey)) {
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

        $this->applyV3TransitionActions($message, $edge, $fieldsPayload);

        data_set($fieldsPayload, '_v3.transition_counts.'.$counterKey, $currentCount + 1);

        $encoded = json_encode($fieldsPayload);

        if ($encoded === false || strlen($encoded) > self::V3_DIALOG_FIELDS_MAX_BYTES) {
            Log::warning('scenario.v3_dialog_fields_payload_limit_exceeded', [
                'scenario_code' => $this->code(),
                'dialog_id' => $message->dialog_id,
                'edge_key' => $edge['edge_key'] ?? null,
            ]);

            throw new RuntimeException('v3_dialog_fields_payload_limit_exceeded');
        }

        $dialog->forceFill(['fields_payload' => $fieldsPayload])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $edge
     * @param  array<string, mixed>  $fieldsPayload
     */
    private function applyV3TransitionActions(Message $message, array $edge, array &$fieldsPayload): void
    {
        $actions = is_array($edge['transition_actions'] ?? null) ? $edge['transition_actions'] : [];

        foreach ($actions as $index => $action) {
            if (! is_array($action) || ! $this->applyV3TransitionAction($message, $action, $fieldsPayload)) {
                Log::info('scenario.v3_transition_action_failed', [
                    'scenario_code' => $this->code(),
                    'dialog_id' => $message->dialog_id,
                    'message_id' => $message->id,
                    'edge_key' => $edge['edge_key'] ?? null,
                    'action_index' => $index,
                    'action_type' => is_array($action) ? ($action['type'] ?? null) : null,
                    'target_scope' => is_array($action) ? ($action['target_scope'] ?? null) : null,
                    'target_field' => is_array($action) ? ($action['target_field'] ?? null) : null,
                    'status' => 'validation_failed',
                ]);

                throw new RuntimeException('v3_transition_action_failed');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $fieldsPayload
     */
    private function applyV3TransitionAction(Message $message, array $action, array &$fieldsPayload): bool
    {
        if (($action['type'] ?? null) !== 'write_field' || ($action['value_source'] ?? 'static') !== 'static') {
            return false;
        }

        $targetScope = (string) ($action['target_scope'] ?? 'contact');
        $targetField = trim((string) ($action['target_field'] ?? ''));
        $value = trim((string) ($action['value'] ?? ''));

        if ($targetField === '' || $value === '') {
            return false;
        }

        if ($targetScope === 'dialog') {
            return $this->applyV3TransitionDialogFieldToPayload($fieldsPayload, $targetField, $value);
        }

        if ($targetScope === 'contact') {
            return $this->applyV3TransitionContactField($message, $targetField, $value);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $fieldsPayload
     */
    private function applyV3TransitionDialogFieldToPayload(array &$fieldsPayload, string $fieldKey, string $value): bool
    {
        if (! $this->validV3DialogVariableKey($fieldKey) || mb_strlen($value) > self::V3_DIALOG_FIELD_VALUE_MAX_LENGTH) {
            return false;
        }

        $userFieldCount = $this->v3DialogUserFieldCount($fieldsPayload);

        if (! array_key_exists($fieldKey, $fieldsPayload) && $userFieldCount >= self::V3_DIALOG_USER_FIELDS_MAX) {
            return false;
        }

        $fieldsPayload[$fieldKey] = $value;

        return true;
    }

    private function applyV3TransitionContactField(Message $message, string $fieldKey, string $value): bool
    {
        if (! $message->contact instanceof Contact || ! in_array($fieldKey, EngineFieldRegistry::CONTACT_TRANSITION_WRITE_FIELDS, true)) {
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
            'first_name' => $this->applyV3ContactFirstNameCapture(
                $lockedContact,
                $value,
                Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
                new FirstNameResolutionWriteContext(
                    dialogId: $message->dialog_id,
                    channelId: $message->channel_id,
                    scenarioId: $this->scenario->id,
                    messageId: $message->id,
                ),
            ),
            'last_name', 'country', 'region', 'city' => $this->applyV3ContactStringCapture($lockedContact, $fieldKey, $value),
            'gender' => $this->applyV3ContactEnumCapture($lockedContact, $fieldKey, $value, Contact::genderOptions()),
            'gender_source' => $this->applyV3ContactEnumCapture($lockedContact, $fieldKey, $value, Contact::genderSourceOptions()),
            'age_range' => $this->applyV3ContactEnumCapture($lockedContact, $fieldKey, $value, Contact::ageRangeOptions()),
            'age_years' => $this->applyV3ContactAgeYearsCapture($lockedContact, $value),
            'first_name_source' => $this->applyV3ContactEnumCapture($lockedContact, $fieldKey, $value, $this->firstNameSourceOptionsForV3()),
            'first_name_resolution_method' => $this->applyV3ContactEnumCapture($lockedContact, $fieldKey, $value, Contact::firstNameResolutionMethodOptions()),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    private function firstNameSourceOptionsForV3(): array
    {
        return collect(Contact::allowedFirstNameSources())
            ->mapWithKeys(fn (string $source): array => [
                $source => Contact::formatFirstNameSourceBadgeLabel($source) ?? $source,
            ])
            ->all();
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

        if (! in_array($fieldKey, EngineFieldRegistry::CONTACT_CAPTURE_FIELDS, true) || $value === '') {
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
            'first_name' => $this->applyV3ContactFirstNameCapture(
                $lockedContact,
                $value,
                is_string($capture['first_name_resolution_method'] ?? null)
                    ? $capture['first_name_resolution_method']
                    : Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
                ($capture['first_name_resolution_write_context'] ?? null) instanceof FirstNameResolutionWriteContext
                    ? $capture['first_name_resolution_write_context']
                    : new FirstNameResolutionWriteContext(
                        dialogId: $message->dialog_id,
                        channelId: $message->channel_id,
                        scenarioId: $this->scenario->id,
                        messageId: $message->id,
                    ),
            ),
            'last_name', 'country', 'region', 'city' => $this->applyV3ContactStringCapture($lockedContact, $fieldKey, $value),
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
        } catch (Throwable $throwable) {
            Log::warning('scenario.v3_contact_phone_capture_failed', [
                'scenario_code' => $this->code(),
                'contact_id' => $contact->id,
                'message_id' => $message->id,
                'exception' => get_class($throwable),
                'exception_code' => $throwable->getCode(),
                'error_message' => 'Не удалось сохранить телефон из V3-сценария.',
            ]);

            return false;
        }

        return true;
    }

    private function applyV3ContactFirstNameCapture(
        Contact $contact,
        string $value,
        ?string $resolutionMethod = Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
        ?FirstNameResolutionWriteContext $writeContext = null,
    ): bool {
        $result = $this->applyContactFirstNameAction->handle(
            $contact,
            $value,
            Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            ApplyContactFirstNameAction::REASON_SCENARIO_CONFIRMED,
            $resolutionMethod,
            $writeContext,
        );

        if ($result->bitrix24RelevantChanged) {
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
        bool $suppressMessage = false,
        bool $allowDelayedAiOutputs = true,
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

        $actionResult = $this->applyV3ActionModule($message, $runtime, $block, $statePayload, $run);

        if ($actionResult === null) {
            $activeBlockId = $isNonStateBlock && $previousBlockId !== null ? $previousBlockId : $blockId;

            return $this->activeProgress($activeBlockId, $statePayload);
        }

        $statePayload = $actionResult['state_payload'];

        if (filled($actionResult['reroute_block_id'] ?? null)) {
            $rerouteMessage = ($actionResult['reroute_message'] ?? null) instanceof Message
                ? $actionResult['reroute_message']
                : $message;

            $this->v3SimulateStartDepth++;

            try {
                $progress = $this->advanceV3FromBlock(
                    $rerouteMessage,
                    $runtime,
                    (string) $actionResult['reroute_block_id'],
                    $statePayload,
                    $remainingTransitions - 1,
                    $run,
                );

                $this->clearV3SimulatedStartSourceFieldAfterReroute(
                    $message,
                    $run,
                    $blockId,
                    is_array($actionResult['clear_source_field_after_reroute'] ?? null)
                        ? $actionResult['clear_source_field_after_reroute']
                        : null,
                );

                return $progress;
            } finally {
                $this->v3SimulateStartDepth = max(0, $this->v3SimulateStartDepth - 1);
            }
        }

        if (($actionResult['stop_current_execution'] ?? false) === true) {
            $activeBlockId = $isNonStateBlock && $previousBlockId !== null ? $previousBlockId : $blockId;

            return $this->activeProgress($activeBlockId, $statePayload);
        }

        if ($actionResult['output_id'] !== null) {
            $actionProgress = $this->advanceV3ActionResultEdge(
                $message,
                $runtime,
                $block,
                $statePayload,
                $actionResult['output_id'],
                $remainingTransitions,
                $run,
            );

            if ($actionProgress !== null) {
                return $actionProgress;
            }
        }

        if ($messagePayload !== null && ! $suppressMessage) {
            $replyButtonRows = $visibleButtonRows !== [] ? $visibleButtonRows : null;
            $removeTelegramKeyboard = false;
            $removeTelegramKeyboardBeforeMessage = false;
            $clearPendingTelegramKeyboardRemoval = false;

            if (
                $this->v3PendingTelegramKeyboardRemoval($statePayload)
                && $message->channel?->platform === Channel::PLATFORM_TELEGRAM
            ) {
                $replyMarkupKind = $this->v3TelegramReplyMarkupKind(false, $replyButtonRows, $buttonPlacement);

                if ($replyMarkupKind === 'none') {
                    $removeTelegramKeyboard = true;
                    $clearPendingTelegramKeyboardRemoval = true;
                } elseif ($replyMarkupKind === 'inline_message') {
                    // Telegram cannot remove a reply keyboard and show inline buttons in the same message.
                    // Send a technical cleanup message first, then delete it and send the real inline message.
                    $removeTelegramKeyboardBeforeMessage = true;
                    $clearPendingTelegramKeyboardRemoval = true;
                } elseif ($replyMarkupKind === 'reply_keyboard') {
                    $clearPendingTelegramKeyboardRemoval = true;
                }
            }

            if (! $this->dispatchScenarioMessage(
                $message,
                $this->v3TextWithVariables(
                    $message,
                    $this->v3MessageTextForPayload($message, $messagePayload),
                    $statePayload,
                    $blockId,
                ),
                (string) ($messagePayload['text_format'] ?? Message::TEXT_FORMAT_PLAIN_TEXT),
                removeTelegramKeyboard: $removeTelegramKeyboard,
                replyButtonRows: $replyButtonRows,
                buttonPlacement: $buttonPlacement,
                v3CallbackBlockId: $blockId,
                scenarioRun: $run,
                removeTelegramKeyboardBeforeMessage: $removeTelegramKeyboardBeforeMessage,
            )) {
                $activeBlockId = $isNonStateBlock && $previousBlockId !== null ? $previousBlockId : $blockId;

                return $this->activeProgress(
                    $activeBlockId,
                    $this->markPendingPromptDelivery($statePayload),
                );
            }

            if ($clearPendingTelegramKeyboardRemoval) {
                $statePayload = $this->clearV3PendingTelegramKeyboardRemoval($statePayload);
            }
        }

        $aiProgress = $this->advanceV3AiAnalysis(
            $message,
            $runtime,
            $block,
            $statePayload,
            $remainingTransitions,
            $run,
            $allowDelayedAiOutputs,
        );

        if ($aiProgress !== null) {
            return $aiProgress;
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

        if ($this->v3BlockHasActionModule($block) && $this->v3WaitReplyEdges($block) === [] && $this->v3AutomaticEdges($block) === []) {
            return $this->completedV3Progress($statePayload);
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
                || ! $this->v3EdgeAllowsDialogPhone($message, $edge)
                || ! $this->v3EdgeAllowsFieldCondition($message, $edge)
                || ! $this->v3EdgeAllowsTagCondition($message, $edge)
                || ! $this->v3EdgeAllowsExpression($message, $edge)
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
    private function advanceV3AiAnalysis(
        Message $message,
        array $runtime,
        array $block,
        array &$statePayload,
        int $remainingTransitions,
        ?ScenarioRun $run,
        bool $allowDelayedOutputs = true,
    ): ?array {
        $analysis = is_array($block['ai_analysis'] ?? null) ? $block['ai_analysis'] : null;

        if ($analysis === null) {
            return null;
        }

        $blockId = filled($block['id'] ?? null) ? (string) $block['id'] : 'unknown';
        $result = $this->v3AiAnalysisResult($message, $analysis, $statePayload, $blockId);

        if (($result['status'] ?? null) === 'retry_scheduled') {
            $statePayload = $this->scheduleV3AiAnalysisRetry(
                $message,
                $run,
                $blockId,
                $statePayload,
                (int) ($result['next_cycle'] ?? 2),
                is_numeric($result['ai_request_id'] ?? null) ? (int) $result['ai_request_id'] : null,
                is_numeric($result['last_attempt_id'] ?? null) ? (int) $result['last_attempt_id'] : null,
                filled($result['error_reason'] ?? null) ? (string) $result['error_reason'] : 'temporary_provider_error',
            );

            return $this->activeProgress(
                $blockId,
                $this->markV3Waiting($statePayload, $blockId, $block, $message->channel),
            );
        }

        $statePayload = $this->clearV3AiAnalysisRetry($statePayload, $blockId);
        $outputId = (string) ($result['output_id'] ?? '');
        $delaySeconds = max(0, min(300, (int) ($result['delay_seconds'] ?? 0)));

        data_set($statePayload, 'v3.ai_analysis.'.$blockId, [
            'output_id' => $outputId,
            'label' => (string) ($result['label'] ?? ''),
            'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
            'delay_seconds' => $delaySeconds,
            'message_id' => (int) $message->id,
            'ai_request_id' => is_numeric($result['ai_request_id'] ?? null) ? (int) $result['ai_request_id'] : null,
            'first_name_resolution_event_id' => is_numeric($result['first_name_resolution_event_id'] ?? null) ? (int) $result['first_name_resolution_event_id'] : null,
            'first_name_resolution_correlation_id' => is_string($result['first_name_resolution_correlation_id'] ?? null)
                ? $result['first_name_resolution_correlation_id']
                : null,
            'error' => (bool) ($result['error'] ?? false),
            'error_reason' => filled($result['error_reason'] ?? null) ? (string) $result['error_reason'] : null,
        ]);

        $edges = $this->v3AiAnalysisEdges($analysis, $outputId);

        if ($edges === []) {
            if ($outputId === self::V3_AI_FAILED_OUTPUT_ID) {
                data_set($statePayload, "v3.ai_analysis.$blockId.route_error_reason", 'ai_failed_no_edge');

                Log::warning('scenario.v3_ai_analysis_failed_no_edge', [
                    'scenario_code' => $this->code(),
                    'dialog_id' => $message->dialog_id,
                    'message_id' => $message->id,
                    'block_id' => $blockId,
                    'ai_request_id' => $result['ai_request_id'] ?? null,
                ]);

                return $this->activeProgress(
                    $blockId,
                    $this->markV3Waiting($statePayload, $blockId, $block, $message->channel),
                );
            }

            return null;
        }

        foreach ($edges as $edge) {
            $targetBlockId = filled($edge['target_block_id'] ?? null) ? (string) $edge['target_block_id'] : null;

            if ($targetBlockId === null) {
                continue;
            }

            if (
                ! $this->v3EdgeAllowsContactPhone($message, $edge)
                || ! $this->v3EdgeAllowsDialogPhone($message, $edge)
                || ! $this->v3EdgeAllowsFieldCondition($message, $edge)
                || ! $this->v3EdgeAllowsTagCondition($message, $edge)
                || ! $this->v3EdgeAllowsExpression($message, $edge)
            ) {
                continue;
            }

            if ($allowDelayedOutputs && $delaySeconds > 0) {
                $delayedStatePayload = $this->scheduleV3DelayedAiAnalysis(
                    $message,
                    $run,
                    $blockId,
                    $outputId,
                    $delaySeconds,
                    $statePayload,
                );

                if ($delayedStatePayload !== null) {
                    return $this->activeProgress(
                        $blockId,
                        $this->markV3Waiting($delayedStatePayload, $blockId, $block, $message->channel),
                    );
                }
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

            $statePayload = $this->clearV3AiAnalysisPending($statePayload, $blockId);

            return $this->advanceV3FromBlock(
                $message,
                $runtime,
                $targetBlockId,
                $statePayload,
                $remainingTransitions - 1,
                $run,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return list<array<string, mixed>>
     */
    private function v3AiAnalysisEdges(array $analysis, string $outputId): array
    {
        $outputs = is_array($analysis['outputs'] ?? null) ? $analysis['outputs'] : [];

        return collect($outputs)
            ->filter(fn (mixed $output): bool => is_array($output)
                && ($output['id'] ?? null) === $outputId
                && is_array($output['edge'] ?? null))
            ->map(fn (array $output): array => $output['edge'])
            ->sort(fn (array $left, array $right): int => $this->compareV3TransitionEdges($left, $right))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $statePayload
     * @return array{state_payload: array<string, mixed>, output_id: ?string, stop_current_execution?: bool, reroute_block_id?: string, reroute_message?: Message}|null
     */
    private function applyV3ActionModule(Message $message, array $runtime, array $block, array $statePayload, ?ScenarioRun $run): ?array
    {
        $actions = is_array($block['actions'] ?? null) ? $block['actions'] : [];

        if ($actions === []) {
            return ['state_payload' => $statePayload, 'output_id' => null];
        }

        foreach ($actions as $actionIndex => $action) {
            if (! is_array($action)) {
                continue;
            }

            if (($action['type'] ?? null) === 'check_data') {
                return $this->applyV3CheckDataAction(
                    $message,
                    $action,
                    $statePayload,
                    filled($block['id'] ?? null) ? (string) $block['id'] : null,
                );
            }

            if (($action['type'] ?? null) === 'edit_message') {
                $statePayload = $this->applyV3EditMessageAction($message, $action, $statePayload, $run);

                continue;
            }

            if (($action['type'] ?? null) === 'calculate_distance_to_moscow') {
                return $this->applyV3CalculateDistanceToMoscowAction(
                    $message,
                    $statePayload,
                    filled($block['id'] ?? null) ? (string) $block['id'] : null,
                );
            }

            if (($action['type'] ?? null) === 'resolve_geo_city') {
                return $this->applyV3ResolveGeoCityAction(
                    $message,
                    $action,
                    $block,
                    $statePayload,
                    filled($block['id'] ?? null) ? (string) $block['id'] : null,
                );
            }

            if (($action['type'] ?? null) === 'variables') {
                $result = $this->applyV3VariablesAction(
                    $message,
                    $action,
                    $statePayload,
                    filled($block['id'] ?? null) ? (string) $block['id'] : null,
                    $run,
                );

                $statePayload = $result['state_payload'] ?? $statePayload;

                if (($result['stop_current_execution'] ?? false) === true || ($result['output_id'] ?? null) !== null) {
                    return $result;
                }

                continue;
            }

            if (($action['type'] ?? null) === 'simulate_start_parameter') {
                $result = $this->applyV3SimulateStartParameterAction(
                    $message,
                    $runtime,
                    $action,
                    $block,
                    $statePayload,
                    $run,
                );

                if (filled($result['reroute_block_id'] ?? null)) {
                    return $result;
                }

                $statePayload = $result['state_payload'] ?? $statePayload;

                continue;
            }

            if (($action['type'] ?? null) === 'tag_effects') {
                $result = $this->applyV3TagEffectsAction(
                    $message,
                    $action,
                    $statePayload,
                    filled($block['id'] ?? null) ? (string) $block['id'] : null,
                    $run,
                );
                $statePayload = $result['state_payload'] ?? $statePayload;

                if (($result['stop_current_execution'] ?? false) === true || ($result['output_id'] ?? null) !== null) {
                    return $result;
                }

                continue;
            }

            if (($action['type'] ?? null) === 'complete_data_collection') {
                $result = $this->applyV3CompleteDataCollectionAction(
                    $message,
                    $statePayload,
                    filled($block['id'] ?? null) ? (string) $block['id'] : null,
                    $run,
                    (int) $actionIndex,
                );
                $statePayload = $result['state_payload'] ?? $statePayload;

                continue;
            }

            if (($action['type'] ?? null) === 'bitrix24_sync') {
                $result = $this->applyV3Bitrix24SyncAction(
                    $message,
                    $action,
                    $statePayload,
                    filled($block['id'] ?? null) ? (string) $block['id'] : null,
                    $run,
                    (int) $actionIndex,
                );
                $statePayload = $result['state_payload'] ?? $statePayload;

                continue;
            }

            if (($action['type'] ?? null) === 'change_field') {
                $this->applyV3ChangeFieldAction(
                    $message,
                    $action,
                    $statePayload,
                    filled($block['id'] ?? null) ? (string) $block['id'] : null,
                    $run,
                );

                continue;
            }

            if (($action['type'] ?? null) !== 'write_contact_field') {
                Log::warning('scenario.v3_unsupported_action_skipped', [
                    'scenario_code' => $this->code(),
                    'scenario_run_id' => $run?->id,
                    'block_id' => filled($block['id'] ?? null) ? (string) $block['id'] : null,
                    'action_type' => is_scalar($action['type'] ?? null) ? (string) $action['type'] : null,
                ]);

                return [
                    'state_payload' => $statePayload,
                    'output_id' => 'failed',
                ];
            }

            if (! $this->applyV3WriteContactFieldAction($message, $action, $statePayload)) {
                return null;
            }
        }

        return ['state_payload' => $statePayload, 'output_id' => null];
    }

    private function v3MessageContactId(Message $message): ?int
    {
        if (is_numeric($message->contact_id) && (int) $message->contact_id > 0) {
            return (int) $message->contact_id;
        }

        if ($message->contact instanceof Contact && is_numeric($message->contact->getKey())) {
            return (int) $message->contact->getKey();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array{state_payload: array<string, mixed>, output_id: null}
     */
    private function applyV3CompleteDataCollectionAction(
        Message $message,
        array $statePayload,
        ?string $blockId,
        ?ScenarioRun $run,
        int $actionIndex,
    ): array {
        $contactId = $this->v3MessageContactId($message);

        if ($contactId === null) {
            $diagnostic = $this->v3DataCollectionCompletionDiagnostic(
                status: 'missing_contact',
                blockId: $blockId,
                actionIndex: $actionIndex,
                message: $message,
                run: $run,
                completed: false,
                rootContactId: null,
                reason: 'missing_contact',
            );

            Log::warning('scenario.v3_complete_data_collection_missing_contact', [
                'scenario_code' => $this->code(),
                'scenario_run_id' => $run?->id,
                'block_id' => $blockId,
                'action_index' => $actionIndex,
                'message_id' => $message->id,
            ]);

            return [
                'state_payload' => $this->storeV3DataCollectionCompletionDiagnostic($statePayload, $blockId, $actionIndex, $diagnostic),
                'output_id' => null,
            ];
        }

        try {
            $completionResult = $this->completeContactDataCollectionIfReadyAction->handle($contactId);
            $diagnostic = $this->v3DataCollectionCompletionDiagnosticFromResult(
                $completionResult,
                blockId: $blockId,
                actionIndex: $actionIndex,
                message: $message,
                run: $run,
            );

            Log::info('scenario.v3_complete_data_collection_done', [
                'scenario_code' => $this->code(),
                'scenario_run_id' => $run?->id,
                'block_id' => $blockId,
                'action_index' => $actionIndex,
                'message_id' => $message->id,
                'status' => $diagnostic['status'] ?? null,
                'root_contact_id' => $diagnostic['root_contact_id'] ?? null,
            ]);

            return [
                'state_payload' => $this->storeV3DataCollectionCompletionDiagnostic($statePayload, $blockId, $actionIndex, $diagnostic),
                'output_id' => null,
            ];
        } catch (Throwable $exception) {
            $diagnostic = $this->v3DataCollectionCompletionDiagnostic(
                status: 'failed',
                blockId: $blockId,
                actionIndex: $actionIndex,
                message: $message,
                run: $run,
                completed: false,
                rootContactId: null,
                reason: 'completion_exception',
            );

            Log::warning('scenario.v3_complete_data_collection_failed', [
                'scenario_code' => $this->code(),
                'scenario_run_id' => $run?->id,
                'block_id' => $blockId,
                'action_index' => $actionIndex,
                'message_id' => $message->id,
                'error_class' => $exception::class,
            ]);

            return [
                'state_payload' => $this->storeV3DataCollectionCompletionDiagnostic($statePayload, $blockId, $actionIndex, $diagnostic),
                'output_id' => null,
            ];
        }
    }

    /**
     * @param  list<string>  $missingRequirements
     * @return array<string, mixed>
     */
    private function v3DataCollectionCompletionDiagnostic(
        string $status,
        ?string $blockId,
        int $actionIndex,
        Message $message,
        ?ScenarioRun $run,
        bool $completed,
        ?int $rootContactId,
        array $missingRequirements = [],
        ?string $reason = null,
    ): array {
        return array_filter([
            'status' => $status,
            'completed' => $completed,
            'root_contact_id' => $rootContactId,
            'block_id' => $blockId,
            'action_index' => $actionIndex,
            'message_id' => $message->id,
            'scenario_run_id' => $run?->id,
            'missing_requirements' => $missingRequirements !== [] ? $missingRequirements : null,
            'reason' => $reason,
            'executed_at' => now()->toISOString(),
        ], fn (mixed $value): bool => $value !== null);
    }

    private function v3DataCollectionCompletionDiagnosticFromResult(
        ContactDataCollectionCompletionResult $result,
        ?string $blockId,
        int $actionIndex,
        Message $message,
        ?ScenarioRun $run,
    ): array {
        return $this->v3DataCollectionCompletionDiagnostic(
            status: $result->status,
            blockId: $blockId,
            actionIndex: $actionIndex,
            message: $message,
            run: $run,
            completed: $result->completed,
            rootContactId: $result->rootContactId,
            missingRequirements: $result->missingRequirements,
            reason: $result->reason,
        );
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @param  array<string, mixed>  $diagnostic
     * @return array<string, mixed>
     */
    private function storeV3DataCollectionCompletionDiagnostic(
        array $statePayload,
        ?string $blockId,
        int $actionIndex,
        array $diagnostic,
    ): array {
        $blockKey = filled($blockId) ? (string) $blockId : 'unknown_block';
        $entries = data_get($statePayload, 'v3.data_collection_completion', []);

        if (! is_array($entries)) {
            $entries = [];
        }

        if (! isset($entries[$blockKey]) || ! is_array($entries[$blockKey])) {
            $entries[$blockKey] = [];
        }

        $entries[$blockKey][(string) $actionIndex] = $diagnostic;
        $entries['last'] = $diagnostic;

        data_set($statePayload, 'v3.data_collection_completion', $entries);

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $statePayload
     * @return array{state_payload: array<string, mixed>, output_id: null}
     */
    private function applyV3Bitrix24SyncAction(
        Message $message,
        array $action,
        array $statePayload,
        ?string $blockId,
        ?ScenarioRun $run,
        int $actionIndex,
    ): array {
        $operation = $this->normalizeV3Bitrix24SyncOperation($action['operation'] ?? null);
        $contactId = $this->v3MessageContactId($message);

        if ($contactId === null) {
            $diagnostic = $this->v3Bitrix24SyncDiagnostic(
                operation: $operation,
                status: 'missing_contact',
                blockId: $blockId,
                actionIndex: $actionIndex,
                message: $message,
                run: $run,
                queued: false,
                alreadyPending: false,
                ready: false,
                rootContactId: null,
                reason: 'missing_contact',
            );

            Log::warning('scenario.v3_bitrix24_sync_missing_contact', [
                'scenario_code' => $this->code(),
                'scenario_run_id' => $run?->id,
                'block_id' => $blockId,
                'action_index' => $actionIndex,
                'message_id' => $message->id,
                'operation' => $operation,
            ]);

            return [
                'state_payload' => $this->storeV3Bitrix24SyncDiagnostic($statePayload, $blockId, $actionIndex, $diagnostic),
                'output_id' => null,
            ];
        }

        try {
            $rootContact = $this->resolveRootContactAction->handle($contactId);
            $completionResult = $this->completeContactDataCollectionIfReadyAction->handle($rootContact);
            $completionDiagnostic = $this->v3DataCollectionCompletionDiagnosticFromResult(
                $completionResult,
                blockId: $blockId,
                actionIndex: $actionIndex,
                message: $message,
                run: $run,
            );
            $statePayload = $this->storeV3DataCollectionCompletionDiagnostic(
                $statePayload,
                $blockId,
                $actionIndex,
                $completionDiagnostic,
            );

            if (! $completionResult->completed) {
                $diagnostic = $this->v3Bitrix24SyncDiagnostic(
                    operation: $operation,
                    status: 'not_ready',
                    blockId: $blockId,
                    actionIndex: $actionIndex,
                    message: $message,
                    run: $run,
                    queued: false,
                    alreadyPending: false,
                    ready: false,
                    rootContactId: $completionResult->rootContactId,
                    reason: 'data_collection_not_ready',
                );

                Log::info('scenario.v3_bitrix24_sync_skipped_until_data_collection_ready', [
                    'scenario_code' => $this->code(),
                    'scenario_run_id' => $run?->id,
                    'block_id' => $blockId,
                    'action_index' => $actionIndex,
                    'message_id' => $message->id,
                    'operation' => $operation,
                    'root_contact_id' => $completionResult->rootContactId,
                    'missing_requirements' => $completionResult->missingRequirements,
                ]);

                return [
                    'state_payload' => $this->storeV3Bitrix24SyncDiagnostic($statePayload, $blockId, $actionIndex, $diagnostic),
                    'output_id' => null,
                ];
            }

            if (is_numeric($completionResult->rootContactId)) {
                $rootContact = Contact::query()->findOrFail((int) $completionResult->rootContactId);
            }

            $queueResult = match ($operation) {
                'deal_sync' => $this->queueBitrix24DealSyncAction->handle($rootContact),
                'history_export' => $this->queueBitrix24HistoryExportAction->handle($rootContact),
                default => $this->queueBitrix24ContactSyncAction->handle($rootContact),
            };

            $diagnostic = $this->v3Bitrix24SyncDiagnostic(
                operation: $operation,
                status: $this->v3Bitrix24SyncStatus($queueResult),
                blockId: $blockId,
                actionIndex: $actionIndex,
                message: $message,
                run: $run,
                queued: $queueResult->queued,
                alreadyPending: $queueResult->alreadyPending,
                ready: $queueResult->ready,
                rootContactId: $queueResult->rootContactId,
            );

            Log::info('scenario.v3_bitrix24_sync_done', [
                'scenario_code' => $this->code(),
                'scenario_run_id' => $run?->id,
                'block_id' => $blockId,
                'action_index' => $actionIndex,
                'message_id' => $message->id,
                'operation' => $operation,
                'status' => $diagnostic['status'],
                'root_contact_id' => $queueResult->rootContactId,
                'data_collection_completion_status' => $completionResult->status,
            ]);

            return [
                'state_payload' => $this->storeV3Bitrix24SyncDiagnostic($statePayload, $blockId, $actionIndex, $diagnostic),
                'output_id' => null,
            ];
        } catch (Throwable $exception) {
            $diagnostic = $this->v3Bitrix24SyncDiagnostic(
                operation: $operation,
                status: 'failed',
                blockId: $blockId,
                actionIndex: $actionIndex,
                message: $message,
                run: $run,
                queued: false,
                alreadyPending: false,
                ready: false,
                rootContactId: null,
                reason: 'queue_exception',
            );

            Log::warning('scenario.v3_bitrix24_sync_failed', [
                'scenario_code' => $this->code(),
                'scenario_run_id' => $run?->id,
                'block_id' => $blockId,
                'action_index' => $actionIndex,
                'message_id' => $message->id,
                'operation' => $operation,
                'error_class' => $exception::class,
            ]);

            return [
                'state_payload' => $this->storeV3Bitrix24SyncDiagnostic($statePayload, $blockId, $actionIndex, $diagnostic),
                'output_id' => null,
            ];
        }
    }

    private function normalizeV3Bitrix24SyncOperation(mixed $operation): string
    {
        $value = is_scalar($operation) ? trim((string) $operation) : '';

        return in_array($value, self::V3_BITRIX24_SYNC_OPERATIONS, true)
            ? $value
            : 'contact_sync';
    }

    private function v3Bitrix24SyncStatus(
        Bitrix24ContactSyncQueueResultData|Bitrix24DealSyncQueueResultData|Bitrix24HistoryExportQueueResultData $queueResult,
    ): string {
        if ($queueResult->queued) {
            return 'queued';
        }

        if ($queueResult->alreadyPending && $queueResult->ready) {
            return 'already_pending';
        }

        if (! $queueResult->ready) {
            return 'not_ready';
        }

        return 'failed';
    }

    /**
     * @return array<string, mixed>
     */
    private function v3Bitrix24SyncDiagnostic(
        string $operation,
        string $status,
        ?string $blockId,
        int $actionIndex,
        Message $message,
        ?ScenarioRun $run,
        bool $queued,
        bool $alreadyPending,
        bool $ready,
        ?int $rootContactId,
        ?string $reason = null,
    ): array {
        return array_filter([
            'operation' => $operation,
            'status' => $status,
            'queued' => $queued,
            'already_pending' => $alreadyPending,
            'ready' => $ready,
            'root_contact_id' => $rootContactId,
            'block_id' => $blockId,
            'action_index' => $actionIndex,
            'message_id' => $message->id,
            'scenario_run_id' => $run?->id,
            'reason' => $reason,
            'executed_at' => now()->toISOString(),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @param  array<string, mixed>  $diagnostic
     * @return array<string, mixed>
     */
    private function storeV3Bitrix24SyncDiagnostic(
        array $statePayload,
        ?string $blockId,
        int $actionIndex,
        array $diagnostic,
    ): array {
        $blockKey = filled($blockId) ? (string) $blockId : 'unknown_block';
        $entries = data_get($statePayload, 'v3.bitrix24_sync', []);

        if (! is_array($entries)) {
            $entries = [];
        }

        if (! isset($entries[$blockKey]) || ! is_array($entries[$blockKey])) {
            $entries[$blockKey] = [];
        }

        $entries[$blockKey][(string) $actionIndex] = $diagnostic;
        $entries['last'] = $diagnostic;

        data_set($statePayload, 'v3.bitrix24_sync', $entries);

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $statePayload
     * @return array{state_payload: array<string, mixed>, output_id: ?string, stop_current_execution?: bool}
     */
    private function applyV3TagEffectsAction(
        Message $message,
        array $action,
        array $statePayload,
        ?string $blockId,
        ?ScenarioRun $run,
    ): array {
        $assignTagIds = $this->v3TagEffectIds($action['assign_tag_ids'] ?? []);
        $removeTagIds = $this->v3TagEffectIds($action['remove_tag_ids'] ?? []);
        $allTagIds = array_values(array_unique([...$assignTagIds, ...$removeTagIds]));

        if ($allTagIds === []) {
            return ['state_payload' => $statePayload, 'output_id' => null];
        }

        if (! $message->contact instanceof Contact) {
            Log::warning('scenario.v3_tag_effects_failed', [
                'scenario_code' => $this->code(),
                'scenario_run_id' => $run?->id,
                'block_id' => $blockId,
                'message_id' => $message->id,
                'reason' => 'missing_contact',
            ]);

            return [
                'state_payload' => $statePayload,
                'output_id' => null,
                'stop_current_execution' => true,
            ];
        }

        $tagsById = Tag::query()
            ->active()
            ->whereKey($allTagIds)
            ->get(['id', 'slug'])
            ->keyBy(fn (Tag $tag): int => (int) $tag->id);

        if ($tagsById->count() !== count($allTagIds)) {
            Log::warning('scenario.v3_tag_effects_failed', [
                'scenario_code' => $this->code(),
                'scenario_run_id' => $run?->id,
                'block_id' => $blockId,
                'message_id' => $message->id,
                'reason' => 'unknown_tag',
                'tag_ids' => $allTagIds,
            ]);

            return [
                'state_payload' => $statePayload,
                'output_id' => null,
                'stop_current_execution' => true,
            ];
        }

        $tagActions = [];

        foreach ($assignTagIds as $tagId) {
            $tag = $tagsById->get($tagId);

            if ($tag instanceof Tag) {
                $tagActions[] = ['type' => 'set_tag', 'value' => (string) $tag->slug];
            }
        }

        foreach ($removeTagIds as $tagId) {
            $tag = $tagsById->get($tagId);

            if ($tag instanceof Tag) {
                $tagActions[] = ['type' => 'remove_tag', 'value' => (string) $tag->slug];
            }
        }

        try {
            $contact = $this->applyScenarioTagEffectsAction->handle($message->contact, $tagActions);
            $this->forgetV3RootContactTagIds($message, $contact);
        } catch (Throwable $exception) {
            Log::warning('scenario.v3_tag_effects_failed', [
                'scenario_code' => $this->code(),
                'scenario_run_id' => $run?->id,
                'block_id' => $blockId,
                'message_id' => $message->id,
                'reason' => 'runtime_error',
                'error' => $exception->getMessage(),
            ]);

            return [
                'state_payload' => $statePayload,
                'output_id' => null,
                'stop_current_execution' => true,
            ];
        }

        Log::info('scenario.v3_tag_effects_done', [
            'scenario_code' => $this->code(),
            'scenario_run_id' => $run?->id,
            'block_id' => $blockId,
            'message_id' => $message->id,
            'assign_tag_ids' => $assignTagIds,
            'remove_tag_ids' => $removeTagIds,
        ]);

        return ['state_payload' => $statePayload, 'output_id' => null];
    }

    /**
     * @return list<int>
     */
    private function v3TagEffectIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $statePayload
     * @return array{state_payload: array<string, mixed>, output_id: ?string, reroute_block_id?: string, reroute_message?: Message, clear_source_field_after_reroute?: array{source_scope: string, source_field_key: string, used_value: string}}
     */
    private function applyV3SimulateStartParameterAction(
        Message $message,
        array $runtime,
        array $action,
        array $block,
        array $statePayload,
        ?ScenarioRun $run,
    ): array {
        $blockId = filled($block['id'] ?? null) ? (string) $block['id'] : null;
        $sourceScope = (string) ($action['source_scope'] ?? 'dialog');
        $sourceFieldKey = trim((string) ($action['source_field_key'] ?? ''));
        $clearSourceFieldAfterReroute = (bool) ($action['clear_source_field_after_reroute'] ?? false);

        if ($sourceScope !== 'dialog' || ! $this->validV3DialogVariableKey($sourceFieldKey)) {
            if ($clearSourceFieldAfterReroute && $sourceScope !== 'dialog') {
                $this->logV3SimulateStartParameter($message, $run, $blockId, 'source_field_clear_skipped_not_dialog_scope', [
                    'source_scope' => $sourceScope,
                    'source_field_key' => $sourceFieldKey,
                ]);
            }

            $this->logV3SimulateStartParameter($message, $run, $blockId, 'invalid_action', [
                'source_scope' => $sourceScope,
                'source_field_key' => $sourceFieldKey,
            ]);

            return ['state_payload' => $statePayload, 'output_id' => null];
        }

        if ($this->v3SimulateStartDepth > 0) {
            $this->logV3SimulateStartParameter($message, $run, $blockId, 'loop_guard', [
                'source_field_key' => $sourceFieldKey,
            ]);

            return ['state_payload' => $statePayload, 'output_id' => null];
        }

        $parameter = $this->v3DialogFieldStringValue($message, $sourceFieldKey);

        if ($parameter === '') {
            if ($clearSourceFieldAfterReroute) {
                $this->logV3SimulateStartParameter($message, $run, $blockId, 'source_field_clear_skipped_empty_parameter', [
                    'source_field_key' => $sourceFieldKey,
                ]);
            }

            $this->logV3SimulateStartParameter($message, $run, $blockId, 'empty_parameter', [
                'source_field_key' => $sourceFieldKey,
            ]);

            return ['state_payload' => $statePayload, 'output_id' => null];
        }

        if (mb_strlen($parameter) > 255) {
            $this->logV3SimulateStartParameter($message, $run, $blockId, 'parameter_too_long', [
                'source_field_key' => $sourceFieldKey,
                'parameter_length' => mb_strlen($parameter),
            ]);

            return ['state_payload' => $statePayload, 'output_id' => null];
        }

        $startMessage = $this->v3VirtualStartParameterMessage($message, $parameter);
        $targetBlockId = $this->matchingV3EntrypointBlockId($startMessage, $runtime);

        if ($targetBlockId === null) {
            if ($clearSourceFieldAfterReroute) {
                $this->logV3SimulateStartParameter($message, $run, $blockId, 'source_field_clear_skipped_not_found', [
                    'source_field_key' => $sourceFieldKey,
                    'parameter' => $parameter,
                ]);
            }

            $this->logV3SimulateStartParameter($message, $run, $blockId, 'start_block_not_found', [
                'source_field_key' => $sourceFieldKey,
                'parameter' => $parameter,
            ]);

            return ['state_payload' => $statePayload, 'output_id' => null];
        }

        if ($blockId !== null && $targetBlockId === $blockId) {
            $this->logV3SimulateStartParameter($message, $run, $blockId, 'same_block_noop', [
                'source_field_key' => $sourceFieldKey,
                'parameter' => $parameter,
                'target_block_id' => $targetBlockId,
            ]);

            return ['state_payload' => $statePayload, 'output_id' => null];
        }

        $this->logV3SimulateStartParameter($message, $run, $blockId, 'start_block_matched', [
            'source_field_key' => $sourceFieldKey,
            'parameter' => $parameter,
            'target_block_id' => $targetBlockId,
        ]);
        $this->logV3SimulateStartParameter($message, $run, $blockId, 'rerouted', [
            'source_field_key' => $sourceFieldKey,
            'parameter' => $parameter,
            'target_block_id' => $targetBlockId,
        ]);

        data_set($statePayload, 'v3.entrypoint.parameter', $parameter);

        return [
            'state_payload' => $statePayload,
            'output_id' => null,
            'reroute_block_id' => $targetBlockId,
            'reroute_message' => $startMessage,
            ...($clearSourceFieldAfterReroute ? [
                'clear_source_field_after_reroute' => [
                    'source_scope' => 'dialog',
                    'source_field_key' => $sourceFieldKey,
                    'used_value' => $parameter,
                ],
            ] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function applyV3EditMessageAction(Message $message, array $action, array $statePayload, ?ScenarioRun $run): array
    {
        if (
            ($action['operation'] ?? null) === 'remove_buttons'
            && ($action['target'] ?? null) === 'last_current_run_outbound_with_inline_buttons'
        ) {
            if ($message->channel?->platform !== Channel::PLATFORM_TELEGRAM) {
                return $statePayload;
            }

            if ($run instanceof ScenarioRun) {
                $this->removeV3TelegramInlineButtonsFromLastCurrentRunMessage($message, $run);
            }

            return $statePayload;
        }

        if (
            ($action['operation'] ?? null) === 'delete_message'
            && ($action['target'] ?? null) === 'last_current_run_outbound'
        ) {
            if ($message->channel?->platform === Channel::PLATFORM_TELEGRAM) {
                $statePayload = $this->markV3PendingTelegramKeyboardRemoval($statePayload);
            }

            if ($run instanceof ScenarioRun) {
                $this->deleteV3LastCurrentRunMessage($message, $run);
            }

            return $statePayload;
        }

        return $statePayload;
    }

    private function deleteV3LastCurrentRunMessage(Message $message, ScenarioRun $run): void
    {
        $dialog = $message->dialog;

        if (! $dialog instanceof Dialog && filled($message->dialog_id)) {
            $dialog = Dialog::query()->find($message->dialog_id);
        }

        if (! $dialog instanceof Dialog) {
            Log::info('scenario.v3_edit_message_delete_message_skipped', [
                'scenario_code' => $this->code(),
                'scenario_run_id' => $run->id,
                'dialog_id' => $message->dialog_id,
                'status' => DeleteLastOutboundDialogMessageAction::STATUS_MISSING_LAST_MESSAGE,
            ]);

            return;
        }

        $result = $this->deleteLastOutboundDialogMessageAction->handle($dialog);

        $context = [
            'scenario_code' => $this->code(),
            'scenario_run_id' => $run->id,
            'dialog_id' => $dialog->id,
            'message_id' => $result->messageId,
            'external_message_id' => $result->externalMessageId,
            'status' => $result->status,
        ];

        if ($result->error !== null) {
            $context['error_message'] = $result->error;
        }

        if ($result->status === DeleteLastOutboundDialogMessageAction::STATUS_PROVIDER_FAILED) {
            Log::warning('scenario.v3_edit_message_delete_message_failed', $context);

            return;
        }

        Log::info('scenario.v3_edit_message_delete_message_result', $context);
    }

    private function removeV3TelegramInlineButtonsFromLastCurrentRunMessage(Message $message, ScenarioRun $run): void
    {
        $channel = $message->channel;

        if (! $channel instanceof Channel || $channel->platform !== Channel::PLATFORM_TELEGRAM) {
            return;
        }

        $sentMessage = $this->lastV3SentMessageWithTelegramInlineButtons($message, $run, $channel);
        $outbound = null;

        if (! $sentMessage instanceof Message) {
            $outbound = ScenarioV3OutboundMessage::query()
                ->with(['outboundMessage'])
                ->where('scenario_run_id', $run->id)
                ->where('dialog_id', $message->dialog_id)
                ->where('channel_id', $channel->id)
                ->where('published_version_id', $this->publishedVersion->id)
                ->where('scenario_code', $this->code())
                ->where('status', ScenarioV3OutboundMessage::STATUS_SENT)
                ->whereNotNull('outbound_message_id')
                ->orderByDesc('id')
                ->get()
                ->first(fn (ScenarioV3OutboundMessage $outbound): bool => $outbound->outboundMessage instanceof Message
                    && $this->v3OutboundMessageHasTelegramInlineButtons($outbound->outboundMessage));

            $sentMessage = $outbound instanceof ScenarioV3OutboundMessage && $outbound->outboundMessage instanceof Message
                ? $outbound->outboundMessage
                : null;
        }

        if (! $sentMessage instanceof Message) {
            return;
        }

        $chatId = filled($sentMessage->external_chat_id)
            ? (string) $sentMessage->external_chat_id
            : (string) $message->dialog?->external_chat_id;
        $externalMessageId = filled($sentMessage->external_message_id)
            ? (string) $sentMessage->external_message_id
            : (string) data_get($sentMessage->raw_payload, 'result.message_id', '');

        if ($chatId === '' || $externalMessageId === '') {
            return;
        }

        try {
            $this->telegramBotApiService->editMessageReplyMarkup(
                $channel,
                $chatId,
                $externalMessageId,
                ['inline_keyboard' => []],
            );
        } catch (Throwable $throwable) {
            Log::warning('scenario.v3_edit_message_remove_buttons_failed', [
                'scenario_code' => $this->code(),
                'scenario_run_id' => $run->id,
                'dialog_id' => $message->dialog_id,
                'outbound_message_id' => $outbound?->id,
                'message_id' => $sentMessage->id,
                'exception' => get_class($throwable),
                'error_message' => $outbound instanceof ScenarioV3OutboundMessage
                    ? $this->safeV3OutboundMessageErrorMessage($throwable->getMessage(), $outbound)
                    : $this->safeV3TelegramApiErrorMessage($throwable->getMessage(), $channel),
            ]);
        }
    }

    private function lastV3SentMessageWithTelegramInlineButtons(Message $message, ScenarioRun $run, Channel $channel): ?Message
    {
        return Message::query()
            ->where('dialog_id', $message->dialog_id)
            ->where('channel_id', $channel->id)
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('message_kind', Message::KIND_OUTBOUND_SCENARIO_MESSAGE)
            ->whereNotNull('external_message_id')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->first(fn (Message $sentMessage): bool => (int) data_get($sentMessage->raw_payload, 'v3.scenario_run_id') === (int) $run->id
                && (string) data_get($sentMessage->raw_payload, 'v3.scenario_code') === $this->code()
                && (int) data_get($sentMessage->raw_payload, 'v3.published_version_id') === (int) $this->publishedVersion->id
                && $this->v3OutboundMessageHasTelegramInlineButtons($sentMessage));
    }

    private function safeV3TelegramApiErrorMessage(string $message, Channel $channel): ?string
    {
        if (! filled($message)) {
            return null;
        }

        $safeMessage = trim($message);

        foreach ([$channel->getToken(), $channel->getWebhookSecret()] as $secret) {
            if (filled($secret)) {
                $safeMessage = str_replace((string) $secret, '[secret]', $safeMessage);
            }
        }

        $safeMessage = preg_replace('/bot[0-9A-Za-z:_-]+(?=\/)/u', 'bot[secret]', $safeMessage) ?? $safeMessage;
        $safeMessage = preg_replace('/([?&](?:token|access_token|auth|secret)=)[^&\s]+/iu', '$1[secret]', $safeMessage) ?? $safeMessage;

        return mb_substr($safeMessage, 0, 1000);
    }

    private function v3OutboundMessageHasTelegramInlineButtons(Message $message): bool
    {
        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $inlineKeyboard = data_get($rawPayload, 'result.reply_markup.inline_keyboard');

        if (is_array($inlineKeyboard) && $inlineKeyboard !== []) {
            return true;
        }

        $buttonRows = data_get($rawPayload, 'v3.buttons.rows');

        if (! is_array($buttonRows) || $buttonRows === []) {
            return false;
        }

        $placement = (string) data_get($rawPayload, 'v3.buttons.placement', self::V3_BUTTON_PLACEMENT_AUTO);

        if ($placement === self::V3_BUTTON_PLACEMENT_INLINE_MESSAGE) {
            return true;
        }

        if ($placement !== self::V3_BUTTON_PLACEMENT_AUTO) {
            return false;
        }

        return collect($buttonRows)
            ->flatten(1)
            ->contains(fn (mixed $button): bool => is_array($button)
                && ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) === self::V3_BUTTON_TYPE_LINK
                && filled($button['url'] ?? null));
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $statePayload
     * @return array{state_payload: array<string, mixed>, output_id: ?string}|null
     */
    private function applyV3CheckDataAction(Message $message, array $action, array $statePayload, ?string $blockId): ?array
    {
        if (($action['source_type'] ?? null) !== 'inbound_message') {
            return null;
        }

        $dictionaryKey = trim((string) ($action['dictionary_key'] ?? ''));
        $targetVariableKey = trim((string) ($action['target_variable_key'] ?? ''));

        if ($dictionaryKey === '' || $targetVariableKey === '') {
            return null;
        }

        $rootContact = $message->contact instanceof Contact
            ? $this->resolveRootContactAction->handle($message->contact)
            : null;
        $lookup = app(LookupScenarioDataDictionaryAction::class)->handle(
            $dictionaryKey,
            (string) ($message->text ?? ''),
            $rootContact instanceof Contact ? $rootContact->gender : null,
        );
        $correlationId = (string) Str::uuid();
        $resolutionEvent = $rootContact instanceof Contact
            ? $this->firstNameResolutionAnalyticsService->recordResolutionAttempt(
                contact: $rootContact,
                source: ContactFirstNameResolutionEvent::SOURCE_DICTIONARY,
                result: $this->v3DictionaryLookupAnalyticsResult((string) ($lookup['status'] ?? '')),
                clientText: (string) ($message->text ?? ''),
                dialogId: $message->dialog_id,
                channelId: $message->channel_id,
                scenarioId: $this->scenario->id,
                scenarioBlockId: $blockId,
                messageId: $message->id,
                matchedDictionaryEntryId: is_numeric($lookup['matched_entry_id'] ?? null) ? (int) $lookup['matched_entry_id'] : null,
                foundFirstName: (string) ($message->text ?? ''),
                resolvedFirstName: is_string($lookup['value'] ?? null) ? $lookup['value'] : null,
                correlationId: $correlationId,
                payload: ['status' => $lookup['status'] ?? null],
            )
            : null;

        if ($lookup['matched'] === true && trim((string) $lookup['value']) !== '') {
            data_set($statePayload, "v3.variables.$targetVariableKey", trim((string) $lookup['value']));
            data_set(
                $statePayload,
                "v3.variable_meta.$targetVariableKey.first_name_resolution_method",
                Contact::FIRST_NAME_RESOLUTION_METHOD_DICTIONARY_LOOKUP,
            );
            data_set($statePayload, "v3.variable_meta.$targetVariableKey.first_name_resolution_correlation_id", $correlationId);
            data_set($statePayload, "v3.variable_meta.$targetVariableKey.first_name_resolution_event_id", $resolutionEvent?->id);

            return ['state_payload' => $statePayload, 'output_id' => 'data_found'];
        }

        if (in_array($lookup['status'] ?? null, [
            LookupScenarioDataDictionaryAction::STATUS_AMBIGUOUS,
            LookupScenarioDataDictionaryAction::STATUS_MANUAL_REQUIRED,
        ], true)) {
            return ['state_payload' => $statePayload, 'output_id' => 'data_manual_required'];
        }

        return ['state_payload' => $statePayload, 'output_id' => 'data_not_found'];
    }

    private function v3DictionaryLookupAnalyticsResult(string $status): string
    {
        return match ($status) {
            LookupScenarioDataDictionaryAction::STATUS_MATCHED => ContactFirstNameResolutionEvent::RESULT_MATCHED,
            LookupScenarioDataDictionaryAction::STATUS_AMBIGUOUS => ContactFirstNameResolutionEvent::RESULT_AMBIGUOUS,
            LookupScenarioDataDictionaryAction::STATUS_MANUAL_REQUIRED => ContactFirstNameResolutionEvent::RESULT_MANUAL_REQUIRED,
            default => ContactFirstNameResolutionEvent::RESULT_NOT_FOUND,
        };
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
    private function advanceV3ActionResultEdge(
        Message $message,
        array $runtime,
        array $block,
        array $statePayload,
        string $outputId,
        int $remainingTransitions,
        ?ScenarioRun $run,
    ): ?array {
        $blockEdges = is_array($block['action_result_edges'] ?? null) ? $block['action_result_edges'] : [];
        $runtimeEdges = collect(is_array($runtime['edges'] ?? null) ? $runtime['edges'] : [])
            ->filter(fn (mixed $edge): bool => is_array($edge)
                && (string) ($edge['source_block_id'] ?? '') === (string) ($block['id'] ?? '')
                && ($edge['from_output_id'] ?? null) === $outputId)
            ->values()
            ->all();

        $edges = collect([...$blockEdges, ...$runtimeEdges])
            ->filter(fn (mixed $edge): bool => is_array($edge)
                && ($edge['from_output_id'] ?? null) === $outputId
                && filled($edge['target_block_id'] ?? null))
            ->sort(fn (array $left, array $right): int => $this->compareV3TransitionEdges($left, $right))
            ->values();

        foreach ($edges as $edge) {
            if (
                ! $this->v3EdgeAllowsContactPhone($message, $edge)
                || ! $this->v3EdgeAllowsDialogPhone($message, $edge)
                || ! $this->v3EdgeAllowsFieldCondition($message, $edge)
                || ! $this->v3EdgeAllowsTagCondition($message, $edge)
                || ! $this->v3EdgeAllowsExpression($message, $edge)
            ) {
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

            return $this->advanceV3FromBlock(
                $message,
                $runtime,
                (string) $edge['target_block_id'],
                $statePayload,
                $remainingTransitions - 1,
                $run,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $statePayload
     */
    private function applyV3WriteContactFieldAction(Message $message, array $action, array $statePayload): bool
    {
        $sourceType = (string) ($action['source_type'] ?? 'ai_data');
        $targetScope = (string) ($action['target_scope'] ?? 'contact');

        if (! in_array($sourceType, ['ai_data', 'static_value'], true) || ! in_array($targetScope, ['contact', 'dialog'], true)) {
            return false;
        }

        $targetField = trim((string) ($action['target_field'] ?? ''));
        $sourceBlockId = trim((string) ($action['source_block_id'] ?? ''));
        $sourceFieldKey = trim((string) ($action['source_field_key'] ?? ''));

        if ($targetField === '') {
            return false;
        }

        $value = $sourceType === 'static_value'
            ? ($action['static_value'] ?? null)
            : ($sourceFieldKey !== '' ? $this->v3ActionAiDataValue($statePayload, $sourceBlockId, $sourceFieldKey) : null);

        if ($value === null || trim((string) $value) === '') {
            Log::info('scenario.v3_action_source_value_missing', [
                'scenario_code' => $this->code(),
                'dialog_id' => $message->dialog_id,
                'message_id' => $message->id,
                'source_block_id' => $sourceBlockId !== '' ? $sourceBlockId : null,
                'source_field_key' => $sourceFieldKey,
                'target_field' => $targetField,
            ]);

            return false;
        }

        $stringValue = trim((string) $value);
        $firstNameResolutionMethod = $targetField === 'first_name'
            ? ($sourceType === 'static_value'
                ? Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT
                : $this->v3ActionFirstNameResolutionMethod($statePayload, $sourceBlockId, $sourceFieldKey))
            : null;

        if ($targetScope === 'dialog') {
            return $this->applyV3WriteDialogFieldAction($message, $targetField, $stringValue);
        }

        if (! in_array($targetField, EngineFieldRegistry::CONTACT_CAPTURE_FIELDS, true)) {
            return false;
        }

        $dataType = EngineFieldRegistry::CONTACT_CAPTURE_DATA_TYPES[$targetField] ?? 'any_text';

        return $this->applyV3TransitionCaptureToContact($message, [
            'field_key' => $targetField,
            'data_type' => $dataType,
            'first_name_resolution_method' => $firstNameResolutionMethod,
            'first_name_resolution_write_context' => $targetField === 'first_name'
                ? $this->v3ActionFirstNameResolutionWriteContext($message, $statePayload, $sourceBlockId, $sourceFieldKey)
                : null,
        ], [
            'valid' => true,
            'value' => $stringValue,
            'phone_raw' => $dataType === 'phone' ? $stringValue : null,
            'phone_normalized' => $dataType === 'phone'
                ? app(NormalizePhoneNumberAction::class)->handle($stringValue)
                : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array{state_payload: array<string, mixed>, output_id: string}
     */
    private function applyV3CalculateDistanceToMoscowAction(Message $message, array $statePayload, ?string $blockId): array
    {
        $outputId = self::V3_DISTANCE_TO_MOSCOW_OUTPUT_FAILED;
        $status = Contact::DISTANCE_TO_MOSCOW_STATUS_FAILED;
        $distanceKm = null;
        $calculatedAt = null;
        $contactId = null;

        try {
            $messageContactId = is_numeric($message->contact_id)
                ? (int) $message->contact_id
                : ($message->contact instanceof Contact ? (int) $message->contact->getKey() : null);
            $rootContact = $messageContactId !== null && $messageContactId > 0
                ? $this->resolveRootContactAction->handle($messageContactId)
                : null;

            if (! $rootContact instanceof Contact) {
                Log::info('scenario.v3_distance_to_moscow_missing_contact', [
                    'scenario_code' => $this->code(),
                    'dialog_id' => $message->dialog_id,
                    'message_id' => $message->id,
                    'block_id' => $blockId,
                ]);
            } else {
                $contactId = $rootContact->id;
                $updated = $this->syncContactDistanceToMoscowAction->handle($rootContact)->fresh();

                if ($updated instanceof Contact) {
                    $status = (string) ($updated->distance_to_moscow_status ?: Contact::DISTANCE_TO_MOSCOW_STATUS_FAILED);
                    $distanceKm = $updated->distance_to_moscow_km;
                    $calculatedAt = $updated->distance_to_moscow_calculated_at?->toISOString();
                }
            }
        } catch (Throwable $throwable) {
            Log::warning('scenario.v3_distance_to_moscow_action_failed', [
                'scenario_code' => $this->code(),
                'dialog_id' => $message->dialog_id,
                'message_id' => $message->id,
                'block_id' => $blockId,
                'exception_class' => $throwable::class,
                'error' => $throwable->getMessage(),
            ]);
        }

        $outputId = $this->v3DistanceToMoscowOutputId($status);

        data_set($statePayload, 'v3.distance_to_moscow.'.($blockId ?: 'unknown'), [
            'contact_id' => $contactId,
            'status' => $status,
            'distance_km' => $distanceKm,
            'calculated_at' => $calculatedAt,
            'output_id' => $outputId,
        ]);

        return [
            'state_payload' => $statePayload,
            'output_id' => $outputId,
        ];
    }

    private function v3DistanceToMoscowOutputId(string $status): string
    {
        return match ($status) {
            Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED => self::V3_DISTANCE_TO_MOSCOW_OUTPUT_RESOLVED,
            Contact::DISTANCE_TO_MOSCOW_STATUS_PENDING => self::V3_DISTANCE_TO_MOSCOW_OUTPUT_PENDING,
            Contact::DISTANCE_TO_MOSCOW_STATUS_OUT_OF_SCOPE => self::V3_DISTANCE_TO_MOSCOW_OUTPUT_OUT_OF_SCOPE,
            Contact::DISTANCE_TO_MOSCOW_STATUS_UNKNOWN => self::V3_DISTANCE_TO_MOSCOW_OUTPUT_UNKNOWN,
            default => self::V3_DISTANCE_TO_MOSCOW_OUTPUT_FAILED,
        };
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array{state_payload: array<string, mixed>, output_id: string}
     */
    private function applyV3ResolveGeoCityAction(Message $message, array $action, array $block, array $statePayload, ?string $blockId): array
    {
        $attemptKey = $blockId ?: 'unknown';
        $attemptPath = "v3.geo_resolution_attempts.$attemptKey";
        $attempts = max(0, (int) data_get($statePayload, $attemptPath, 0));

        $rootContact = $message->contact instanceof Contact
            ? $this->resolveRootContactAction->handle($message->contact)
            : null;

        if (! $rootContact instanceof Contact) {
            Log::warning('scenario.v3_geo_city_missing_contact', [
                'scenario_code' => $this->code(),
                'dialog_id' => $message->dialog_id,
                'message_id' => $message->id,
                'block_id' => $blockId,
            ]);

            data_set($statePayload, $attemptPath, $attempts + 1);
            $this->v3ClearGeoCityPending($statePayload, $blockId);

            return [
                'state_payload' => $statePayload,
                'output_id' => $this->v3GeoCityOutputId(ResolveGeoCityAction::STATUS_FAILED, $block),
            ];
        }

        $dialog = $message->dialog instanceof Dialog
            ? $message->dialog
            : ($message->dialog_id !== null ? Dialog::query()->find($message->dialog_id) : null);

        if (($action['source'] ?? 'current_inbound_message') === 'ai_data') {
            return $this->applyV3ResolveGeoCityFromAiDataAction(
                message: $message,
                action: $action,
                block: $block,
                statePayload: $statePayload,
                blockId: $blockId,
                rootContact: $rootContact,
                dialog: $dialog,
                attemptPath: $attemptPath,
                attempts: $attempts,
            );
        }

        $isCurrentInboundMessage = $message->direction === Message::DIRECTION_INBOUND
            && $message->sent_by_type === Message::SENT_BY_TYPE_CONTACT;
        $text = trim((string) ($message->text ?? ''));

        if (! $isCurrentInboundMessage || $text === '') {
            $reason = ! $isCurrentInboundMessage ? 'missing_current_inbound_message' : 'empty_text';
            $result = [
                'status' => ResolveGeoCityAction::STATUS_NOT_FOUND,
                'source_text' => $text !== '' ? $text : null,
                'payload' => ['reason' => $reason],
            ];

            $this->applyGeoResolutionToContactAction->createEvent($rootContact, $result, $dialog, $message);

            data_set($statePayload, $attemptPath, $attempts + 1);
            $this->v3ClearGeoCityPending($statePayload, $blockId);

            return [
                'state_payload' => $statePayload,
                'output_id' => self::V3_GEO_CITY_OUTPUT_NOT_FOUND,
            ];
        }

        $result = $this->resolveGeoCityAction->handle($text);
        $status = (string) ($result['status'] ?? ResolveGeoCityAction::STATUS_FAILED);
        $outputId = $this->v3GeoCityOutputId($status, $block);
        $result = $this->withV3GeoCityRuntimePayload($result, 'current_inbound_message', $outputId);

        if ($outputId === self::V3_GEO_CITY_OUTPUT_FOUND) {
            $this->applyGeoResolutionToContactAction->handle($rootContact, $result, $dialog, $message);
        } else {
            $this->applyGeoResolutionToContactAction->createEvent($rootContact, $result, $dialog, $message);
        }

        $statePayload = $this->v3GeoCityStateAfterOutput(
            statePayload: $statePayload,
            attemptPath: $attemptPath,
            attempts: $attempts,
            blockId: $blockId,
            outputId: $outputId,
            result: $result,
        );

        return [
            'state_payload' => $statePayload,
            'output_id' => $outputId,
        ];
    }

    private function v3GeoCityOutputId(string $status, array $block): string
    {
        if ($status === ResolveGeoCityAction::STATUS_MATCHED_CITY) {
            return self::V3_GEO_CITY_OUTPUT_FOUND;
        }

        $legacyOutputId = self::V3_GEO_CITY_LEGACY_OUTPUTS_BY_STATUS[$status] ?? null;

        if ($legacyOutputId !== null && $this->v3BlockHasActionResultEdge($block, $legacyOutputId)) {
            return $legacyOutputId;
        }

        if (in_array($status, self::V3_GEO_CITY_MANUAL_REQUIRED_STATUSES, true)) {
            return self::V3_GEO_CITY_OUTPUT_MANUAL_REQUIRED;
        }

        return self::V3_GEO_CITY_OUTPUT_NOT_FOUND;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $statePayload
     * @return array{state_payload: array<string, mixed>, output_id: string}
     */
    private function applyV3ResolveGeoCityFromAiDataAction(
        Message $message,
        array $action,
        array $block,
        array $statePayload,
        ?string $blockId,
        Contact $rootContact,
        ?Dialog $dialog,
        string $attemptPath,
        int $attempts,
    ): array {
        $sourceBlockId = trim((string) ($action['source_block_id'] ?? ''));
        $analysis = $this->v3GeoCityAiAnalysis($statePayload, $sourceBlockId);
        $sourceBlockClientKey = trim((string) ($action['source_block_client_key'] ?? ''));
        $cityFieldKey = trim((string) ($action['city_field_key'] ?? 'geo_city'));
        $regionFieldKey = trim((string) ($action['region_field_key'] ?? 'geo_region'));
        $countryFieldKey = trim((string) ($action['country_field_key'] ?? 'geo_country'));

        if ($analysis === null) {
            $this->applyGeoResolutionToContactAction->createEvent(
                $rootContact,
                $this->v3GeoCityAiDataEarlyResult(
                    reason: 'missing_ai_data',
                    action: $action,
                    analysis: null,
                    city: null,
                    region: null,
                    country: null,
                ),
                $dialog,
                $message,
            );
            data_set($statePayload, $attemptPath, $attempts + 1);
            $this->v3ClearGeoCityPending($statePayload, $blockId);

            return [
                'state_payload' => $statePayload,
                'output_id' => self::V3_GEO_CITY_OUTPUT_NOT_FOUND,
            ];
        }

        $aiOutputId = trim((string) data_get($analysis, 'output_id', ''));

        $city = $this->v3GeoCityAiDataValue($analysis, $cityFieldKey);
        $region = $regionFieldKey !== '' ? $this->v3GeoCityAiDataValue($analysis, $regionFieldKey) : null;
        $country = $countryFieldKey !== '' ? $this->v3GeoCityAiDataValue($analysis, $countryFieldKey) : null;

        if ($aiOutputId === 'city_not_found') {
            $this->applyGeoResolutionToContactAction->createEvent(
                $rootContact,
                $this->v3GeoCityAiDataEarlyResult(
                    reason: 'ai_city_not_found',
                    action: $action,
                    analysis: $analysis,
                    city: $city,
                    region: $region,
                    country: $country,
                ),
                $dialog,
                $message,
            );
            data_set($statePayload, $attemptPath, $attempts + 1);
            $this->v3ClearGeoCityPending($statePayload, $blockId);

            return [
                'state_payload' => $statePayload,
                'output_id' => self::V3_GEO_CITY_OUTPUT_NOT_FOUND,
            ];
        }

        if ($city === null) {
            $this->applyGeoResolutionToContactAction->createEvent(
                $rootContact,
                $this->v3GeoCityAiDataEarlyResult(
                    reason: 'missing_ai_city',
                    action: $action,
                    analysis: $analysis,
                    city: $city,
                    region: $region,
                    country: $country,
                ),
                $dialog,
                $message,
            );
            data_set($statePayload, $attemptPath, $attempts + 1);
            $this->v3ClearGeoCityPending($statePayload, $blockId);

            return [
                'state_payload' => $statePayload,
                'output_id' => self::V3_GEO_CITY_OUTPUT_NOT_FOUND,
            ];
        }

        $resolverInput = $this->v3GeoCityResolverInputFromAiData($city, $region, $country);
        $geoResult = $this->resolveGeoCityAction->handle($resolverInput);
        $status = (string) ($geoResult['status'] ?? ResolveGeoCityAction::STATUS_FAILED);
        $resolverReason = data_get($geoResult, 'payload.reason');

        if ($status === ResolveGeoCityAction::STATUS_MANUAL_REQUIRED && $resolverReason === 'city_required') {
            $geoResult['status'] = ResolveGeoCityAction::STATUS_NOT_FOUND;
            data_set($geoResult, 'payload.reason', 'city_not_found');
            data_set($geoResult, 'payload.resolver_reason', 'city_required');
            $status = ResolveGeoCityAction::STATUS_NOT_FOUND;
        }

        $outputId = $this->v3GeoCityOutputId($status, $block);
        $geoResult = $this->withV3GeoCityAiDataPayload(
            result: $geoResult,
            action: $action,
            analysis: $analysis,
            city: $city,
            region: $region,
            country: $country,
            resolverInput: $resolverInput,
            outputId: $outputId,
        );

        if ($outputId === self::V3_GEO_CITY_OUTPUT_FOUND) {
            $this->applyGeoResolutionToContactAction->handle($rootContact, $geoResult, $dialog, $message);
        } else {
            $this->applyGeoResolutionToContactAction->createEvent($rootContact, $geoResult, $dialog, $message);
        }

        $statePayload = $this->v3GeoCityStateAfterOutput(
            statePayload: $statePayload,
            attemptPath: $attemptPath,
            attempts: $attempts,
            blockId: $blockId,
            outputId: $outputId,
            result: $geoResult,
        );

        Log::info('scenario.v3_geo_city_ai_data_resolved', [
            'scenario_code' => $this->code(),
            'dialog_id' => $message->dialog_id,
            'message_id' => $message->id,
            'block_id' => $blockId,
            'source_block_id' => $sourceBlockId,
            'source_block_client_key' => $sourceBlockClientKey,
            'resolver_status' => $status,
            'output_id' => $outputId,
        ]);

        return [
            'state_payload' => $statePayload,
            'output_id' => $outputId,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withV3GeoCityRuntimePayload(array $result, string $source, string $outputId): array
    {
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $status = (string) ($result['status'] ?? ResolveGeoCityAction::STATUS_FAILED);

        $result['payload'] = array_merge($payload, [
            'source' => $source,
            'resolver_status' => $status,
            'resolver_ran' => true,
            'final_output' => $outputId,
        ]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function v3GeoCityStateAfterOutput(
        array $statePayload,
        string $attemptPath,
        int $attempts,
        ?string $blockId,
        string $outputId,
        array $result,
    ): array {
        if ($outputId === self::V3_GEO_CITY_OUTPUT_FOUND) {
            data_forget($statePayload, $attemptPath);
            $this->v3ClearGeoCityPending($statePayload, $blockId);

            return $statePayload;
        }

        if ($outputId === self::V3_GEO_CITY_OUTPUT_MANUAL_REQUIRED) {
            data_set($statePayload, $attemptPath, $attempts + 1);
            $this->v3SetGeoCityPending($statePayload, $blockId, $result);

            return $statePayload;
        }

        data_set($statePayload, $attemptPath, $attempts + 1);
        $this->v3ClearGeoCityPending($statePayload, $blockId);

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function v3ClearGeoCityPending(array &$statePayload, ?string $blockId): void
    {
        data_forget($statePayload, 'v3.geo_resolution_pending.'.($blockId ?: 'unknown'));
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @param  array<string, mixed>  $result
     */
    private function v3SetGeoCityPending(array &$statePayload, ?string $blockId, array $result): void
    {
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $candidates = is_array($payload['candidates'] ?? null) ? $payload['candidates'] : [];

        if ($candidates === [] && filled($result['city'] ?? null)) {
            $candidates = [[
                'city_id' => $result['city_id'] ?? null,
                'city' => $result['city'] ?? null,
                'region_id' => $result['region_id'] ?? null,
                'region' => $result['region'] ?? null,
                'country_id' => $result['country_id'] ?? null,
                'country' => $result['country'] ?? null,
                'matched_alias' => $result['matched_alias'] ?? null,
                'confidence' => $result['confidence'] ?? null,
            ]];
        }

        data_set($statePayload, 'v3.geo_resolution_pending.'.($blockId ?: 'unknown'), [
            'reason' => $payload['reason'] ?? null,
            'source' => $payload['source'] ?? 'current_inbound_message',
            'source_text' => $result['source_text'] ?? null,
            'country' => $result['country'] ?? null,
            'region' => $result['region'] ?? null,
            'city' => $result['city'] ?? null,
            'candidates' => array_slice(array_values($candidates), 0, 5),
        ]);
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>|null
     */
    private function v3GeoCityAiAnalysis(array $statePayload, string $sourceBlockId): ?array
    {
        if ($sourceBlockId === '') {
            return null;
        }

        $analysis = data_get($statePayload, "v3.ai_analysis.$sourceBlockId");

        return is_array($analysis) ? $analysis : null;
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function v3GeoCityAiDataValue(array $analysis, string $fieldKey): ?string
    {
        if ($fieldKey === '') {
            return null;
        }

        $value = data_get($analysis, "data.$fieldKey");

        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 255);
    }

    private function v3GeoCityResolverInputFromAiData(string $city, ?string $region, ?string $country): string
    {
        return collect([$city, $region, $country])
            ->filter(fn (?string $value): bool => is_string($value) && trim($value) !== '')
            ->implode(' ');
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>|null  $analysis
     * @return array<string, mixed>
     */
    private function v3GeoCityAiDataEarlyResult(
        string $reason,
        array $action,
        ?array $analysis,
        ?string $city,
        ?string $region,
        ?string $country,
    ): array {
        return [
            'status' => ResolveGeoCityAction::STATUS_NOT_FOUND,
            'source_text' => null,
            'payload' => [
                'source' => 'ai_data',
                'reason' => $reason,
                'source_block_id' => (string) ($action['source_block_id'] ?? ''),
                'source_block_client_key' => (string) ($action['source_block_client_key'] ?? ''),
                'city_field_key' => (string) ($action['city_field_key'] ?? ''),
                'region_field_key' => (string) ($action['region_field_key'] ?? ''),
                'country_field_key' => (string) ($action['country_field_key'] ?? ''),
                'ai_output_id' => is_array($analysis) ? data_get($analysis, 'output_id') : null,
                'ai_output_label' => is_array($analysis) ? data_get($analysis, 'label') : null,
                'ai_request_id' => is_array($analysis) ? data_get($analysis, 'ai_request_id') : null,
                'ai_city' => $city,
                'ai_region' => $region,
                'ai_country' => $country,
                'resolver_input' => null,
                'resolver_status' => ResolveGeoCityAction::STATUS_NOT_FOUND,
                'resolver_ran' => false,
                'final_output' => self::V3_GEO_CITY_OUTPUT_NOT_FOUND,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function withV3GeoCityAiDataPayload(
        array $result,
        array $action,
        array $analysis,
        string $city,
        ?string $region,
        ?string $country,
        string $resolverInput,
        string $outputId,
    ): array {
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $status = (string) ($result['status'] ?? ResolveGeoCityAction::STATUS_FAILED);
        $reason = $payload['reason'] ?? null;

        if ($outputId === self::V3_GEO_CITY_OUTPUT_MANUAL_REQUIRED && $status !== ResolveGeoCityAction::STATUS_NOT_FOUND) {
            $payload['resolver_reason'] = $reason;
            $payload['reason'] = 'ai_unconfirmed';
        }

        $result['source_text'] = $resolverInput;
        $result['payload'] = array_merge($payload, [
            'source' => 'ai_data',
            'source_block_id' => (string) ($action['source_block_id'] ?? ''),
            'source_block_client_key' => (string) ($action['source_block_client_key'] ?? ''),
            'city_field_key' => (string) ($action['city_field_key'] ?? ''),
            'region_field_key' => (string) ($action['region_field_key'] ?? ''),
            'country_field_key' => (string) ($action['country_field_key'] ?? ''),
            'ai_output_id' => data_get($analysis, 'output_id'),
            'ai_output_label' => data_get($analysis, 'label'),
            'ai_request_id' => data_get($analysis, 'ai_request_id'),
            'ai_city' => $city,
            'ai_region' => $region,
            'ai_country' => $country,
            'resolver_input' => $resolverInput,
            'resolver_status' => $status,
            'resolver_ran' => true,
            'final_output' => $outputId,
        ]);

        return $result;
    }

    private function v3BlockHasActionResultEdge(array $block, string $outputId): bool
    {
        $edges = is_array($block['action_result_edges'] ?? null) ? $block['action_result_edges'] : [];

        return collect($edges)->contains(fn (mixed $edge): bool => is_array($edge)
            && ($edge['from_output_id'] ?? null) === $outputId);
    }

    private function applyV3WriteDialogFieldAction(Message $message, string $fieldKey, string $value): bool
    {
        if (! $this->validV3DialogVariableKey($fieldKey) || mb_strlen($value) > self::V3_DIALOG_FIELD_VALUE_MAX_LENGTH) {
            return false;
        }

        $dialog = Dialog::query()
            ->whereKey($message->dialog_id)
            ->lockForUpdate()
            ->first();

        if (! $dialog instanceof Dialog) {
            return false;
        }

        $fields = is_array($dialog->fields_payload) ? $dialog->fields_payload : [];
        $userFieldCount = $this->v3DialogUserFieldCount($fields);

        if (! array_key_exists($fieldKey, $fields) && $userFieldCount >= self::V3_DIALOG_USER_FIELDS_MAX) {
            return false;
        }

        data_set($fields, $fieldKey, $value);

        $encoded = json_encode($fields);

        if ($encoded === false || strlen($encoded) > self::V3_DIALOG_FIELDS_MAX_BYTES) {
            return false;
        }

        $dialog->forceFill(['fields_payload' => $fields])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $statePayload
     */
    private function applyV3ChangeFieldAction(
        Message $message,
        array $action,
        array $statePayload,
        ?string $blockId,
        ?ScenarioRun $run,
    ): void {
        $targetScope = (string) ($action['target_scope'] ?? '');
        $targetField = trim((string) ($action['target_field'] ?? ''));
        $valueResult = $this->v3ChangeFieldValue($action, $statePayload);

        if (($valueResult['status'] ?? null) !== 'ok') {
            $this->logV3ChangeFieldAction($message, $run, $blockId, $action, (string) ($valueResult['status'] ?? 'missing_source'));

            return;
        }

        $value = (string) ($valueResult['value'] ?? '');
        $written = match ($targetScope) {
            'dialog' => $this->applyV3WriteDialogFieldAction($message, $targetField, $value),
            'contact' => $this->applyV3ChangeContactFieldAction($message, $targetField, $value, $action, $statePayload),
            default => false,
        };

        $this->logV3ChangeFieldAction(
            $message,
            $run,
            $blockId,
            $action,
            $written ? ($value === '' ? 'cleared' : 'written') : 'validation_failed',
        );
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $statePayload
     * @return array{status: string, value?: string}
     */
    private function v3ChangeFieldValue(array $action, array $statePayload): array
    {
        $valueSource = (string) ($action['value_source'] ?? 'manual');

        if ($valueSource === 'manual') {
            return ['status' => 'ok', 'value' => (string) ($action['manual_value'] ?? '')];
        }

        if ($valueSource === 'start_parameter') {
            $value = data_get($statePayload, 'v3.entrypoint.parameter');

            return is_scalar($value) && trim((string) $value) !== ''
                ? ['status' => 'ok', 'value' => trim((string) $value)]
                : ['status' => 'missing_source'];
        }

        if ($valueSource === 'ai_result') {
            $sourceBlockId = trim((string) ($action['source_block_id'] ?? ''));
            $sourceFieldKey = trim((string) ($action['source_field_key'] ?? ''));
            $value = $sourceBlockId !== '' && $sourceFieldKey !== ''
                ? data_get($statePayload, "v3.ai_analysis.$sourceBlockId.data.$sourceFieldKey")
                : null;

            return is_scalar($value) && trim((string) $value) !== ''
                ? ['status' => 'ok', 'value' => trim((string) $value)]
                : ['status' => 'missing_source'];
        }

        return ['status' => 'validation_failed'];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $statePayload
     */
    private function applyV3ChangeContactFieldAction(
        Message $message,
        string $fieldKey,
        string $value,
        array $action,
        array $statePayload,
    ): bool {
        if (! $message->contact instanceof Contact || ! in_array($fieldKey, EngineFieldRegistry::CONTACT_CHANGE_FIELD_FIELDS, true)) {
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

        if ($fieldKey === 'first_name') {
            if ($value === '') {
                $result = $this->applyContactFirstNameAction->clear(
                    $lockedContact,
                    ApplyContactFirstNameAction::REASON_SCENARIO_CONFIRMED,
                );

                if ($result->bitrix24RelevantChanged) {
                    $this->queueBitrix24ContactSyncAction->handle($lockedContact);
                }

                return true;
            }

            $resolutionMethod = ($action['value_source'] ?? null) === 'ai_result'
                ? Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS
                : Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT;

            return $this->applyV3ContactFirstNameCapture(
                $lockedContact,
                $value,
                $resolutionMethod,
                $this->v3ActionFirstNameResolutionWriteContext(
                    $message,
                    $statePayload,
                    trim((string) ($action['source_block_id'] ?? '')),
                    trim((string) ($action['source_field_key'] ?? '')),
                ),
            );
        }

        if (in_array($fieldKey, ['last_name', 'country', 'region', 'city'], true)) {
            if (mb_strlen($value) > 255) {
                return false;
            }

            return $this->updateV3ContactAttribute($lockedContact, $fieldKey, $value === '' ? null : $value);
        }

        if ($fieldKey === 'gender') {
            return $value === ''
                ? $this->updateV3ContactAttribute($lockedContact, $fieldKey, null)
                : $this->applyV3ContactEnumCapture($lockedContact, $fieldKey, $value, Contact::genderOptions());
        }

        if ($fieldKey === 'age_range') {
            return $value === ''
                ? $this->updateV3ContactAttribute($lockedContact, $fieldKey, null)
                : $this->applyV3ContactEnumCapture($lockedContact, $fieldKey, $value, Contact::ageRangeOptions());
        }

        if ($fieldKey === 'age_years') {
            return $value === ''
                ? $this->updateV3ContactAttribute($lockedContact, $fieldKey, null)
                : $this->applyV3ContactAgeYearsCapture($lockedContact, $value);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function logV3ChangeFieldAction(
        Message $message,
        ?ScenarioRun $run,
        ?string $blockId,
        array $action,
        string $status,
    ): void {
        Log::info('scenario.v3_change_field_action', [
            'scenario_code' => $this->code(),
            'scenario_run_id' => $run?->id,
            'dialog_id' => $message->dialog_id,
            'message_id' => $message->id,
            'block_id' => $blockId,
            'status' => $status,
            'target_scope' => is_scalar($action['target_scope'] ?? null) ? (string) $action['target_scope'] : null,
            'target_field' => is_scalar($action['target_field'] ?? null) ? (string) $action['target_field'] : null,
            'value_source' => is_scalar($action['value_source'] ?? null) ? (string) $action['value_source'] : null,
            'source_block_id' => is_scalar($action['source_block_id'] ?? null) ? (string) $action['source_block_id'] : null,
            'source_field_key' => is_scalar($action['source_field_key'] ?? null) ? (string) $action['source_field_key'] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $statePayload
     * @return array{state_payload: array<string, mixed>, output_id: ?string, stop_current_execution?: bool}
     */
    private function applyV3VariablesAction(
        Message $message,
        array $action,
        array $statePayload,
        ?string $blockId,
        ?ScenarioRun $run,
    ): array {
        $operations = is_array($action['operations'] ?? null) ? $action['operations'] : [];
        $failure = null;

        try {
            $saved = DB::transaction(function () use ($message, $operations, &$failure): bool {
                $dialog = Dialog::query()
                    ->whereKey($message->dialog_id)
                    ->lockForUpdate()
                    ->first();

                if (! $dialog instanceof Dialog) {
                    $failure = ['reason' => 'dialog_not_found'];

                    return false;
                }

                $fields = is_array($dialog->fields_payload) ? $dialog->fields_payload : [];

                foreach ($operations as $index => $operation) {
                    if (! is_array($operation)) {
                        $failure = ['reason' => 'invalid_operation', 'operation_index' => $index];

                        return false;
                    }

                    $failure = $this->applyV3DialogVariableOperation($message, $fields, $operation, $index);

                    if ($failure !== null) {
                        return false;
                    }
                }

                if ($this->v3DialogUserFieldCount($fields) > self::V3_DIALOG_USER_FIELDS_MAX) {
                    $failure = ['reason' => 'too_many_fields'];

                    return false;
                }

                $encoded = json_encode($fields);

                if ($encoded === false || strlen($encoded) > self::V3_DIALOG_FIELDS_MAX_BYTES) {
                    $failure = ['reason' => 'payload_too_large'];

                    return false;
                }

                $dialog->forceFill(['fields_payload' => $fields])->save();

                return true;
            });
        } catch (Throwable $throwable) {
            Log::warning('scenario.v3_variables_action_failed', [
                'scenario_code' => $this->code(),
                'dialog_id' => $message->dialog_id,
                'message_id' => $message->id,
                'scenario_run_id' => $run?->id,
                'block_id' => $blockId,
                'reason' => 'exception',
                'exception_class' => $throwable::class,
                'error' => $throwable->getMessage(),
            ]);

            return [
                'state_payload' => $statePayload,
                'output_id' => null,
                'stop_current_execution' => true,
            ];
        }

        if (! $saved) {
            Log::info('scenario.v3_variables_action_failed', [
                'scenario_code' => $this->code(),
                'dialog_id' => $message->dialog_id,
                'message_id' => $message->id,
                'scenario_run_id' => $run?->id,
                'block_id' => $blockId,
                'reason' => is_array($failure) ? ($failure['reason'] ?? 'unknown') : 'unknown',
                'operation_index' => is_array($failure) ? ($failure['operation_index'] ?? null) : null,
                'field_key' => is_array($failure) ? ($failure['field_key'] ?? null) : null,
            ]);

            return [
                'state_payload' => $statePayload,
                'output_id' => null,
                'stop_current_execution' => true,
            ];
        }

        Log::info('scenario.v3_variables_action_done', [
            'scenario_code' => $this->code(),
            'dialog_id' => $message->dialog_id,
            'message_id' => $message->id,
            'scenario_run_id' => $run?->id,
            'block_id' => $blockId,
            'operations_count' => count($operations),
        ]);

        return ['state_payload' => $statePayload, 'output_id' => null];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>|null
     */
    private function applyV3DialogVariableOperation(Message $message, array &$fields, array $operation, int $index): ?array
    {
        $type = (string) ($operation['operation'] ?? '');
        $fieldKey = trim((string) ($operation['field_key'] ?? ''));

        if (! $this->validV3DialogVariableKey($fieldKey)) {
            return [
                'reason' => 'invalid_field_key',
                'operation_index' => $index,
                'field_key' => $fieldKey,
            ];
        }

        if ($type === 'clear') {
            unset($fields[$fieldKey]);

            return null;
        }

        if ($type === 'increment') {
            $current = $fields[$fieldKey] ?? null;

            if ($current === null || $current === '') {
                $current = 0;
            }

            if (! is_int($current) && ! is_float($current) && ! (is_string($current) && is_numeric(trim($current)))) {
                return [
                    'reason' => 'not_numeric',
                    'operation_index' => $index,
                    'field_key' => $fieldKey,
                ];
            }

            $amount = (int) ($operation['amount'] ?? 1);

            if ($amount < 1 || $amount > 100) {
                return [
                    'reason' => 'invalid_amount',
                    'operation_index' => $index,
                    'field_key' => $fieldKey,
                ];
            }

            $next = ((float) $current) + $amount;
            $fields[$fieldKey] = floor($next) === $next ? (int) $next : $next;

            return null;
        }

        if ($type === 'set') {
            $value = $this->v3DialogVariableSetValue($message, $operation);

            if (! is_scalar($value) && $value !== null) {
                return [
                    'reason' => 'invalid_value',
                    'operation_index' => $index,
                    'field_key' => $fieldKey,
                ];
            }

            if (is_string($value) && mb_strlen($value) > self::V3_DIALOG_FIELD_VALUE_MAX_LENGTH) {
                return [
                    'reason' => 'value_too_long',
                    'operation_index' => $index,
                    'field_key' => $fieldKey,
                ];
            }

            $fields[$fieldKey] = $value;

            return null;
        }

        return [
            'reason' => 'unknown_operation',
            'operation_index' => $index,
            'field_key' => $fieldKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function v3DialogVariableSetValue(Message $message, array $operation): mixed
    {
        $source = (string) ($operation['value_source'] ?? 'static_value');

        return match ($source) {
            'current_message' => trim((string) $message->text),
            'start_param' => $this->v3StartParameterFromMessage($message),
            default => $operation['value'] ?? '',
        };
    }

    private function v3StartParameterFromMessage(Message $message): string
    {
        $messageParameter = trim((string) $message->message_parameter);

        if ($messageParameter !== '') {
            return $messageParameter;
        }

        $text = trim((string) $message->text);

        if ($text === '') {
            return '';
        }

        if (preg_match('/^\/start(?:@[A-Za-z0-9_]+)?(?:\s+(.+))?$/u', $text, $matches) !== 1) {
            return '';
        }

        return trim((string) ($matches[1] ?? ''));
    }

    private function v3DialogFieldStringValue(Message $message, string $fieldKey): string
    {
        $value = $this->v3DialogReadableFieldValue($message, $fieldKey);

        return trim((string) ($value ?? ''));
    }

    private function v3DialogReadableFieldValue(Message $message, string $fieldKey): mixed
    {
        if (! $this->validV3DialogVariableKey($fieldKey)) {
            return null;
        }

        $dialog = $message->dialog instanceof Dialog
            ? $message->dialog
            : ($message->dialog_id !== null ? Dialog::query()->find($message->dialog_id) : null);

        if (! $dialog instanceof Dialog) {
            return null;
        }

        if (in_array($fieldKey, EngineFieldRegistry::readableFieldKeys(EngineFieldRegistry::ENTITY_DIALOG), true)) {
            return $this->v3DialogSystemFieldValue($dialog, $fieldKey);
        }

        $fields = is_array($dialog->fields_payload) ? $dialog->fields_payload : [];

        return $fields[$fieldKey] ?? null;
    }

    private function v3DialogSystemFieldValue(Dialog $dialog, string $fieldKey): mixed
    {
        return match ($fieldKey) {
            'phone' => $dialog->confirmed_phone_raw ?: $dialog->confirmed_phone_normalized,
            'external_username' => $dialog->currentContactIdentity?->external_username,
            'last_inbound_message_at' => $dialog->last_inbound_at,
            'last_outbound_message_at' => $dialog->last_outbound_at,
            default => $dialog->{$fieldKey} ?? null,
        };
    }

    private function v3VirtualStartParameterMessage(Message $message, string $parameter): Message
    {
        $startMessage = clone $message;
        $startMessage->forceFill([
            'text' => '/start '.$parameter,
            'message_parameter' => $parameter,
        ]);

        return $startMessage;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logV3SimulateStartParameter(
        Message $message,
        ?ScenarioRun $run,
        ?string $blockId,
        string $status,
        array $context = [],
    ): void {
        Log::info('scenario.v3_simulate_start_parameter', [
            'scenario_code' => $this->code(),
            'scenario_run_id' => $run?->id,
            'dialog_id' => $message->dialog_id,
            'message_id' => $message->id,
            'block_id' => $blockId,
            'status' => $status,
            ...$context,
        ]);
    }

    /**
     * @param  array{source_scope?: string, source_field_key?: string, used_value?: string}|null  $clearRequest
     */
    private function clearV3SimulatedStartSourceFieldAfterReroute(
        Message $message,
        ?ScenarioRun $run,
        ?string $blockId,
        ?array $clearRequest,
    ): void {
        if ($clearRequest === null) {
            return;
        }

        $sourceScope = (string) ($clearRequest['source_scope'] ?? '');
        $sourceFieldKey = trim((string) ($clearRequest['source_field_key'] ?? ''));
        $usedValue = trim((string) ($clearRequest['used_value'] ?? ''));

        if ($sourceScope !== 'dialog') {
            $this->logV3SimulateStartParameter($message, $run, $blockId, 'source_field_clear_skipped_not_dialog_scope', [
                'source_scope' => $sourceScope,
                'source_field_key' => $sourceFieldKey,
            ]);

            return;
        }

        if (! $this->validV3DialogVariableKey($sourceFieldKey) || $usedValue === '') {
            $this->logV3SimulateStartParameter($message, $run, $blockId, 'source_field_clear_failed', [
                'source_scope' => $sourceScope,
                'source_field_key' => $sourceFieldKey,
                'reason' => 'invalid_clear_request',
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($message, $run, $blockId, $sourceFieldKey, $usedValue): void {
                $dialog = Dialog::query()
                    ->whereKey($message->dialog_id)
                    ->lockForUpdate()
                    ->first();

                if (! $dialog instanceof Dialog) {
                    $this->logV3SimulateStartParameter($message, $run, $blockId, 'source_field_clear_failed', [
                        'source_field_key' => $sourceFieldKey,
                        'reason' => 'dialog_not_found',
                    ]);

                    return;
                }

                $fields = is_array($dialog->fields_payload) ? $dialog->fields_payload : [];
                $currentValueExists = array_key_exists($sourceFieldKey, $fields);
                $currentValue = $currentValueExists ? trim((string) ($fields[$sourceFieldKey] ?? '')) : '';

                if (! $currentValueExists || $currentValue === '' || $currentValue !== $usedValue) {
                    $this->logV3SimulateStartParameter($message, $run, $blockId, 'source_field_clear_skipped_changed', [
                        'source_field_key' => $sourceFieldKey,
                        'used_value' => $usedValue,
                    ]);

                    return;
                }

                unset($fields[$sourceFieldKey]);

                $dialog->forceFill(['fields_payload' => $fields])->save();

                $this->logV3SimulateStartParameter($message, $run, $blockId, 'source_field_cleared', [
                    'source_field_key' => $sourceFieldKey,
                    'used_value' => $usedValue,
                ]);
            });
        } catch (Throwable $throwable) {
            $this->logV3SimulateStartParameter($message, $run, $blockId, 'source_field_clear_failed', [
                'source_field_key' => $sourceFieldKey,
                'reason' => 'exception',
                'error_class' => $throwable::class,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function v3DialogUserFieldCount(array $fields): int
    {
        return collect($fields)
            ->keys()
            ->filter(fn (mixed $key): bool => is_string($key) && ! str_starts_with($key, '_'))
            ->unique()
            ->count();
    }

    private function validV3DialogVariableKey(string $key): bool
    {
        if ($key === '' || mb_strlen($key) > 64) {
            return false;
        }

        if (in_array($key, ['__proto__', 'constructor', 'prototype'], true)) {
            return false;
        }

        return preg_match('/^(?!_)[\p{L}][\p{L}\p{N}_]{0,63}$/u', $key) === 1;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function v3ActionAiDataValue(array $statePayload, string $sourceBlockId, string $sourceFieldKey): mixed
    {
        if ($sourceBlockId !== '') {
            $blockValue = data_get($statePayload, "v3.ai_analysis.$sourceBlockId.data.$sourceFieldKey");

            if ($blockValue !== null && trim((string) $blockValue) !== '') {
                return $blockValue;
            }
        }

        $variableValue = data_get($statePayload, "v3.variables.$sourceFieldKey");

        if ($variableValue !== null && trim((string) $variableValue) !== '') {
            return $variableValue;
        }

        $analyses = data_get($statePayload, 'v3.ai_analysis', []);

        if (! is_array($analyses)) {
            return null;
        }

        $candidates = collect($analyses)
            ->filter(fn (mixed $analysis): bool => is_array($analysis)
                && is_array($analysis['data'] ?? null)
                && array_key_exists($sourceFieldKey, $analysis['data']))
            ->sortByDesc(fn (array $analysis): int => (int) ($analysis['message_id'] ?? 0))
            ->values();

        $latest = $candidates->first();

        return is_array($latest) ? data_get($latest, "data.$sourceFieldKey") : null;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function v3ActionFirstNameResolutionMethod(array $statePayload, string $sourceBlockId, string $sourceFieldKey): ?string
    {
        if ($sourceFieldKey === '') {
            return null;
        }

        if (
            $sourceBlockId !== ''
            && trim((string) data_get($statePayload, "v3.ai_analysis.$sourceBlockId.data.$sourceFieldKey", '')) !== ''
        ) {
            return Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS;
        }

        $variableMethod = $this->normalizeV3FirstNameResolutionMethod(
            data_get($statePayload, "v3.variable_meta.$sourceFieldKey.first_name_resolution_method"),
        );

        if ($variableMethod !== null) {
            return $variableMethod;
        }

        $analyses = data_get($statePayload, 'v3.ai_analysis', []);

        if (! is_array($analyses)) {
            return null;
        }

        $hasAiData = collect($analyses)
            ->contains(fn (mixed $analysis): bool => is_array($analysis)
                && is_array($analysis['data'] ?? null)
                && trim((string) data_get($analysis, "data.$sourceFieldKey", '')) !== '');

        return $hasAiData ? Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS : null;
    }

    private function v3ActionFirstNameResolutionWriteContext(
        Message $message,
        array $statePayload,
        string $sourceBlockId,
        string $sourceFieldKey,
    ): FirstNameResolutionWriteContext {
        $correlationId = null;
        $resolutionEventId = null;
        $aiRequestId = null;

        if ($sourceBlockId !== '') {
            $correlationId = data_get($statePayload, "v3.ai_analysis.$sourceBlockId.first_name_resolution_correlation_id");
            $resolutionEventId = data_get($statePayload, "v3.ai_analysis.$sourceBlockId.first_name_resolution_event_id");
            $aiRequestId = data_get($statePayload, "v3.ai_analysis.$sourceBlockId.ai_request_id");
        }

        if ($sourceFieldKey !== '' && $correlationId === null) {
            $correlationId = data_get($statePayload, "v3.variable_meta.$sourceFieldKey.first_name_resolution_correlation_id");
            $resolutionEventId = data_get($statePayload, "v3.variable_meta.$sourceFieldKey.first_name_resolution_event_id");
        }

        return new FirstNameResolutionWriteContext(
            correlationId: is_string($correlationId) && $correlationId !== '' ? $correlationId : null,
            dialogId: $message->dialog_id,
            channelId: $message->channel_id,
            scenarioId: $this->scenario->id,
            scenarioBlockId: $sourceBlockId !== '' ? $sourceBlockId : null,
            messageId: $message->id,
            resolutionAttemptEventId: is_numeric($resolutionEventId) ? (int) $resolutionEventId : null,
            aiRequestId: is_numeric($aiRequestId) ? (int) $aiRequestId : null,
        );
    }

    private function normalizeV3FirstNameResolutionMethod(mixed $method): ?string
    {
        if (! is_string($method) || trim($method) === '') {
            return null;
        }

        $method = trim($method);

        return in_array($method, Contact::allowedFirstNameResolutionMethods(), true) ? $method : null;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function v3BlockHasAiAnalysis(array $block): bool
    {
        return is_array($block['ai_analysis'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function v3BlockHasActionModule(array $block): bool
    {
        $actions = $block['actions'] ?? null;

        return is_array($actions) && $actions !== [];
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>|null
     */
    private function scheduleV3DelayedAiAnalysis(
        Message $message,
        ?ScenarioRun $run,
        string $blockId,
        string $outputId,
        int $delaySeconds,
        array $statePayload,
    ): ?array {
        if (! $run instanceof ScenarioRun || $message->dialog_id === null || $delaySeconds < 1) {
            return null;
        }

        if ($this->v3DelayedAiAnalysisWasAlreadyScheduled($statePayload, $blockId, (int) $message->id, $outputId)) {
            Log::info('scenario.v3_ai_analysis_delayed_duplicate_skipped', [
                'scenario_code' => $this->code(),
                'scenario_run_id' => $run->id,
                'dialog_id' => $message->dialog_id,
                'message_id' => $message->id,
                'block_id' => $blockId,
                'output_id' => $outputId,
            ]);

            return $statePayload;
        }

        $token = (string) Str::uuid();
        $scheduledFor = CarbonImmutable::now()->addSeconds($delaySeconds);

        $statePayload = $this->rememberV3DelayedAiAnalysisSchedule(
            $statePayload,
            $blockId,
            (int) $message->id,
            $outputId,
            $delaySeconds,
            $scheduledFor,
        );

        data_set($statePayload, "v3.ai_analysis_pending.$blockId", [
            'token' => $token,
            'output_id' => $outputId,
            'message_id' => (int) $message->id,
            'delay_seconds' => $delaySeconds,
            'scheduled_for' => $scheduledFor->toJSON(),
        ]);

        ProcessScenarioV3AiAnalysisJob::dispatch(
            (int) $run->id,
            (int) $message->dialog_id,
            (int) $message->id,
            $this->code(),
            (int) $this->publishedVersion->id,
            $blockId,
            $token,
        )
            ->delay($scheduledFor)
            ->afterCommit();

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function v3DelayedAiAnalysisWasAlreadyScheduled(
        array $statePayload,
        string $blockId,
        int $messageId,
        string $outputId,
    ): bool {
        $history = data_get($statePayload, "v3.ai_analysis_delayed_history.$blockId", []);

        if (! is_array($history)) {
            return false;
        }

        foreach ($history as $entry) {
            if (
                is_array($entry)
                && (int) ($entry['message_id'] ?? 0) === $messageId
                && (string) ($entry['output_id'] ?? '') === $outputId
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function rememberV3DelayedAiAnalysisSchedule(
        array $statePayload,
        string $blockId,
        int $messageId,
        string $outputId,
        int $delaySeconds,
        CarbonImmutable $scheduledFor,
    ): array {
        $history = data_get($statePayload, "v3.ai_analysis_delayed_history.$blockId", []);
        $history = is_array($history) ? array_values($history) : [];

        $history[] = [
            'message_id' => $messageId,
            'output_id' => $outputId,
            'delay_seconds' => $delaySeconds,
            'scheduled_for' => $scheduledFor->toJSON(),
        ];

        data_set($statePayload, "v3.ai_analysis_delayed_history.$blockId", array_slice($history, -20));

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function clearV3AiAnalysisPending(array $statePayload, string $blockId): array
    {
        data_forget($statePayload, "v3.ai_analysis_pending.$blockId");

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function scheduleV3AiAnalysisRetry(
        Message $message,
        ?ScenarioRun $run,
        string $blockId,
        array $statePayload,
        int $cycle,
        ?int $aiRequestId,
        ?int $lastAttemptId,
        string $reason,
    ): array {
        if (! $run instanceof ScenarioRun || $message->dialog_id === null) {
            return $statePayload;
        }

        $cycle = max(2, min(self::V3_AI_RETRY_MAX_CYCLES, $cycle));
        $delaySeconds = self::V3_AI_RETRY_BACKOFF_SECONDS[$cycle] ?? null;

        if ($delaySeconds === null) {
            return $statePayload;
        }

        $token = (string) Str::uuid();
        $scheduledFor = CarbonImmutable::now()->addSeconds($delaySeconds);

        data_set($statePayload, "v3.ai_analysis_retry.$blockId", [
            'token' => $token,
            'cycle' => $cycle,
            'max_cycles' => self::V3_AI_RETRY_MAX_CYCLES,
            'ai_request_id' => $aiRequestId,
            'last_attempt_id' => $lastAttemptId,
            'message_id' => (int) $message->id,
            'reason' => $reason,
            'scheduled_for' => $scheduledFor->toJSON(),
        ]);

        RetryScenarioV3AiAnalysisJob::dispatch(
            (int) $run->id,
            (int) $message->dialog_id,
            (int) $message->id,
            $this->code(),
            (int) $this->publishedVersion->id,
            $blockId,
            $token,
            $cycle,
        )
            ->delay($scheduledFor)
            ->afterCommit();

        Log::info('scenario.v3_ai_analysis_retry_scheduled', [
            'scenario_code' => $this->code(),
            'scenario_run_id' => $run->id,
            'dialog_id' => $message->dialog_id,
            'message_id' => $message->id,
            'block_id' => $blockId,
            'cycle' => $cycle,
            'delay_seconds' => $delaySeconds,
            'ai_request_id' => $aiRequestId,
            'reason' => $reason,
        ]);

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function clearV3AiAnalysisRetry(array $statePayload, string $blockId): array
    {
        data_forget($statePayload, "v3.ai_analysis_retry.$blockId");

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function cancelV3AiAnalysisRetry(array $statePayload, string $blockId, string $reason): array
    {
        $retryState = data_get($statePayload, "v3.ai_analysis_retry.$blockId", []);
        $retryState = is_array($retryState) ? $retryState : [];
        $aiRequestId = is_numeric($retryState['ai_request_id'] ?? null) ? (int) $retryState['ai_request_id'] : null;

        if ($aiRequestId !== null) {
            $this->aiRequestAnalyticsService->markCancelled(AiRequest::query()->find($aiRequestId), $reason);
        }

        return $this->clearV3AiAnalysisRetry($statePayload, $blockId);
    }

    private function v3HasNewerInboundMessage(Message $message): bool
    {
        if ($message->dialog_id === null || ! is_numeric($message->id)) {
            return false;
        }

        return Message::query()
            ->where('dialog_id', (int) $message->dialog_id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->where('id', '>', (int) $message->id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array{status?: string, output_id: string, label: string, delay_seconds: int, data: array<string, mixed>, ai_request_id?: ?int, last_attempt_id?: ?int, first_name_resolution_event_id?: ?int, first_name_resolution_correlation_id?: ?string, error?: bool, error_reason?: ?string, next_cycle?: int}
     */
    private function v3AiAnalysisResult(Message $message, array $analysis, array $statePayload, string $blockId): array
    {
        $outputs = $this->v3AiAnalysisOutputs($analysis);
        $extractFields = $this->v3AiAnalysisExtractFields($analysis);

        if ($outputs === []) {
            return [
                'output_id' => self::V3_AI_FAILED_OUTPUT_ID,
                'label' => 'Ошибка ИИ',
                'delay_seconds' => 0,
                'data' => [],
                'error' => true,
                'error_reason' => 'missing_outputs',
            ];
        }

        $tracksNameResolution = $this->v3AiAnalysisTracksNameResolution($message, $extractFields);
        $aiResult = null;
        $aiRequestId = null;
        $retryState = data_get($statePayload, "v3.ai_analysis_retry.$blockId", []);
        $retryState = is_array($retryState) ? $retryState : [];
        $cycle = max(1, min(self::V3_AI_RETRY_MAX_CYCLES, (int) ($retryState['cycle'] ?? 1)));

        try {
            $systemPrompt = $this->v3AiAnalysisSystemPrompt($message, $analysis, $outputs, $extractFields, $statePayload, $blockId);
            $userPrompt = $this->v3AiAnalysisUserPrompt($message, $analysis, $statePayload, $blockId);
            $aiContext = $this->v3AiAnalysisContext($message, $blockId);

            if ($aiContext instanceof AiGenerationContext) {
                $existingAiRequest = is_numeric($retryState['ai_request_id'] ?? null)
                    ? AiRequest::query()->find((int) $retryState['ai_request_id'])
                    : null;
                $cycleResult = $this->aiStructuredGenerationService->generateStructuredV3Cycle(
                    $systemPrompt,
                    $userPrompt,
                    $this->v3AiAnalysisSchema($outputs, $extractFields),
                    $aiContext,
                    $existingAiRequest instanceof AiRequest ? $existingAiRequest : null,
                    function (array $payload) use ($outputs): void {
                        $this->validateV3AiAnalysisResponseOutput($payload, $outputs);
                    },
                );

                $aiRequestId = is_numeric($cycleResult['ai_request_id'] ?? null) ? (int) $cycleResult['ai_request_id'] : null;

                if (($cycleResult['status'] ?? null) === 'temporary_failed') {
                    if ($cycle < self::V3_AI_RETRY_MAX_CYCLES) {
                        return [
                            'status' => 'retry_scheduled',
                            'output_id' => '',
                            'label' => '',
                            'delay_seconds' => 0,
                            'data' => [],
                            'ai_request_id' => $aiRequestId,
                            'last_attempt_id' => is_numeric($cycleResult['last_attempt_id'] ?? null) ? (int) $cycleResult['last_attempt_id'] : null,
                            'next_cycle' => $cycle + 1,
                            'error' => true,
                            'error_reason' => 'temporary_provider_error',
                        ];
                    }

                    $aiRequest = $aiRequestId !== null ? AiRequest::query()->find($aiRequestId) : null;
                    $this->aiRequestAnalyticsService->finalize($aiRequest, null, false);

                    return [
                        'output_id' => self::V3_AI_FAILED_OUTPUT_ID,
                        'label' => 'Ошибка ИИ',
                        'delay_seconds' => 0,
                        'data' => [],
                        'ai_request_id' => $aiRequestId,
                        'last_attempt_id' => is_numeric($cycleResult['last_attempt_id'] ?? null) ? (int) $cycleResult['last_attempt_id'] : null,
                        'error' => true,
                        'error_reason' => 'ai_failed',
                    ];
                }

                if (($cycleResult['status'] ?? null) === 'failed') {
                    return [
                        'output_id' => self::V3_AI_FAILED_OUTPUT_ID,
                        'label' => 'Ошибка ИИ',
                        'delay_seconds' => 0,
                        'data' => [],
                        'ai_request_id' => $aiRequestId,
                        'last_attempt_id' => is_numeric($cycleResult['last_attempt_id'] ?? null) ? (int) $cycleResult['last_attempt_id'] : null,
                        'error' => true,
                        'error_reason' => 'ai_failed',
                    ];
                }

                $aiResult = $cycleResult['result'] ?? null;

                if (! $aiResult instanceof AiStructuredGenerationResult) {
                    throw new RuntimeException('AI cycle did not return a structured result.');
                }

                $aiRequestId = $aiResult->aiRequestId;
                $response = $aiResult->data;
            } else {
                Log::warning('scenario.v3_ai_analysis_missing_contact', [
                    'scenario_code' => $this->code(),
                    'dialog_id' => $message->dialog_id,
                    'message_id' => $message->id,
                    'block_id' => $blockId,
                ]);

                return [
                    'output_id' => self::V3_AI_FAILED_OUTPUT_ID,
                    'label' => 'Ошибка ИИ',
                    'delay_seconds' => 0,
                    'data' => [],
                    'error' => true,
                    'error_reason' => 'missing_context',
                ];
            }
        } catch (Throwable $throwable) {
            Log::warning('scenario.v3_ai_analysis_failed', [
                'scenario_code' => $this->code(),
                'dialog_id' => $message->dialog_id,
                'message_id' => $message->id,
                'exception' => get_class($throwable),
                'error_message' => 'Не удалось выполнить ИИ-анализ V3-сценария.',
            ]);

            if ($throwable instanceof AiStructuredGenerationException) {
                $aiRequestId = $throwable->aiRequestId;
            }

            if ($tracksNameResolution && $throwable instanceof AiStructuredGenerationException) {
                $this->recordV3AiNameResolutionError($message, $blockId, $aiRequestId);
            }

            return [
                'output_id' => self::V3_AI_FAILED_OUTPUT_ID,
                'label' => 'Ошибка ИИ',
                'delay_seconds' => 0,
                'data' => [],
                'ai_request_id' => $aiRequestId,
                'error' => true,
                'error_reason' => 'ai_failed',
            ];
        }

        $outputId = trim((string) ($response['output_id'] ?? ''));
        $output = collect($outputs)
            ->first(fn (array $candidate): bool => ($candidate['choice_id'] ?? null) === $outputId
                || ($candidate['id'] ?? null) === $outputId);

        if (! is_array($output)) {
            return [
                'output_id' => self::V3_AI_FAILED_OUTPUT_ID,
                'label' => 'Ошибка ИИ',
                'delay_seconds' => 0,
                'data' => [],
                'ai_request_id' => $aiRequestId,
                'error' => true,
                'error_reason' => 'unknown_output',
            ];
        }

        $data = $this->v3AiAnalysisData($response['data'] ?? [], $extractFields);
        $resolutionEvent = null;

        if ($tracksNameResolution && $aiResult !== null) {
            $resolutionEvent = $this->recordV3AiNameResolutionResult(
                message: $message,
                blockId: $blockId,
                outputId: (string) $output['id'],
                data: $data,
                aiRequestId: $aiRequestId,
            );
        }

        return [
            'output_id' => (string) $output['id'],
            'label' => (string) $output['label'],
            'delay_seconds' => max(0, min(300, (int) ($output['delay_seconds'] ?? 0))),
            'data' => $data,
            'ai_request_id' => $aiRequestId,
            'first_name_resolution_event_id' => $resolutionEvent?->id,
            'first_name_resolution_correlation_id' => $resolutionEvent?->correlation_id,
            'error' => false,
            'error_reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{id: string, choice_id: string, label: string, delay_seconds: int}>  $outputs
     */
    private function validateV3AiAnalysisResponseOutput(array $payload, array $outputs): void
    {
        $outputId = trim((string) ($payload['output_id'] ?? ''));
        $output = collect($outputs)
            ->first(fn (array $candidate): bool => ($candidate['choice_id'] ?? null) === $outputId
                || ($candidate['id'] ?? null) === $outputId);

        if (is_array($output)) {
            return;
        }

        Log::warning('scenario.v3_ai_analysis_unknown_output', [
            'scenario_code' => $this->code(),
            'output_id' => $outputId,
        ]);

        throw new RuntimeException("AI analysis returned unknown output_id [{$outputId}].");
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return list<array{id: string, choice_id: string, label: string, delay_seconds: int}>
     */
    private function v3AiAnalysisOutputs(array $analysis): array
    {
        $outputs = is_array($analysis['outputs'] ?? null) ? $analysis['outputs'] : [];

        return collect($outputs)
            ->filter(fn (mixed $output): bool => is_array($output)
                && filled($output['id'] ?? null)
                && ($output['id'] ?? null) !== self::V3_AI_FAILED_OUTPUT_ID
                && filled($output['label'] ?? null))
            ->values()
            ->map(fn (array $output, int $index): array => [
                'id' => (string) $output['id'],
                'choice_id' => filled($output['choice_id'] ?? null)
                    ? (string) $output['choice_id']
                    : (string) ($index + 1),
                'label' => (string) $output['label'],
                'delay_seconds' => max(0, min(300, (int) ($output['delay_seconds'] ?? 0))),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return list<array{key: string, label: string, type: string}>
     */
    private function v3AiAnalysisExtractFields(array $analysis): array
    {
        $fields = is_array($analysis['extract_fields'] ?? null) ? $analysis['extract_fields'] : [];

        return collect($fields)
            ->filter(fn (mixed $field): bool => is_array($field)
                && filled($field['key'] ?? null)
                && filled($field['label'] ?? null))
            ->map(fn (array $field): array => [
                'key' => (string) $field['key'],
                'label' => (string) $field['label'],
                'type' => ($field['type'] ?? null) === 'number' ? 'number' : 'text',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{key: string, label: string, type: string}>  $extractFields
     * @return array<string, mixed>
     */
    private function v3AiAnalysisData(mixed $data, array $extractFields): array
    {
        if (! is_array($data) || $extractFields === []) {
            return [];
        }

        $result = [];

        foreach ($extractFields as $field) {
            $key = (string) $field['key'];

            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (($field['type'] ?? 'text') === 'number') {
                if (is_numeric($value)) {
                    $result[$key] = (float) $value;
                }

                continue;
            }

            $text = trim((string) $value);

            if ($text !== '') {
                if ($key === 'first_name') {
                    $text = $this->normalizeV3AiFirstNameData($text);
                }

                $result[$key] = mb_substr($text, 0, 1000);
            }
        }

        return $result;
    }

    private function normalizeV3AiFirstNameData(string $value): string
    {
        $lookup = app(LookupScenarioDataDictionaryAction::class)->handle(
            DataDictionaryEntry::DICTIONARY_NAMES,
            $value,
        );

        return $lookup['matched'] === true && filled($lookup['value'])
            ? trim((string) $lookup['value'])
            : $value;
    }

    /**
     * @param  list<array{key: string, label: string, type: string}>  $extractFields
     */
    private function v3AiAnalysisTracksNameResolution(Message $message, array $extractFields): bool
    {
        return $message->contact instanceof Contact
            && collect($extractFields)->contains(fn (array $field): bool => $field['key'] === 'first_name');
    }

    private function v3AiAnalysisContext(Message $message, string $blockId): ?AiGenerationContext
    {
        $contact = $message->contact instanceof Contact
            ? $this->resolveRootContactAction->handle($message->contact)
            : null;
        $contactId = is_numeric($contact?->id ?? $message->contact_id)
            ? (int) ($contact?->id ?? $message->contact_id)
            : 0;

        if ($contactId <= 0) {
            return null;
        }

        return new AiGenerationContext(
            taskKey: AiTask::KEY_SCENARIO_V3_AI_ANALYSIS,
            contactId: $contactId,
            dialogId: $message->dialog_id,
            channelId: $message->channel_id,
            scenarioId: $this->scenario->id,
            scenarioBlockId: $blockId,
            promptKey: 'scenario_v3_ai_analysis:'.$blockId,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordV3AiNameResolutionResult(
        Message $message,
        string $blockId,
        string $outputId,
        array $data,
        ?int $aiRequestId,
    ): ?ContactFirstNameResolutionEvent {
        if (! $message->contact instanceof Contact) {
            return null;
        }

        $contact = $this->resolveRootContactAction->handle($message->contact);
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $correlationId = (string) Str::uuid();

        return $this->firstNameResolutionAnalyticsService->recordResolutionAttempt(
            contact: $contact,
            source: ContactFirstNameResolutionEvent::SOURCE_AI,
            result: $firstName !== ''
                ? ContactFirstNameResolutionEvent::RESULT_ACCEPTED
                : ContactFirstNameResolutionEvent::RESULT_REJECTED,
            clientText: (string) ($message->text ?? ''),
            dialogId: $message->dialog_id,
            channelId: $message->channel_id,
            scenarioId: $this->scenario->id,
            scenarioBlockId: $blockId,
            messageId: $message->id,
            aiRequestId: $aiRequestId,
            foundFirstName: $firstName !== '' ? $firstName : null,
            resolvedFirstName: $firstName !== '' ? $firstName : null,
            correlationId: $correlationId,
            payload: ['output_id' => $outputId],
        );
    }

    private function recordV3AiNameResolutionError(Message $message, string $blockId, ?int $aiRequestId): void
    {
        if (! $message->contact instanceof Contact) {
            return;
        }

        $this->firstNameResolutionAnalyticsService->recordResolutionAttempt(
            contact: $this->resolveRootContactAction->handle($message->contact),
            source: ContactFirstNameResolutionEvent::SOURCE_AI,
            result: ContactFirstNameResolutionEvent::RESULT_ERROR,
            clientText: (string) ($message->text ?? ''),
            dialogId: $message->dialog_id,
            channelId: $message->channel_id,
            scenarioId: $this->scenario->id,
            scenarioBlockId: $blockId,
            messageId: $message->id,
            aiRequestId: $aiRequestId,
            correlationId: (string) Str::uuid(),
        );
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  list<array{id: string, choice_id: string, label: string, delay_seconds: int}>  $outputs
     * @param  list<array{key: string, label: string, type: string}>  $extractFields
     */
    private function v3AiAnalysisSystemPrompt(
        Message $message,
        array $analysis,
        array $outputs,
        array $extractFields,
        array $statePayload,
        string $blockId,
    ): string {
        $variants = collect($outputs)
            ->map(fn (array $output): string => sprintf('- ID %s: %s', $output['choice_id'], $output['label']))
            ->implode("\n");
        $fields = collect($extractFields)
            ->map(fn (array $field): string => sprintf(
                '- %s: %s, тип %s',
                $field['key'],
                $field['label'],
                $field['type'] === 'number' ? 'число' : 'текст',
            ))
            ->implode("\n");
        $prompt = $this->v3AiAnalysisPromptWithVariables($message, $analysis, $statePayload, $blockId);
        $fieldsSection = $fields !== ''
            ? "\nДанные, которые нужно извлечь в объект data:\n{$fields}\nЕсли значение не найдено или не подходит, верни пустую строку для текстового поля или не заполняй поле."
            : '';
        $firstNameRule = collect($extractFields)->contains(fn (array $field): bool => $field['key'] === 'first_name')
            ? "\nДля data.first_name возвращай полное имя в нормальной форме. Русские уменьшительные имена раскрывай только при однозначном соответствии; если соответствие неоднозначно, верни пустую строку."
            : '';

        return <<<TEXT
Ты анализируешь данные клиента внутри сценария.
Нужно выбрать ровно один вариант результата и вернуть только JSON по заданной схеме.
Верни только JSON по заданной схеме, без пояснений, комментариев и лишнего текста.

Промт оператора:
{$prompt}

Варианты результата. В поле output_id верни только ID выбранного варианта:
{$variants}
{$fieldsSection}{$firstNameRule}
TEXT;
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function v3AiAnalysisUserPrompt(Message $message, array $analysis, array $statePayload, string $blockId): string
    {
        if ($this->v3AiAnalysisPromptUsesInputVariables($analysis)) {
            return 'Данные для анализа уже подставлены в промт оператора.';
        }

        return "Данные для анализа:\n".$this->v3AiAnalysisText($message, $analysis, $statePayload, $blockId);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $statePayload
     */
    private function v3AiAnalysisPromptWithVariables(
        Message $message,
        array $analysis,
        array $statePayload,
        string $blockId,
    ): string {
        $prompt = trim((string) ($analysis['prompt'] ?? ''));

        if ($prompt === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([\p{L}\p{N}_.]+)\s*(?:\|\s*([^}]*?))?\s*\}\}/u',
            function (array $matches) use ($message, $statePayload, $blockId): string {
                $path = trim((string) ($matches[1] ?? ''));
                $fallback = array_key_exists(2, $matches) ? trim((string) $matches[2]) : '';
                $value = $this->v3TemplateVariableValue($message, $statePayload, $blockId, $path);

                if ($value === null || trim($value) === '') {
                    return $fallback;
                }

                return $value;
            },
            $prompt,
        );
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function v3AiAnalysisPromptUsesInputVariables(array $analysis): bool
    {
        $prompt = (string) ($analysis['prompt'] ?? '');

        return preg_match('/\{\{\s*input\./u', $prompt) === 1;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $statePayload
     */
    private function v3AiAnalysisPromptVariableValue(
        Message $message,
        array $analysis,
        array $statePayload,
        string $blockId,
        string $path,
    ): ?string {
        return $this->v3TemplateVariableValue($message, $statePayload, $blockId, $path);
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function v3TextWithVariables(Message $message, string $text, array $statePayload, string $blockId): string
    {
        if ($text === '' || ! str_contains($text, '{{')) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_.]+)\s*(?:\|\s*([^}]*?))?\s*\}\}/u',
            function (array $matches) use ($message, $statePayload, $blockId): string {
                $path = trim((string) ($matches[1] ?? ''));
                $fallback = array_key_exists(2, $matches) ? trim((string) $matches[2]) : '';
                $value = $this->v3TemplateVariableValue($message, $statePayload, $blockId, $path);

                if ($value === null || trim($value) === '') {
                    return $fallback;
                }

                return $value;
            },
            $text,
        );
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     */
    private function v3MessageTextForPayload(Message $message, array $messagePayload): string
    {
        if (($messagePayload['text_mode'] ?? 'static') !== 'by_dialog_variable') {
            return (string) ($messagePayload['text'] ?? '');
        }

        $fieldKey = trim((string) ($messagePayload['variable_key'] ?? ''));

        if (! $this->validV3DialogVariableKey($fieldKey)) {
            return (string) ($messagePayload['fallback_text'] ?? '');
        }

        $dialog = $message->dialog_id !== null
            ? Dialog::query()->find($message->dialog_id)
            : ($message->dialog instanceof Dialog ? $message->dialog : null);
        $fields = is_array($dialog?->fields_payload) ? $dialog->fields_payload : [];
        $rawValue = $fields[$fieldKey] ?? null;
        $value = $rawValue === null ? '' : trim((string) $rawValue);
        $variants = is_array($messagePayload['variable_text_variants'] ?? null)
            ? $messagePayload['variable_text_variants']
            : [];

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            if ($this->v3MessageVariableVariantMatches($value, $variant)) {
                return (string) ($variant['text'] ?? '');
            }
        }

        return (string) ($messagePayload['fallback_text'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $variant
     */
    private function v3MessageVariableVariantMatches(string $actualValue, array $variant): bool
    {
        $operator = (string) ($variant['operator'] ?? 'eq');
        $expectedValue = trim((string) ($variant['value'] ?? ''));

        if ($operator === 'eq' || $operator === '') {
            return $expectedValue === $actualValue;
        }

        if (! in_array($operator, ['gt', 'gte', 'lt', 'lte'], true)) {
            return false;
        }

        if (! is_numeric($actualValue) || ! is_numeric($expectedValue)) {
            return false;
        }

        $actual = (float) $actualValue;
        $expected = (float) $expectedValue;

        return match ($operator) {
            'gt' => $actual > $expected,
            'gte' => $actual >= $expected,
            'lt' => $actual < $expected,
            'lte' => $actual <= $expected,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function v3TemplateVariableValue(
        Message $message,
        array $statePayload,
        string $blockId,
        string $path,
    ): ?string {
        if ($path === 'input.client_messages') {
            return $this->v3InboundMessageBundleAfterPreviousBotMessage(
                $this->v3AiAnalysisBundleAnchorMessage($message, $statePayload, $blockId),
            );
        }

        if ($path === 'input.current_message') {
            return trim((string) $message->text);
        }

        if ($path === 'input.start_param') {
            return $this->v3StartParameterFromMessage($message);
        }

        if (str_starts_with($path, 'contact.')) {
            return $this->v3AiAnalysisContactPromptVariable($message, mb_substr($path, 8));
        }

        if (str_starts_with($path, 'dialog.')) {
            return $this->v3AiAnalysisDialogPromptVariable($message, mb_substr($path, 7));
        }

        if (str_starts_with($path, 'variables.')) {
            return $this->v3AiAnalysisStatePromptVariable($statePayload, mb_substr($path, 10));
        }

        if (str_starts_with($path, 'variable.')) {
            return $this->v3AiAnalysisStatePromptVariable($statePayload, mb_substr($path, 9));
        }

        return null;
    }

    private function v3AiAnalysisContactPromptVariable(Message $message, string $field): ?string
    {
        if (! in_array($field, EngineFieldRegistry::CONTACT_PROMPT_VARIABLE_FIELDS, true)) {
            return null;
        }

        $contact = $message->contact_id !== null
            ? Contact::query()->find($message->contact_id)
            : ($message->contact instanceof Contact ? $message->contact : null);

        if (! $contact instanceof Contact) {
            return null;
        }

        $contact = $this->resolveRootContactAction->handle($contact);

        if ($field === 'phone') {
            $phone = $contact->phoneNumbers()
                ->whereNotNull('phone_normalized')
                ->value('phone_normalized');

            return $phone === null ? null : trim((string) $phone);
        }

        if ($field === 'emails') {
            $email = $contact->emails()
                ->whereNotNull('email_normalized')
                ->value('email_normalized');

            return $email === null ? null : trim((string) $email);
        }

        $field = EngineFieldRegistry::resolveReadAlias(EngineFieldRegistry::ENTITY_CONTACT, $field);

        $value = $contact->getAttribute($field);

        return $value === null ? null : trim((string) $value);
    }

    private function v3AiAnalysisDialogPromptVariable(Message $message, string $field): ?string
    {
        if (! $this->validV3DialogVariableKey($field)) {
            return null;
        }

        $dialog = $message->dialog_id !== null
            ? Dialog::query()->find($message->dialog_id)
            : ($message->dialog instanceof Dialog ? $message->dialog : null);

        if (! $dialog instanceof Dialog) {
            return null;
        }

        $fields = is_array($dialog->fields_payload) ? $dialog->fields_payload : [];
        $value = $fields[$field] ?? null;

        return $value === null ? null : trim((string) $value);
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function v3AiAnalysisStatePromptVariable(array $statePayload, string $field): ?string
    {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $field)) {
            return null;
        }

        $value = data_get($statePayload, "v3.variables.$field");

        return $value === null ? null : trim((string) $value);
    }

    /**
     * @param  list<array{id: string, choice_id: string, label: string, delay_seconds: int}>  $outputs
     * @param  list<array{key: string, label: string, type: string}>  $extractFields
     * @return array<string, mixed>
     */
    private function v3AiAnalysisSchema(array $outputs, array $extractFields): array
    {
        $dataProperties = collect($extractFields)
            ->mapWithKeys(fn (array $field): array => [
                $field['key'] => [
                    'type' => $field['type'] === 'number' ? 'number' : 'string',
                ],
            ])
            ->all();

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'output_id' => [
                    'type' => 'string',
                    'enum' => collect($outputs)
                        ->pluck('choice_id')
                        ->values()
                        ->all(),
                ],
                'data' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => $dataProperties,
                ],
            ],
            'required' => ['output_id', 'data'],
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function v3AiAnalysisText(Message $message, array $analysis, array $statePayload, string $blockId): string
    {
        $source = (string) ($analysis['source'] ?? 'current_inbound_message');

        return match ($source) {
            'current_inbound_message' => trim((string) $message->text),
            'inbound_messages_after_previous_bot_message' => $this->v3InboundMessageBundleAfterPreviousBotMessage(
                $this->v3AiAnalysisBundleAnchorMessage($message, $statePayload, $blockId),
            ),
            default => trim((string) $message->text),
        };
    }

    private function v3AiAnalysisBundleAnchorMessage(Message $message, array $statePayload, string $blockId): Message
    {
        $pendingMessageId = (int) data_get($statePayload, "v3.ai_analysis_pending.$blockId.message_id", 0);

        if ($pendingMessageId < 1 || $message->dialog_id === null) {
            return $message;
        }

        $pendingMessage = Message::query()
            ->whereKey($pendingMessageId)
            ->where('dialog_id', $message->dialog_id)
            ->first();

        return $pendingMessage instanceof Message ? $pendingMessage : $message;
    }

    private function v3InboundMessageBundleAfterPreviousBotMessage(Message $message): string
    {
        if ($message->dialog_id === null) {
            return trim((string) $message->text);
        }

        $previousBotMessageId = Message::query()
            ->where('dialog_id', $message->dialog_id)
            ->where('id', '<', (int) $message->id)
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderByDesc('id')
            ->value('id');

        $messages = Message::query()
            ->where('dialog_id', $message->dialog_id)
            ->when(
                $previousBotMessageId !== null,
                fn ($query) => $query->where('id', '>', (int) $previousBotMessageId),
            )
            ->whereIn('message_kind', [
                Message::KIND_INBOUND_USER,
                Message::KIND_INBOUND_CONTACT_SHARE,
            ])
            ->orderByDesc('id')
            ->limit(self::V3_AI_MESSAGE_BUNDLE_LIMIT)
            ->get(['id', 'text', 'source_text', 'message_kind'])
            ->sortBy('id')
            ->values();

        $lines = $messages
            ->map(function (Message $bundleMessage): string {
                $text = trim((string) ($bundleMessage->text ?: $bundleMessage->source_text));

                if ($text !== '') {
                    return $text;
                }

                return $bundleMessage->message_kind === Message::KIND_INBOUND_CONTACT_SHARE
                    ? '[Клиент поделился контактом]'
                    : '';
            })
            ->filter(fn (string $text): bool => $text !== '')
            ->values()
            ->all();

        return $lines !== []
            ? implode("\n", $lines)
            : trim((string) $message->text);
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

            if (! $lockedTransition instanceof ScenarioV3ScheduledTransition) {
                return null;
            }

            $isScheduled = $lockedTransition->status === ScenarioV3ScheduledTransition::STATUS_SCHEDULED;
            $isStaleProcessing = $lockedTransition->status === ScenarioV3ScheduledTransition::STATUS_PROCESSING
                && (
                    $lockedTransition->processing_started_at === null
                    || $lockedTransition->processing_started_at->lte(now()->subSeconds(self::V3_SCHEDULED_TRANSITION_PROCESSING_TIMEOUT_SECONDS))
                );

            if (! $isScheduled && ! $isStaleProcessing) {
                return null;
            }

            if ($isScheduled && $lockedTransition->scheduled_for !== null && $lockedTransition->scheduled_for->isFuture()) {
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
        $this->withDeferredV3ScenarioMessages(function () use ($transition): void {
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

                $previousScheduledTransitionId = $this->activeV3ScheduledTransitionId;
                $this->activeV3ScheduledTransitionId = (int) $lockedTransition->id;

                try {
                    $progress = $this->advanceV3FromBlock(
                        $message,
                        $runtime,
                        $lockedTransition->target_block_id,
                        $statePayload,
                        run: $run,
                    );
                } finally {
                    $this->activeV3ScheduledTransitionId = $previousScheduledTransitionId;
                }

                $run->forceFill([
                    'status' => $progress['status'],
                    'current_step' => $progress['current_step'],
                    'state_payload' => $progress['state_payload'],
                    'exit_outcome' => $progress['exit_outcome'],
                    'finished_at' => $progress['status'] === ScenarioRun::STATUS_ACTIVE ? null : now(),
                ])->save();

                $hasPendingDelivery = ScenarioV3OutboundMessage::query()
                    ->where('scheduled_transition_id', $lockedTransition->id)
                    ->exists();

                $this->finishV3ScheduledTransition(
                    $lockedTransition,
                    $hasPendingDelivery
                        ? ScenarioV3ScheduledTransition::STATUS_DELIVERY_PENDING
                        : ScenarioV3ScheduledTransition::STATUS_PASSED,
                );
            });
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

        if (! $this->v3EntrypointAllowsDialogPhone($message, $entrypoint)) {
            return null;
        }

        $match = (string) ($entrypoint['match'] ?? 'strict');

        foreach ($entrypoint['values'] ?? [] as $value) {
            if (! $this->messageMatchesV3Value($message, $match, (string) $value)) {
                continue;
            }

            if (! $this->v3EntrypointAllowsExpression($message, $entrypoint)) {
                continue;
            }

            if (! $this->v3EntrypointAllowsTagCondition($message, $entrypoint)) {
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
    private function v3EntrypointAllowsExpression(Message $message, array $entrypoint): bool
    {
        $expression = trim((string) ($entrypoint['expression'] ?? ''));

        if ($expression === '') {
            return true;
        }

        try {
            return app(ScenarioEdgeExpressionCondition::class)->evaluate($expression, $message);
        } catch (Throwable $exception) {
            Log::warning('V3 start expression condition failed.', [
                'scenario_id' => $this->scenario->id,
                'block_id' => $entrypoint['block_id'] ?? null,
                'message_id' => $message->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     */
    private function v3EntrypointAllowsTagCondition(Message $message, array $entrypoint): bool
    {
        $condition = is_array($entrypoint['tag_condition'] ?? null) ? $entrypoint['tag_condition'] : [];

        return $this->v3AllowsTagCondition($message, $condition);
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
     * @param  array<string, mixed>  $entrypoint
     */
    private function v3EntrypointAllowsDialogPhone(Message $message, array $entrypoint): bool
    {
        $condition = trim((string) ($entrypoint['dialog_phone_condition'] ?? ''));

        return $this->v3PhoneConditionAllows($condition, $this->messageDialogHasConfirmedPhone($message));
    }

    private function v3PhoneConditionAllows(string $condition, bool $hasPhone): bool
    {
        if ($condition === '') {
            return true;
        }

        if (! in_array($condition, [
            AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
        ], true)) {
            return false;
        }

        return $condition === AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE
            ? $hasPhone
            : ! $hasPhone;
    }

    private function messageDialogHasConfirmedPhone(Message $message): bool
    {
        $dialog = $message->relationLoaded('dialog') ? $message->dialog : null;

        if ($dialog instanceof Dialog) {
            return filled($dialog->confirmed_phone_raw) || filled($dialog->confirmed_phone_normalized);
        }

        if ($message->dialog_id === null) {
            return false;
        }

        return Dialog::query()
            ->whereKey($message->dialog_id)
            ->where(function ($query): void {
                $query
                    ->where(function ($query): void {
                        $query
                            ->whereNotNull('confirmed_phone_raw')
                            ->where('confirmed_phone_raw', '!=', '');
                    })
                    ->orWhere(function ($query): void {
                        $query
                            ->whereNotNull('confirmed_phone_normalized')
                            ->where('confirmed_phone_normalized', '!=', '');
                    });
            })
            ->exists();
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
            ->sort(fn (array $left, array $right): int => $this->compareV3Entrypoints($left, $right, $runtime))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  array<string, mixed>  $runtime
     */
    private function compareV3Entrypoints(array $left, array $right, array $runtime): int
    {
        return [
            $this->v3EntrypointPriority($right),
            $this->v3EntrypointDisplayOrder($right, $runtime),
            $this->v3EntrypointDisplayId($right, $runtime),
        ] <=> [
            $this->v3EntrypointPriority($left),
            $this->v3EntrypointDisplayOrder($left, $runtime),
            $this->v3EntrypointDisplayId($left, $runtime),
        ];
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     */
    private function v3EntrypointPriority(array $entrypoint): int
    {
        return max(
            self::V3_START_PRIORITY_MIN,
            min(self::V3_START_PRIORITY_MAX, (int) ($entrypoint['priority'] ?? 10)),
        );
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     * @param  array<string, mixed>  $runtime
     */
    private function v3EntrypointDisplayOrder(array $entrypoint, array $runtime): int
    {
        $displayId = $this->v3EntrypointDisplayId($entrypoint, $runtime);

        return is_numeric($displayId) ? (int) $displayId : PHP_INT_MIN;
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     * @param  array<string, mixed>  $runtime
     */
    private function v3EntrypointDisplayId(array $entrypoint, array $runtime): string
    {
        $displayId = trim((string) ($entrypoint['display_id'] ?? $entrypoint['display_number'] ?? ''));

        if ($displayId !== '') {
            return $displayId;
        }

        $blockId = trim((string) ($entrypoint['block_id'] ?? ''));
        $block = $this->v3RuntimeBlock($runtime, $blockId);

        if (is_array($block)) {
            $displayId = trim((string) ($block['display_number'] ?? $block['card_id'] ?? $block['db_id'] ?? $block['id'] ?? ''));
        }

        return $displayId !== '' ? $displayId : $blockId;
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
        $button = $this->v3ButtonForCallback($callbackData, $block, $channel);

        if ($button === null) {
            return null;
        }

        return filled($button['target_block_id'] ?? null) ? (string) $button['target_block_id'] : null;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function v3WaitReplyTargetForButtonCallback(string $callbackData, array $block, ?Channel $channel): ?string
    {
        $button = $this->v3ButtonForCallback($callbackData, $block, $channel);

        if ($button === null) {
            return null;
        }

        $answerText = trim((string) ($button['text'] ?? ''));

        foreach ($this->v3WaitReplyEdges($block) as $edge) {
            if ($this->v3WaitReplyEdgeMatchesAnswer($edge, $answerText, $callbackData)) {
                return (string) $edge['target_block_id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $edge
     */
    private function v3WaitReplyEdgeMatchesAnswer(array $edge, string $answerText, string $callbackData): bool
    {
        $match = is_array($edge['match'] ?? null) ? $edge['match'] : [];
        $type = (string) ($match['type'] ?? 'any_inbound');

        if ($type === 'any_inbound') {
            return true;
        }

        $answerText = $this->normalizeV3ButtonText($answerText);
        $callbackData = $this->normalizeV3ButtonText($callbackData);
        $variants = collect($match['variants'] ?? [])
            ->map(fn (mixed $variant): string => $this->normalizeV3ButtonText((string) $variant))
            ->filter(fn (string $variant): bool => $variant !== '')
            ->values();

        if ($variants->isEmpty()) {
            return false;
        }

        return match ($type) {
            'contains_text' => $answerText !== ''
                && $variants->contains(fn (string $variant): bool => str_contains($answerText, $variant)),
            AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER => $answerText !== ''
                && $variants->contains($answerText),
            self::V3_MATCH_EXACT_CALLBACK => $callbackData !== ''
                && $variants->contains($callbackData),
            AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER => false,
            default => $answerText !== '' && $variants->contains($answerText),
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function v3ButtonForCallback(string $callbackData, array $block, ?Channel $channel): ?array
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

                if ((string) ($button['output_id'] ?? '') === $callback['output_id']) {
                    return $button;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $block
     */
    private function v3SelectedButtonTextForCallbackMessage(Message $message, ?array $block): ?string
    {
        if (! is_array($block) || ! $this->messageIsV3Callback($message)) {
            return null;
        }

        $callbackData = trim((string) data_get($message->raw_payload, 'callback_query.data', ''));

        if ($callbackData === '') {
            $callbackData = trim((string) $message->text);
        }

        if ($callbackData === '') {
            return null;
        }

        $button = $this->v3ButtonForCallback($callbackData, $block, $message->channel);
        $text = trim((string) ($button['text'] ?? ''));

        return $text !== '' ? $text : null;
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
            return true;
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
                        'edge_id' => filled($button['edge_id'] ?? null) ? (string) $button['edge_id'] : null,
                        'edge_key' => filled($button['edge_key'] ?? null) ? (string) $button['edge_key'] : null,
                        'edge' => is_array($button['edge'] ?? null) ? $button['edge'] : null,
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
        data_set($statePayload, 'v3.last_known_block_id', $blockId);
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
        data_set($statePayload, 'v3.last_known_block_id', $blockId);
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

    /**
     * @param  list<list<array<string, mixed>>>|null  $replyButtonRows
     */
    private function createDeferredV3OutboundMessage(
        Message $message,
        string $text,
        string $textFormat,
        bool $requestPhone,
        bool $removeTelegramKeyboard,
        ?array $replyButtonRows,
        string $buttonPlacement,
        ?string $v3CallbackBlockId,
        ?ScenarioRun $scenarioRun,
        bool $removeTelegramKeyboardBeforeMessage = false,
    ): int {
        if (! $scenarioRun instanceof ScenarioRun) {
            throw new RuntimeException("Scenario [{$this->code()}] V3 deferred message requires a scenario run.");
        }

        if ($message->dialog_id === null || $message->channel_id === null) {
            throw new RuntimeException("Scenario [{$this->code()}] V3 deferred message requires dialog and channel context.");
        }

        $outboundMessage = ScenarioV3OutboundMessage::query()->create([
            'scenario_run_id' => $scenarioRun->id,
            'dialog_id' => $message->dialog_id,
            'channel_id' => $message->channel_id,
            'inbound_message_id' => $message->id,
            'published_version_id' => $this->publishedVersion->id,
            'scheduled_transition_id' => $this->activeV3ScheduledTransitionId,
            'scenario_code' => $this->code(),
            'block_id' => $v3CallbackBlockId,
            'text' => $text,
            'text_format' => $textFormat,
            'delivery_payload' => [
                'request_phone' => $requestPhone,
                'remove_telegram_keyboard' => $removeTelegramKeyboard,
                'remove_telegram_keyboard_before_message' => $removeTelegramKeyboardBeforeMessage,
                'reply_button_rows' => $replyButtonRows,
                'button_placement' => $buttonPlacement,
                'v3_callback_block_id' => $v3CallbackBlockId,
            ],
            'status' => ScenarioV3OutboundMessage::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => now(),
        ]);

        $this->scheduleV3OutboundDeliveryJobs((int) $outboundMessage->id, $outboundMessage->available_at);

        return (int) $outboundMessage->id;
    }

    public function handleV3OutboundMessage(int|ScenarioV3OutboundMessage $outboundMessage): void
    {
        $outboundMessage = $this->claimV3OutboundMessage($outboundMessage);

        if (! $outboundMessage instanceof ScenarioV3OutboundMessage) {
            return;
        }

        $inboundMessage = $outboundMessage->inbound_message_id !== null
            ? Message::query()->with(['channel', 'contact', 'contactIdentity', 'dialog'])->find($outboundMessage->inbound_message_id)
            : null;

        if (! $inboundMessage instanceof Message) {
            $this->finishV3OutboundMessage($outboundMessage, null, 'Исходное сообщение для доставки не найдено.', retryable: false);

            return;
        }

        $payload = is_array($outboundMessage->delivery_payload) ? $outboundMessage->delivery_payload : [];

        try {
            $this->markV3OutboundExternalDeliveryStarted($outboundMessage);

            $delivery = $this->dispatchScenarioMessageNow(
                $inboundMessage,
                (string) $outboundMessage->text,
                (string) $outboundMessage->text_format,
                (bool) ($payload['request_phone'] ?? false),
                (bool) ($payload['remove_telegram_keyboard'] ?? false),
                is_array($payload['reply_button_rows'] ?? null) ? $payload['reply_button_rows'] : null,
                is_string($payload['button_placement'] ?? null) ? (string) $payload['button_placement'] : self::V3_BUTTON_PLACEMENT_AUTO,
                is_string($payload['v3_callback_block_id'] ?? null) ? (string) $payload['v3_callback_block_id'] : null,
                $outboundMessage->scenarioRun()->first(),
                (bool) ($payload['remove_telegram_keyboard_before_message'] ?? false),
            );

            $sentMessage = $delivery['sent_message'];
            $deliveryError = $delivery['error'];

            if ($sentMessage instanceof Message) {
                $this->finishV3OutboundMessage($outboundMessage, $sentMessage);

                return;
            }

            if ($delivery['delivery_accepted']) {
                $this->finishV3OutboundMessage(
                    $outboundMessage,
                    null,
                    $this->safeV3OutboundMessageErrorMessage(
                        $deliveryError instanceof Throwable ? $deliveryError->getMessage() : 'Сообщение могло уйти во внешний канал, но локальная запись не сохранилась.',
                        $outboundMessage,
                    ),
                    retryable: false,
                    uncertain: true,
                );

                Log::warning('scenario.v3_outbound_message.delivery_uncertain', [
                    'outbound_message_id' => $outboundMessage->id,
                    'scenario_code' => $outboundMessage->scenario_code,
                    'scenario_run_id' => $outboundMessage->scenario_run_id,
                    'dialog_id' => $outboundMessage->dialog_id,
                    'exception' => $deliveryError instanceof Throwable ? get_class($deliveryError) : null,
                ]);

                return;
            }

            if (! $sentMessage instanceof Message) {
                $this->finishV3OutboundMessage($outboundMessage, null, 'Канал не принял V3-сообщение.', retryable: true);

                return;
            }
        } catch (Throwable $throwable) {
            $this->finishV3OutboundMessage(
                $outboundMessage,
                null,
                $this->safeV3OutboundMessageErrorMessage($throwable->getMessage(), $outboundMessage),
                retryable: false,
                uncertain: true,
            );

            Log::warning('scenario.v3_outbound_message.exception', [
                'outbound_message_id' => $outboundMessage->id,
                'scenario_code' => $outboundMessage->scenario_code,
                'scenario_run_id' => $outboundMessage->scenario_run_id,
                'dialog_id' => $outboundMessage->dialog_id,
                'exception' => get_class($throwable),
            ]);
        }
    }

    public static function failV3OutboundMessageWithoutRuntime(ScenarioV3OutboundMessage $outboundMessage, string $errorMessage): void
    {
        $safeErrorMessage = mb_substr(trim($errorMessage), 0, 1000);

        $outboundMessage->forceFill([
            'status' => ScenarioV3OutboundMessage::STATUS_FAILED,
            'failed_at' => now(),
            'processing_started_at' => null,
            'error_message' => $safeErrorMessage,
        ])->save();

        if ($outboundMessage->scheduled_transition_id !== null) {
            ScenarioV3ScheduledTransition::query()
                ->whereKey($outboundMessage->scheduled_transition_id)
                ->where('status', ScenarioV3ScheduledTransition::STATUS_DELIVERY_PENDING)
                ->update([
                    'status' => ScenarioV3ScheduledTransition::STATUS_FAILED,
                    'finished_at' => now(),
                    'error_message' => $safeErrorMessage,
                    'updated_at' => now(),
                ]);
        }
    }

    private function claimV3OutboundMessage(int|ScenarioV3OutboundMessage $outboundMessage): ?ScenarioV3OutboundMessage
    {
        $outboundMessageId = $outboundMessage instanceof ScenarioV3OutboundMessage
            ? (int) $outboundMessage->id
            : (int) $outboundMessage;

        $staleUncertainMessage = null;
        $staleUncertainErrorMessage = 'Отправка зависла после начала внешней доставки; автоматический повтор остановлен.';

        $claimedMessage = DB::transaction(function () use ($outboundMessageId, &$staleUncertainMessage, $staleUncertainErrorMessage): ?ScenarioV3OutboundMessage {
            $lockedMessage = ScenarioV3OutboundMessage::query()
                ->whereKey($outboundMessageId)
                ->lockForUpdate()
                ->first();

            if (! $lockedMessage instanceof ScenarioV3OutboundMessage) {
                return null;
            }

            $isPending = $lockedMessage->status === ScenarioV3OutboundMessage::STATUS_PENDING
                && ($lockedMessage->available_at === null || ! $lockedMessage->available_at->isFuture());
            $isStaleProcessing = $lockedMessage->status === ScenarioV3OutboundMessage::STATUS_PROCESSING
                && (
                    $lockedMessage->processing_started_at === null
                    || $lockedMessage->processing_started_at->lte(now()->subSeconds(self::V3_OUTBOUND_PROCESSING_TIMEOUT_SECONDS))
                );

            if ($isStaleProcessing && $this->v3OutboundExternalDeliveryWasStarted($lockedMessage)) {
                $lockedMessage->forceFill([
                    'status' => ScenarioV3OutboundMessage::STATUS_FAILED_UNCERTAIN,
                    'failed_at' => now(),
                    'processing_started_at' => null,
                    'error_message' => $staleUncertainErrorMessage,
                ])->save();

                $staleUncertainMessage = $lockedMessage->fresh();

                return null;
            }

            if (! $isPending && ! $isStaleProcessing) {
                return null;
            }

            $lockedMessage->forceFill([
                'status' => ScenarioV3OutboundMessage::STATUS_PROCESSING,
                'attempts' => ((int) $lockedMessage->attempts) + 1,
                'available_at' => null,
                'processing_started_at' => now(),
            ])->save();

            return $lockedMessage->fresh();
        });

        if ($staleUncertainMessage instanceof ScenarioV3OutboundMessage) {
            $this->markV3RunDeliveryFailure($staleUncertainMessage, $staleUncertainErrorMessage);
            $this->failV3ScheduledTransitionDelivery($staleUncertainMessage, $staleUncertainErrorMessage);
        }

        return $claimedMessage;
    }

    private function finishV3OutboundMessage(
        ScenarioV3OutboundMessage $outboundMessage,
        ?Message $sentMessage,
        ?string $errorMessage = null,
        bool $retryable = false,
        bool $uncertain = false,
    ): void {
        $outboundMessage->refresh();

        if ($sentMessage instanceof Message) {
            $outboundMessage->forceFill([
                'status' => ScenarioV3OutboundMessage::STATUS_SENT,
                'outbound_message_id' => $sentMessage->id,
                'sent_at' => now(),
                'processing_started_at' => null,
                'failed_at' => null,
                'error_message' => null,
            ])->save();

            $this->clearV3RunDeliveryFailure($outboundMessage);
            $this->completeV3ScheduledTransitionDeliveryIfReady($outboundMessage);

            return;
        }

        $safeErrorMessage = $this->safeV3OutboundMessageErrorMessage($errorMessage, $outboundMessage);
        $hasAttemptsLeft = ! $uncertain
            && $retryable
            && (int) $outboundMessage->attempts < self::V3_OUTBOUND_MAX_ATTEMPTS;

        if ($hasAttemptsLeft) {
            $availableAt = now()->addSeconds($this->v3OutboundRetryBackoffSeconds((int) $outboundMessage->attempts));

            $outboundMessage->forceFill([
                'status' => ScenarioV3OutboundMessage::STATUS_PENDING,
                'available_at' => $availableAt,
                'processing_started_at' => null,
                'delivery_payload' => $this->v3OutboundDeliveryPayloadWithoutExternalStartedFlag($outboundMessage),
                'error_message' => $safeErrorMessage,
            ])->save();

            $this->scheduleV3OutboundDeliveryJobs((int) $outboundMessage->id, $availableAt);

            return;
        }

        $outboundMessage->forceFill([
            'status' => $uncertain
                ? ScenarioV3OutboundMessage::STATUS_FAILED_UNCERTAIN
                : ScenarioV3OutboundMessage::STATUS_FAILED,
            'failed_at' => now(),
            'processing_started_at' => null,
            'delivery_payload' => $uncertain
                ? $outboundMessage->delivery_payload
                : $this->v3OutboundDeliveryPayloadWithoutExternalStartedFlag($outboundMessage),
            'error_message' => $safeErrorMessage,
        ])->save();

        $this->markV3RunDeliveryFailure($outboundMessage, $safeErrorMessage);
        $this->failV3ScheduledTransitionDelivery($outboundMessage, $safeErrorMessage);
    }

    private function v3OutboundRetryBackoffSeconds(int $attempts): int
    {
        return self::V3_OUTBOUND_RETRY_BACKOFF_SECONDS[max(0, min(
            $attempts - 1,
            count(self::V3_OUTBOUND_RETRY_BACKOFF_SECONDS) - 1,
        ))];
    }

    private function scheduleV3OutboundDeliveryJobs(int $outboundMessageId, mixed $availableAt): void
    {
        $this->scheduleV3OutboundMessageJob($outboundMessageId, $availableAt);

        $watchdogAt = $availableAt instanceof \DateTimeInterface
            ? (clone $availableAt)->modify('+'.self::V3_OUTBOUND_PROCESSING_TIMEOUT_SECONDS.' seconds')
            : now()->addSeconds(self::V3_OUTBOUND_PROCESSING_TIMEOUT_SECONDS);

        $this->scheduleV3OutboundMessageJob($outboundMessageId, $watchdogAt);
    }

    private function scheduleV3OutboundMessageJob(int $outboundMessageId, mixed $delay = null): void
    {
        $job = ProcessScenarioV3OutboundMessageJob::dispatch($outboundMessageId);

        if ($delay !== null) {
            $job->delay($delay);
        }

        $job->afterCommit();
    }

    private function markV3OutboundExternalDeliveryStarted(ScenarioV3OutboundMessage $outboundMessage): void
    {
        $payload = is_array($outboundMessage->delivery_payload) ? $outboundMessage->delivery_payload : [];
        $payload['external_delivery_started_at'] = now()->toJSON();

        $outboundMessage->forceFill([
            'delivery_payload' => $payload,
        ])->save();
    }

    private function v3OutboundExternalDeliveryWasStarted(ScenarioV3OutboundMessage $outboundMessage): bool
    {
        return filled(data_get($outboundMessage->delivery_payload, 'external_delivery_started_at'));
    }

    /**
     * @return array<string, mixed>
     */
    private function v3OutboundDeliveryPayloadWithoutExternalStartedFlag(ScenarioV3OutboundMessage $outboundMessage): array
    {
        $payload = is_array($outboundMessage->delivery_payload) ? $outboundMessage->delivery_payload : [];
        unset($payload['external_delivery_started_at']);

        return $payload;
    }

    private function completeV3ScheduledTransitionDeliveryIfReady(ScenarioV3OutboundMessage $outboundMessage): void
    {
        if ($outboundMessage->scheduled_transition_id === null) {
            return;
        }

        DB::transaction(function () use ($outboundMessage): void {
            $transition = ScenarioV3ScheduledTransition::query()
                ->whereKey($outboundMessage->scheduled_transition_id)
                ->lockForUpdate()
                ->first();

            if (
                ! $transition instanceof ScenarioV3ScheduledTransition
                || $transition->status !== ScenarioV3ScheduledTransition::STATUS_DELIVERY_PENDING
            ) {
                return;
            }

            $pendingDeliveryExists = ScenarioV3OutboundMessage::query()
                ->where('scheduled_transition_id', $transition->id)
                ->where('status', '!=', ScenarioV3OutboundMessage::STATUS_SENT)
                ->exists();

            if ($pendingDeliveryExists) {
                return;
            }

            $this->finishV3ScheduledTransition($transition, ScenarioV3ScheduledTransition::STATUS_PASSED);
        });
    }

    private function failV3ScheduledTransitionDelivery(ScenarioV3OutboundMessage $outboundMessage, ?string $errorMessage): void
    {
        if ($outboundMessage->scheduled_transition_id === null) {
            return;
        }

        DB::transaction(function () use ($outboundMessage, $errorMessage): void {
            $transition = ScenarioV3ScheduledTransition::query()
                ->whereKey($outboundMessage->scheduled_transition_id)
                ->lockForUpdate()
                ->first();

            if (
                ! $transition instanceof ScenarioV3ScheduledTransition
                || $transition->status !== ScenarioV3ScheduledTransition::STATUS_DELIVERY_PENDING
            ) {
                return;
            }

            $this->finishV3ScheduledTransition($transition, ScenarioV3ScheduledTransition::STATUS_FAILED, $errorMessage);
        });
    }

    private function markV3RunDeliveryFailure(ScenarioV3OutboundMessage $outboundMessage, ?string $errorMessage): void
    {
        $run = ScenarioRun::query()->find($outboundMessage->scenario_run_id);

        if (! $run instanceof ScenarioRun) {
            return;
        }

        $statePayload = $this->v3StatePayload($run->state_payload);
        data_set($statePayload, 'v3.delivery_error', [
            'outbound_message_id' => (int) $outboundMessage->id,
            'message' => $errorMessage,
            'failed_at' => now()->toJSON(),
        ]);

        $run->forceFill([
            'state_payload' => $statePayload,
        ])->save();
    }

    private function clearV3RunDeliveryFailure(ScenarioV3OutboundMessage $outboundMessage): void
    {
        $run = ScenarioRun::query()->find($outboundMessage->scenario_run_id);

        if (! $run instanceof ScenarioRun) {
            return;
        }

        $statePayload = $this->v3StatePayload($run->state_payload);

        if ((int) data_get($statePayload, 'v3.delivery_error.outbound_message_id') !== (int) $outboundMessage->id) {
            return;
        }

        data_forget($statePayload, 'v3.delivery_error');

        $run->forceFill([
            'state_payload' => $statePayload,
        ])->save();
    }

    private function safeV3OutboundMessageErrorMessage(
        ?string $message,
        ScenarioV3OutboundMessage $outboundMessage,
    ): ?string {
        if (! filled($message)) {
            return null;
        }

        $safeMessage = trim((string) $message);
        $channel = $outboundMessage->channel()->first();

        foreach ([$channel?->getToken(), $channel?->getWebhookSecret()] as $secret) {
            if (filled($secret)) {
                $safeMessage = str_replace((string) $secret, '[secret]', $safeMessage);
            }
        }

        $safeMessage = preg_replace('/bot[0-9A-Za-z:_-]+(?=\/)/u', 'bot[secret]', $safeMessage) ?? $safeMessage;
        $safeMessage = preg_replace('/([?&](?:token|access_token|auth|secret)=)[^&\s]+/iu', '$1[secret]', $safeMessage) ?? $safeMessage;

        return mb_substr($safeMessage, 0, 1000);
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
        ?ScenarioRun $scenarioRun = null,
        bool $removeTelegramKeyboardBeforeMessage = false,
    ): bool {
        if ($this->v3ScenarioMessageDeferralDepth > 0) {
            $this->deferredV3ScenarioOutboundMessageIds[] = $this->createDeferredV3OutboundMessage(
                $message,
                $text,
                $textFormat,
                $requestPhone,
                $removeTelegramKeyboard,
                $replyButtonRows,
                $buttonPlacement,
                $v3CallbackBlockId,
                $scenarioRun,
                $removeTelegramKeyboardBeforeMessage,
            );

            return true;
        }

        $delivery = $this->dispatchScenarioMessageNow(
            $message,
            $text,
            $textFormat,
            $requestPhone,
            $removeTelegramKeyboard,
            $replyButtonRows,
            $buttonPlacement,
            $v3CallbackBlockId,
            $scenarioRun,
            $removeTelegramKeyboardBeforeMessage,
        );

        if ($delivery['error'] instanceof Throwable) {
            throw $delivery['error'];
        }

        return $delivery['sent_message'] instanceof Message;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withDeferredV3ScenarioMessages(callable $callback): mixed
    {
        $this->v3ScenarioMessageDeferralDepth++;

        try {
            $result = $callback();
        } catch (Throwable $throwable) {
            if ($this->v3ScenarioMessageDeferralDepth === 1) {
                $this->deferredV3ScenarioOutboundMessageIds = [];
            }

            throw $throwable;
        } finally {
            $this->v3ScenarioMessageDeferralDepth--;
        }

        if ($this->v3ScenarioMessageDeferralDepth === 0) {
            $outboundMessageIds = array_values(array_unique($this->deferredV3ScenarioOutboundMessageIds));
            $this->deferredV3ScenarioOutboundMessageIds = [];
            $this->flushDeferredV3ScenarioMessages($outboundMessageIds);
        }

        return $result;
    }

    /**
     * @param  list<int>  $outboundMessageIds
     */
    private function flushDeferredV3ScenarioMessages(array $outboundMessageIds): void
    {
        foreach ($outboundMessageIds as $outboundMessageId) {
            $this->handleV3OutboundMessage($outboundMessageId);
        }
    }

    /**
     * @param  list<list<array<string, mixed>>>|null  $replyButtonRows
     * @return array{sent_message: Message|null, delivery_accepted: bool, error: Throwable|null}
     */
    private function dispatchScenarioMessageNow(
        Message $message,
        string $text,
        string $textFormat,
        bool $requestPhone = false,
        bool $removeTelegramKeyboard = false,
        ?array $replyButtonRows = null,
        string $buttonPlacement = self::V3_BUTTON_PLACEMENT_AUTO,
        ?string $v3CallbackBlockId = null,
        ?ScenarioRun $scenarioRun = null,
        bool $removeTelegramKeyboardBeforeMessage = false,
    ): array {
        $channel = $message->channel;

        if (! $channel instanceof Channel) {
            throw new RuntimeException("Scenario [{$this->code()}] message does not have an active channel.");
        }

        $content = $this->prepareMessageContentAction->handle($text, $textFormat);

        if ($this->shouldQueueV3ScenarioThroughTelegramAccountGateway($channel)) {
            return [
                'sent_message' => $this->queueTelegramAccountV3ScenarioMessage(
                    $message,
                    $content->transportText,
                    $content->textFormat,
                    $requestPhone,
                    $removeTelegramKeyboard,
                    $replyButtonRows,
                    $removeTelegramKeyboardBeforeMessage,
                ),
                'delivery_accepted' => true,
                'error' => null,
            ];
        }

        if ($removeTelegramKeyboardBeforeMessage) {
            $this->removeTelegramReplyKeyboardBeforeScenarioMessage($message, $channel);
        }

        $sendResult = $this->sendBotDialogTextAction->handleMessage(
            $message,
            $content->transportText,
            telegramReplyMarkup: $this->telegramReplyMarkup($requestPhone, $removeTelegramKeyboard, $replyButtonRows, $buttonPlacement, $v3CallbackBlockId),
            maxAttachments: $this->maxAttachments($requestPhone, $replyButtonRows, $buttonPlacement),
            textFormat: $content->textFormat,
        );

        if (! $sendResult->wasSent() || $sendResult->deliveryResult === null) {
            return [
                'sent_message' => null,
                'delivery_accepted' => false,
                'error' => null,
            ];
        }

        $outboundMessage = null;

        try {
            $outboundMessage = $this->storeOutboundScenarioMessageAction->handle(
                channel: $channel,
                inboundMessage: $message,
                deliveryResult: $sendResult->deliveryResult,
                systemCode: $this->systemCode(),
                routeDialog: $sendResult->dialog,
                content: $content,
                rawPayloadMetadata: $this->v3OutboundRawPayloadMetadata(
                    $replyButtonRows,
                    $buttonPlacement,
                    $scenarioRun,
                    $v3CallbackBlockId,
                ),
            );

            $channel->markReplySent();
        } catch (Throwable $throwable) {
            return [
                'sent_message' => $outboundMessage,
                'delivery_accepted' => true,
                'error' => $outboundMessage instanceof Message ? null : $throwable,
            ];
        }

        return [
            'sent_message' => $outboundMessage,
            'delivery_accepted' => true,
            'error' => null,
        ];
    }

    private function shouldQueueV3ScenarioThroughTelegramAccountGateway(Channel $channel): bool
    {
        return $channel->platform === Channel::PLATFORM_TELEGRAM
            && $channel->isAccountConnection();
    }

    /**
     * @param  list<list<array<string, mixed>>>|null  $replyButtonRows
     */
    private function queueTelegramAccountV3ScenarioMessage(
        Message $message,
        string $text,
        string $textFormat,
        bool $requestPhone,
        bool $removeTelegramKeyboard,
        ?array $replyButtonRows,
        bool $removeTelegramKeyboardBeforeMessage,
    ): Message {
        if (
            $requestPhone
            || $removeTelegramKeyboard
            || $removeTelegramKeyboardBeforeMessage
            || ($replyButtonRows !== null && $replyButtonRows !== [])
        ) {
            throw new RuntimeException('Telegram Account Gateway пока поддерживает только текстовые V3-сообщения без кнопок.');
        }

        if ($textFormat !== Message::TEXT_FORMAT_PLAIN_TEXT) {
            throw new RuntimeException('Telegram Account Gateway пока поддерживает только простой текст в V3-сообщениях.');
        }

        $message->loadMissing(['dialog.channel', 'dialog.currentContactIdentity']);

        if (! $message->dialog instanceof Dialog) {
            throw new RuntimeException("Scenario [{$this->code()}] message does not have an active dialog.");
        }

        return $this->queueTelegramAccountSystemReplyAction->handle(
            $message->dialog,
            $text,
            $message,
            $this->systemCode(),
            Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
            Message::SENT_BY_TYPE_SYSTEM,
            $textFormat,
        );
    }

    private function removeTelegramReplyKeyboardBeforeScenarioMessage(Message $message, Channel $channel): void
    {
        if ($channel->platform !== Channel::PLATFORM_TELEGRAM) {
            return;
        }

        $message->loadMissing(['dialog', 'contactIdentity']);

        $chatId = $message->dialog instanceof Dialog && filled($message->dialog->external_chat_id)
            ? (string) $message->dialog->external_chat_id
            : (string) $message->external_chat_id;

        if (! filled($chatId)) {
            return;
        }

        try {
            $cleanupDelivery = $this->telegramBotApiService->sendTextMessage(
                $channel,
                $chatId,
                $message->contactIdentity?->external_user_id,
                self::TELEGRAM_REPLY_KEYBOARD_CLEANUP_TEXT,
                ['remove_keyboard' => true],
                Message::TEXT_FORMAT_PLAIN_TEXT,
                disableNotification: true,
            );
        } catch (Throwable $throwable) {
            Log::warning('scenario.v3.telegram_reply_keyboard_cleanup_failed', [
                'scenario_code' => $this->code(),
                'channel_id' => $channel->id,
                'dialog_id' => $message->dialog_id,
                'exception' => get_class($throwable),
            ]);

            return;
        }

        if (! filled($cleanupDelivery->externalMessageId)) {
            return;
        }

        try {
            $this->telegramBotApiService->deleteMessage($channel, $chatId, (string) $cleanupDelivery->externalMessageId);
        } catch (Throwable $throwable) {
            Log::warning('scenario.v3.telegram_reply_keyboard_cleanup_delete_failed', [
                'scenario_code' => $this->code(),
                'channel_id' => $channel->id,
                'dialog_id' => $message->dialog_id,
                'exception' => get_class($throwable),
            ]);
        }
    }

    /**
     * @param  list<list<array<string, mixed>>>|null  $replyButtonRows
     * @return array<string, mixed>
     */
    private function v3OutboundRawPayloadMetadata(
        ?array $replyButtonRows,
        string $buttonPlacement,
        ?ScenarioRun $scenarioRun = null,
        ?string $blockId = null,
    ): array {
        $metadata = [];

        if ($scenarioRun instanceof ScenarioRun) {
            $metadata['v3'] = [
                'scenario_run_id' => (int) $scenarioRun->id,
                'scenario_code' => $this->code(),
                'published_version_id' => (int) $this->publishedVersion->id,
            ];

            if (filled($blockId)) {
                $metadata['v3']['block_id'] = (string) $blockId;
            }
        }

        if ($replyButtonRows === null || $replyButtonRows === []) {
            return $metadata;
        }

        $rows = collect($replyButtonRows)
            ->map(fn (array $row): array => collect($row)
                ->filter(fn (array $button): bool => filled($button['text'] ?? null))
                ->map(fn (array $button): array => array_filter([
                    'text' => (string) $button['text'],
                    'type' => (string) ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT),
                    'url' => ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) === self::V3_BUTTON_TYPE_LINK
                        ? (string) ($button['url'] ?? '')
                        : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''))
                ->values()
                ->all())
            ->filter(fn (array $row): bool => $row !== [])
            ->values()
            ->all();

        if ($rows === []) {
            return $metadata;
        }

        data_set($metadata, 'v3.buttons', [
            'placement' => $buttonPlacement,
            'rows' => $rows,
        ]);

        return $metadata;
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

        $contact = $this->applyScenarioTagEffectsAction->handle($message->contact, $actions);
        $this->forgetV3RootContactTagIds($message, $contact);

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
     * @return array{
     *     status: string,
     *     current_step: null,
     *     state_payload: array<string, mixed>,
     *     exit_outcome: 'completed',
     * }
     */
    private function completedV3Progress(array $statePayload): array
    {
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
     */
    private function v3PendingTelegramKeyboardRemoval(array $statePayload): bool
    {
        return (bool) data_get($statePayload, self::V3_PENDING_REMOVE_TELEGRAM_KEYBOARD_STATE_KEY, false);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function v3ShouldRemoveTelegramReplyKeyboardAfterInbound(Message $message, array $block): bool
    {
        if ($message->channel?->platform !== Channel::PLATFORM_TELEGRAM) {
            return false;
        }

        if ($message->message_kind === Message::KIND_INBOUND_CONTACT_SHARE) {
            return true;
        }

        $buttonRows = $this->v3WaitingButtonRows($block, $message->channel);

        if ($buttonRows === []) {
            return false;
        }

        return $this->v3TelegramReplyMarkupKind(false, $buttonRows, $this->v3ButtonPlacement($block)) === 'reply_keyboard';
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function markV3PendingTelegramKeyboardRemoval(array $statePayload): array
    {
        data_set($statePayload, self::V3_PENDING_REMOVE_TELEGRAM_KEYBOARD_STATE_KEY, true);

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function clearV3PendingTelegramKeyboardRemoval(array $statePayload): array
    {
        data_forget($statePayload, self::V3_PENDING_REMOVE_TELEGRAM_KEYBOARD_STATE_KEY);

        return $statePayload;
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
     * @param  list<list<array<string, mixed>>>|null  $replyButtonRows
     */
    private function v3TelegramReplyMarkupKind(
        bool $requestPhone,
        ?array $replyButtonRows,
        string $buttonPlacement = self::V3_BUTTON_PLACEMENT_AUTO,
    ): string {
        if ($requestPhone) {
            return 'reply_keyboard';
        }

        if ($replyButtonRows === null || $replyButtonRows === []) {
            return 'none';
        }

        $hasLinkButton = collect($replyButtonRows)
            ->flatten(1)
            ->contains(fn (mixed $button): bool => is_array($button)
                && ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) === self::V3_BUTTON_TYPE_LINK
                && filled($button['url'] ?? null));

        if (
            $buttonPlacement === self::V3_BUTTON_PLACEMENT_INLINE_MESSAGE
            || ($buttonPlacement === self::V3_BUTTON_PLACEMENT_AUTO && $hasLinkButton)
        ) {
            return 'inline_message';
        }

        $hasReplyKeyboardButton = collect($replyButtonRows)
            ->flatten(1)
            ->contains(fn (mixed $button): bool => is_array($button)
                && filled($button['text'] ?? null)
                && ($button['type'] ?? self::V3_BUTTON_TYPE_TEXT) !== self::V3_BUTTON_TYPE_LINK);

        return $hasReplyKeyboardButton ? 'reply_keyboard' : 'none';
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
