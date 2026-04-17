<?php

namespace App\Services\Scenarios;

use App\Data\Scenarios\ScenarioInboundResult;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ScenarioRun;
use App\Services\Bots\SendBotDialogTextAction;
use App\Services\Bots\StoreOutboundScenarioMessageAction;

class WarmupScenario implements ScenarioHandler, SupportsTelegramScenarioCallbackContinuation
{
    public const STEP_AWAITING_REACTION = 'awaiting_reaction';

    public const ACTION_POSITIVE = 'positive';

    public const ACTION_LATER = 'later';

    public const ACTION_DECLINE = 'decline';

    public static function code(): string
    {
        return 'warmup';
    }

    public function __construct(
        private readonly SendBotDialogTextAction $sendBotDialogTextAction,
        private readonly StoreOutboundScenarioMessageAction $storeOutboundScenarioMessageAction,
    ) {}

    public function shouldStart(Message $message): bool
    {
        $platform = $message->channel?->platform;

        if (
            ! in_array($platform, [Channel::PLATFORM_TELEGRAM, Channel::PLATFORM_MAX], true)
            || $message->message_kind !== Message::KIND_INBOUND_USER
            || $message->dialog_id === null
            || ! filled($message->text)
        ) {
            return false;
        }

        if ($platform === Channel::PLATFORM_TELEGRAM && is_array(data_get($message->raw_payload, 'callback_query'))) {
            return false;
        }

        if ($message->contact === null || ! $message->contact->isAutoReplyEnabled() || $message->contact->isInDataCollection()) {
            return false;
        }

        return ! ScenarioRun::query()
            ->where('dialog_id', $message->dialog_id)
            ->where('scenario_code', self::code())
            ->exists();
    }

    public function start(ScenarioRun $run, Message $message): void
    {
        $channel = $message->channel;

        if (! $channel instanceof Channel) {
            return;
        }

        $buttonLabels = $this->buttonLabelsForPlatform($channel->platform);
        $sendResult = $this->sendBotDialogTextAction->handleMessage(
            $message,
            $this->messageTextForPlatform($channel->platform),
            telegramReplyMarkup: [
                'inline_keyboard' => $this->telegramInlineKeyboard($run->id, $buttonLabels),
            ],
            maxAttachments: $this->maxAttachments($buttonLabels),
        );

        if (! $sendResult->wasSent() || $sendResult->deliveryResult === null) {
            return;
        }

        $outboundMessage = $this->storeOutboundScenarioMessageAction->handle(
            channel: $channel,
            inboundMessage: $message,
            deliveryResult: $sendResult->deliveryResult,
            systemCode: Message::SENT_BY_SYSTEM_CODE_SCENARIO_WARMUP,
            routeDialog: $sendResult->dialog,
        );

        $channel->markReplySent();

        $run->forceFill([
            'current_step' => self::STEP_AWAITING_REACTION,
            'state_payload' => [
                'trigger_message_id' => $message->id,
                'prompt_message_id' => $outboundMessage->id,
                'expected_actions' => array_keys($buttonLabels),
                'expected_labels' => $buttonLabels,
            ],
        ])->save();
    }

    public function handleInbound(ScenarioRun $run, Message $message): ScenarioInboundResult
    {
        $statePayload = is_array($run->state_payload) ? $run->state_payload : [];
        $channel = $message->channel;
        $expectedLabels = $this->expectedLabelsFromStatePayload(
            $statePayload,
            $channel instanceof Channel ? $channel->platform : null,
        );

        if ($channel?->platform === Channel::PLATFORM_MAX) {
            $matchedAction = $this->matchMaxAction($expectedLabels, $message->text);

            if ($matchedAction === null) {
                return new ScenarioInboundResult(
                    consumed: false,
                    status: ScenarioRun::STATUS_CANCELLED,
                    currentStep: null,
                    statePayload: $statePayload,
                    exitOutcome: 'interrupted_by_other_message',
                );
            }

            return new ScenarioInboundResult(
                consumed: true,
                status: ScenarioRun::STATUS_COMPLETED,
                currentStep: null,
                statePayload: array_merge($statePayload, [
                    'reaction_message_id' => $message->id,
                    'reaction_action' => $matchedAction,
                    'reaction_label' => $expectedLabels[$matchedAction],
                    'reaction_source' => 'max_text_match',
                ]),
                exitOutcome: $this->exitOutcomeForAction($matchedAction),
            );
        }

        $callback = $this->parseTelegramCallbackData((string) data_get($message->raw_payload, 'callback_query.data', ''));

        if ($callback === null) {
            return new ScenarioInboundResult(
                consumed: false,
                status: ScenarioRun::STATUS_CANCELLED,
                currentStep: null,
                statePayload: $statePayload,
                exitOutcome: 'interrupted_by_other_message',
            );
        }

        if ((int) $callback['run_id'] !== (int) $run->id) {
            return new ScenarioInboundResult(
                consumed: true,
                status: ScenarioRun::STATUS_CANCELLED,
                currentStep: null,
                statePayload: $statePayload,
                exitOutcome: 'invalid_callback_target',
            );
        }

        if (! array_key_exists($callback['action'], $expectedLabels)) {
            return new ScenarioInboundResult(
                consumed: true,
                status: ScenarioRun::STATUS_CANCELLED,
                currentStep: null,
                statePayload: $statePayload,
                exitOutcome: 'invalid_callback_action',
            );
        }

        $action = $callback['action'];

        return new ScenarioInboundResult(
            consumed: true,
            status: ScenarioRun::STATUS_COMPLETED,
            currentStep: null,
            statePayload: array_merge($statePayload, [
                'reaction_message_id' => $message->id,
                'reaction_action' => $action,
                'reaction_label' => $expectedLabels[$action],
                'reaction_source' => 'telegram_callback',
            ]),
            exitOutcome: $this->exitOutcomeForAction($action),
        );
    }

