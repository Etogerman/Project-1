<?php

namespace App\Services\Scenarios;

use App\Data\Scenarios\ScenarioInboundResult;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Services\Bots\SendBotDialogTextAction;
use App\Services\Bots\StoreOutboundScenarioMessageAction;
use Illuminate\Support\Str;
use InvalidArgumentException;

class NeedsDiscoveryScenario implements ScenarioHandler
{
    private const PENDING_DELIVERY_STATE_KEY = 'run.pending_delivery_active';

    private const PENDING_DELIVERY_STEP_STATE_KEY = 'run.pending_delivery_step';

    private const PENDING_DELIVERY_TYPE_STATE_KEY = 'run.pending_delivery_type';

    private const PENDING_DELIVERY_TYPE_QUESTION = 'question';

    private const PENDING_DELIVERY_TYPE_COMPLETION = 'completion';

    public const STEP_PRIMARY_GOAL = 'primary_goal';

    public const STEP_MAIN_BLOCKER = 'main_blocker';

    public const OUTCOME_COMPLETED_WITH_ANSWERS = 'completed_with_answers';

    public const OUTCOME_COMPLETED_WITH_PARTIAL_ANSWERS = 'completed_with_partial_answers';

    public const OUTCOME_COMPLETED_SKIPPED = 'completed_skipped';

    public static function code(): string
    {
        return 'needs_discovery';
    }

    public function __construct(
        private readonly SendBotDialogTextAction $sendBotDialogTextAction,
        private readonly StoreOutboundScenarioMessageAction $storeOutboundScenarioMessageAction,
        private readonly ScenarioRegistry $scenarioRegistry,
    ) {}

    public function shouldStart(Message $message): bool
    {
        $platform = $message->channel?->platform;
        $contact = $message->contact;

        if (
            ! in_array($platform, [Channel::PLATFORM_TELEGRAM, Channel::PLATFORM_MAX], true)
            || $message->message_kind !== Message::KIND_INBOUND_USER
            || $message->dialog_id === null
            || ! filled($message->text)
            || ! $contact instanceof Contact
            || ! $contact->isAutoReplyEnabled()
            || $contact->isInDataCollection()
            || $contact->data_collection_status !== Contact::DATA_COLLECTION_STATUS_COMPLETED
        ) {
            return false;
        }

        if (ScenarioRun::query()
            ->where('dialog_id', $message->dialog_id)
            ->where('scenario_code', self::code())
            ->exists()) {
            return false;
        }

        return $this->warmupAllowsStart($message);
    }

    public function start(ScenarioRun $run, Message $message): void
    {
        $outboundQuestion = $this->sendScenarioMessage(
            $message,
            $this->questionForStep(self::STEP_PRIMARY_GOAL),
        );

        $statePayload = [
            'trigger_message_id' => $message->id,
            'question_message_ids' => [
                self::STEP_PRIMARY_GOAL => $outboundQuestion?->id,
                self::STEP_MAIN_BLOCKER => null,
            ],
            'completion_message_id' => null,
            'answers' => [
                self::STEP_PRIMARY_GOAL => [
                    'text' => null,
                    'message_id' => null,
                    'skipped' => false,
                ],
                self::STEP_MAIN_BLOCKER => [
                    'text' => null,
                    'message_id' => null,
                    'skipped' => false,
                ],
            ],
        ];

        if (! $outboundQuestion instanceof Message) {
            $statePayload = $this->markPendingDelivery(
                $statePayload,
                self::STEP_PRIMARY_GOAL,
                self::PENDING_DELIVERY_TYPE_QUESTION,
            );
        }

        $run->forceFill([
            'current_step' => self::STEP_PRIMARY_GOAL,
            'state_payload' => $statePayload,
        ])->save();
    }