    public function supportsTelegramScenarioCallbackContinuation(ScenarioRun $run, string $callbackData): bool
    {
        $callback = $this->parseTelegramCallbackData($callbackData);

        if ($callback === null || (int) $callback['run_id'] !== (int) $run->id) {
            return false;
        }

        $statePayload = is_array($run->state_payload) ? $run->state_payload : [];
        $expectedLabels = $this->expectedLabelsFromStatePayload($statePayload, Channel::PLATFORM_TELEGRAM);

        return array_key_exists($callback['action'], $expectedLabels);
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    private function telegramInlineKeyboard(int $runId, array $buttonLabels): array
    {
        return array_map(
            fn (string $action, string $label): array => [[
                'text' => $label,
                'callback_data' => sprintf('scenario:%s:%d:%s', self::code(), $runId, $action),
            ]],
            array_keys($buttonLabels),
            array_values($buttonLabels),
        );
    }

    /**
     * @param  array<string, string>  $buttonLabels
     * @return array<int, array<string, mixed>>
     */
    private function maxAttachments(array $buttonLabels): array
    {
        $buttons = [];

        foreach ($buttonLabels as $label) {
            $buttons[] = [[
                'type' => 'message',
                'text' => $label,
            ]];
        }

        return [[
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => $buttons,
            ],
        ]];
    }

    private function messageTextForPlatform(?string $platform): string
    {
        $configPrefix = $platform === Channel::PLATFORM_MAX
            ? 'bots.scenarios.warmup.max'
            : 'bots.scenarios.warmup.telegram';

        return (string) config(
            "{$configPrefix}.text",
            'Прежде чем перейти дальше, подскажите, вам интересно получить несколько коротких материалов?'
        );
    }

    /**
     * @return array<string, string>
     */
    private function buttonLabelsForPlatform(?string $platform): array
    {
        $configPrefix = $platform === Channel::PLATFORM_MAX
            ? 'bots.scenarios.warmup.max.buttons'
            : 'bots.scenarios.warmup.telegram.buttons';

        return [
            self::ACTION_POSITIVE => (string) config("{$configPrefix}.positive", 'Да, интересно'),
            self::ACTION_LATER => (string) config("{$configPrefix}.later", 'Позже'),
            self::ACTION_DECLINE => (string) config("{$configPrefix}.decline", 'Не интересно'),
        ];
    }

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array<string, string>
     */
    private function expectedLabelsFromStatePayload(array $statePayload, ?string $platform): array
    {
        $expectedLabels = data_get($statePayload, 'expected_labels');

        if (is_array($expectedLabels)) {
            $normalizedExpectedLabels = [];

            foreach ($expectedLabels as $action => $label) {
                if (! is_string($action) || ! is_string($label) || trim($action) === '' || trim($label) === '') {
                    continue;
                }

                $normalizedExpectedLabels[trim($action)] = trim($label);
            }

            if ($normalizedExpectedLabels !== []) {
                return $normalizedExpectedLabels;
            }
        }

        return $this->buttonLabelsForPlatform($platform);
    }

    /**
     * @param  array<string, string>  $expectedLabels
     */
    private function matchMaxAction(array $expectedLabels, ?string $normalizedText): ?string
    {
        if (! filled($normalizedText)) {
            return null;
        }

        foreach ($expectedLabels as $action => $label) {
            if ($normalizedText === $label) {
                return $action;
            }
        }

        return null;
    }

    private function exitOutcomeForAction(string $action): string
    {
        return match ($action) {
            self::ACTION_POSITIVE => 'reacted_positive',
            self::ACTION_LATER => 'reacted_later',
            default => 'reacted_decline',
        };
    }

    /**
     * @return array{run_id: int, action: string}|null
     */
    private function parseTelegramCallbackData(string $value): ?array
    {
        if (! preg_match('/^scenario:warmup:(\d+):([a-z_]+)$/', trim($value), $matches)) {
            return null;
        }

        $runId = (int) ($matches[1] ?? 0);
        $action = trim((string) ($matches[2] ?? ''));

        if ($runId <= 0 || $action === '') {
            return null;
        }

        return [
            'run_id' => $runId,
            'action' => $action,
        ];
    }
}