    public function handleInbound(ScenarioRun $run, Message $message): ScenarioInboundResult
    {
        $statePayload = $this->ensureStatePayload(is_array($run->state_payload) ? $run->state_payload : []);

        if ($this->hasPendingDelivery($statePayload)) {
            return $this->resumePendingDelivery($message, $statePayload);
        }

        $normalizedText = $this->normalizeText($message->text);

        if ($normalizedText === '') {
            return new ScenarioInboundResult(
                consumed: false,
                status: ScenarioRun::STATUS_CANCELLED,
                currentStep: null,
                statePayload: $statePayload,
                exitOutcome: 'interrupted_by_other_message',
            );
        }

        return match ($run->current_step) {
            self::STEP_PRIMARY_GOAL => $this->handlePrimaryGoalStep($message, $statePayload, $normalizedText),
            self::STEP_MAIN_BLOCKER => $this->handleMainBlockerStep($message, $statePayload, $normalizedText),
            default => new ScenarioInboundResult(
                consumed: false,
                status: ScenarioRun::STATUS_CANCELLED,
                currentStep: null,
                statePayload: $statePayload,
                exitOutcome: 'unknown_step',
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function handlePrimaryGoalStep(
        Message $message,
        array $statePayload,
        string $normalizedText,
    ): ScenarioInboundResult {
        $updatedStatePayload = $this->storeAnswer(
            $statePayload,
            self::STEP_PRIMARY_GOAL,
            $message,
            $this->isSkipCommand($normalizedText),
        );

        $nextQuestion = $this->sendScenarioMessage(
            $message,
            $this->questionForStep(self::STEP_MAIN_BLOCKER),
        );

        $updatedStatePayload['question_message_ids'][self::STEP_MAIN_BLOCKER] = $nextQuestion?->id;

        if ($nextQuestion instanceof Message) {
            $updatedStatePayload = $this->clearPendingDelivery($updatedStatePayload);
        } else {
            $updatedStatePayload = $this->markPendingDelivery(
                $updatedStatePayload,
                self::STEP_MAIN_BLOCKER,
                self::PENDING_DELIVERY_TYPE_QUESTION,
            );
        }

        return new ScenarioInboundResult(
            consumed: true,
            status: ScenarioRun::STATUS_ACTIVE,
            currentStep: self::STEP_MAIN_BLOCKER,
            statePayload: $updatedStatePayload,
            exitOutcome: null,
        );
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function handleMainBlockerStep(
        Message $message,
        array $statePayload,
        string $normalizedText,
    ): ScenarioInboundResult {
        $updatedStatePayload = $this->storeAnswer(
            $statePayload,
            self::STEP_MAIN_BLOCKER,
            $message,
            $this->isSkipCommand($normalizedText),
        );

        $completionMessage = $this->sendScenarioMessage(
            $message,
            $this->completionMessage(),
        );

        $updatedStatePayload['completion_message_id'] = $completionMessage?->id;

        if (! $completionMessage instanceof Message) {
            return new ScenarioInboundResult(
                consumed: true,
                status: ScenarioRun::STATUS_ACTIVE,
                currentStep: self::STEP_MAIN_BLOCKER,
                statePayload: $this->markPendingDelivery(
                    $updatedStatePayload,
                    self::STEP_MAIN_BLOCKER,
                    self::PENDING_DELIVERY_TYPE_COMPLETION,
                ),
                exitOutcome: null,
            );
        }

        $updatedStatePayload = $this->clearPendingDelivery($updatedStatePayload);

        return new ScenarioInboundResult(
            consumed: true,
            status: ScenarioRun::STATUS_COMPLETED,
            currentStep: null,
            statePayload: $updatedStatePayload,
            exitOutcome: $this->resolveCompletionOutcome($updatedStatePayload),
        );
    }

    private function warmupAllowsStart(Message $message): bool
    {
        if (! $this->scenarioRegistry->enabledForNewStarts(WarmupScenario::code())) {
            return true;
        }

        $warmupIsBound = ScenarioChannelBinding::query()
            ->active()
            ->where('channel_id', $message->channel_id)
            ->where('scenario_code', WarmupScenario::code())
            ->exists();

        if (! $warmupIsBound) {
            return true;
        }

        $warmupRun = ScenarioRun::query()
            ->where('dialog_id', $message->dialog_id)
            ->where('scenario_code', WarmupScenario::code())
            ->latest('id')
            ->first();

        return $warmupRun instanceof ScenarioRun && ! $warmupRun->isActive();
    }

    private function sendScenarioMessage(Message $inboundMessage, string $text): ?Message
    {
        $channel = $inboundMessage->channel;

        if (! $channel instanceof Channel) {
            throw new InvalidArgumentException('Scenario inbound message does not have a channel relation.');
        }

        $sendResult = $this->sendBotDialogTextAction->handleMessage($inboundMessage, $text);

        if (! $sendResult->wasSent() || $sendResult->deliveryResult === null) {
            return null;
        }

        $outboundMessage = $this->storeOutboundScenarioMessageAction->handle(
            channel: $channel,
            inboundMessage: $inboundMessage,
            deliveryResult: $sendResult->deliveryResult,
            systemCode: Message::SENT_BY_SYSTEM_CODE_SCENARIO_NEEDS_DISCOVERY,
            routeDialog: $sendResult->dialog,
        );

        $channel->markReplySent();

        return $outboundMessage;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function ensureStatePayload(array $statePayload): array
    {
        $statePayload['question_message_ids'] = is_array($statePayload['question_message_ids'] ?? null)
            ? $statePayload['question_message_ids']
            : [];

        $statePayload['answers'] = is_array($statePayload['answers'] ?? null)
            ? $statePayload['answers']
            : [];

        foreach ([self::STEP_PRIMARY_GOAL, self::STEP_MAIN_BLOCKER] as $step) {
            if (! array_key_exists($step, $statePayload['question_message_ids'])) {
                $statePayload['question_message_ids'][$step] = null;
            }

            $answerPayload = $statePayload['answers'][$step] ?? [];
            $statePayload['answers'][$step] = [
                'text' => $answerPayload['text'] ?? null,
                'message_id' => $answerPayload['message_id'] ?? null,
                'skipped' => (bool) ($answerPayload['skipped'] ?? false),
            ];
        }

        if (! array_key_exists('completion_message_id', $statePayload)) {
            $statePayload['completion_message_id'] = null;
        }

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function hasPendingDelivery(array $statePayload): bool
    {
        return (bool) data_get($statePayload, self::PENDING_DELIVERY_STATE_KEY, false);
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function markPendingDelivery(array $statePayload, string $step, string $type): array
    {
        data_set($statePayload, self::PENDING_DELIVERY_STATE_KEY, true);
        data_set($statePayload, self::PENDING_DELIVERY_STEP_STATE_KEY, $step);
        data_set($statePayload, self::PENDING_DELIVERY_TYPE_STATE_KEY, $type);

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function clearPendingDelivery(array $statePayload): array
    {
        data_forget($statePayload, self::PENDING_DELIVERY_STATE_KEY);
        data_forget($statePayload, self::PENDING_DELIVERY_STEP_STATE_KEY);
        data_forget($statePayload, self::PENDING_DELIVERY_TYPE_STATE_KEY);

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function pendingDeliveryStep(array $statePayload): ?string
    {
        $step = data_get($statePayload, self::PENDING_DELIVERY_STEP_STATE_KEY);

        return in_array($step, [self::STEP_PRIMARY_GOAL, self::STEP_MAIN_BLOCKER], true)
            ? $step
            : null;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function pendingDeliveryType(array $statePayload): ?string
    {
        $type = data_get($statePayload, self::PENDING_DELIVERY_TYPE_STATE_KEY);

        return in_array($type, [
            self::PENDING_DELIVERY_TYPE_QUESTION,
            self::PENDING_DELIVERY_TYPE_COMPLETION,
        ], true)
            ? $type
            : null;
    }

    /**
     * @param  array<string, mixed>  $statePayload
     */
    private function resumePendingDelivery(Message $message, array $statePayload): ScenarioInboundResult
    {
        $step = $this->pendingDeliveryStep($statePayload);
        $type = $this->pendingDeliveryType($statePayload);

        if ($step === null || $type === null) {
            return new ScenarioInboundResult(
                consumed: true,
                status: ScenarioRun::STATUS_ACTIVE,
                currentStep: self::STEP_PRIMARY_GOAL,
                statePayload: $this->clearPendingDelivery($statePayload),
                exitOutcome: null,
            );
        }

        if ($type === self::PENDING_DELIVERY_TYPE_QUESTION) {
            $outboundQuestion = $this->sendScenarioMessage(
                $message,
                $this->questionForStep($step),
            );

            if (! $outboundQuestion instanceof Message) {
                return new ScenarioInboundResult(
                    consumed: true,
                    status: ScenarioRun::STATUS_ACTIVE,
                    currentStep: $step,
                    statePayload: $statePayload,
                    exitOutcome: null,
                );
            }

            $statePayload['question_message_ids'][$step] = $outboundQuestion->id;

            return new ScenarioInboundResult(
                consumed: true,
                status: ScenarioRun::STATUS_ACTIVE,
                currentStep: $step,
                statePayload: $this->clearPendingDelivery($statePayload),
                exitOutcome: null,
            );
        }

        $completionMessage = $this->sendScenarioMessage(
            $message,
            $this->completionMessage(),
        );

        if (! $completionMessage instanceof Message) {
            return new ScenarioInboundResult(
                consumed: true,
                status: ScenarioRun::STATUS_ACTIVE,
                currentStep: self::STEP_MAIN_BLOCKER,
                statePayload: $statePayload,
                exitOutcome: null,
            );
        }

        $statePayload['completion_message_id'] = $completionMessage->id;

        return new ScenarioInboundResult(
            consumed: true,
            status: ScenarioRun::STATUS_COMPLETED,
            currentStep: null,
            statePayload: $this->clearPendingDelivery($statePayload),
            exitOutcome: $this->resolveCompletionOutcome($statePayload),
        );
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, mixed>
     */
    private function storeAnswer(array $statePayload, string $step, Message $message, bool $skipped): array
    {
        $statePayload['answers'][$step] = [
            'text' => $skipped ? null : $message->text,
            'message_id' => $message->id,
            'skipped' => $skipped,
        ];

        return $statePayload;
    }

    private function resolveCompletionOutcome(array $statePayload): string
    {
        $answers = is_array($statePayload['answers'] ?? null)
            ? $statePayload['answers']
            : [];

        $skippedCount = 0;
        $answeredCount = 0;

        foreach ([self::STEP_PRIMARY_GOAL, self::STEP_MAIN_BLOCKER] as $step) {
            $answerPayload = $answers[$step] ?? [];

            if (($answerPayload['skipped'] ?? false) === true) {
                $skippedCount++;

                continue;
            }

            if (filled($answerPayload['text'] ?? null)) {
                $answeredCount++;
            }
        }

        if ($skippedCount === 2) {
            return self::OUTCOME_COMPLETED_SKIPPED;
        }

        if ($answeredCount === 2) {
            return self::OUTCOME_COMPLETED_WITH_ANSWERS;
        }

        return self::OUTCOME_COMPLETED_WITH_PARTIAL_ANSWERS;
    }

    private function questionForStep(string $step): string
    {
        return (string) config(sprintf('bots.scenarios.%s.%s.question', self::code(), $step));
    }

    private function completionMessage(): string
    {
        return (string) config(sprintf('bots.scenarios.%s.completion_message', self::code()));
    }

    private function isSkipCommand(string $normalizedText): bool
    {
        return in_array($normalizedText, $this->skipCommands(), true);
    }

    /**
     * @return list<string>
     */
    private function skipCommands(): array
    {
        $skipCommands = config(sprintf('bots.scenarios.%s.skip_commands', self::code()), []);

        if (! is_array($skipCommands)) {
            return [];
        }

        $normalized = [];

        foreach ($skipCommands as $skipCommand) {
            if (! is_string($skipCommand)) {
                continue;
            }

            $value = $this->normalizeText($skipCommand);

            if ($value === '') {
                continue;
            }

            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeText(?string $text): string
    {
        return Str::lower(trim((string) $text));
    }
}
