<?php

namespace App\Services\Scenarios;

use App\Data\Scenarios\ScenarioInboundResult;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ScenarioRun;
use App\Services\Bots\StoreOutboundScenarioMessageAction;
use App\Services\Bots\TelegramBotApiService;

class WarmupScenario implements ScenarioHandler
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
        private readonly TelegramBotApiService $telegramBotApiService,
        private readonly StoreOutboundScenarioMessageAction $storeOutboundScenarioMessageAction,
    ) {}

    public function shouldStart(Message $message): bool
    {
        if (
            $message->channel?->platform !== Channel::PLATFORM_TELEGRAM
            || $message->message_kind !== Message::KIND_INBOUND_USER
            || $message->dialog_id === null
            || ! filled($message->text)
            || is_array(data_get($message->raw_payload, 'callback_query'))
        ) {
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

        if (! $channel instanceof Channel || $channel->platform !== Channel::PLATFORM_TELEGRAM) {
            return;
        }

        $deliveryResult = $this->telegramBotApiService->sendTextMessage(
            $channel,
            $message->external_chat_id,
            $message->contactIdentity?->external_user_id,
            $this->messageText(),
            [
                'inline_keyboard' => $this->telegramInlineKeyboard($run->id),
            ],
        );

        $outboundMessage = $this->storeOutboundScenarioMessageAction->handle(
            $channel,
            $message,
            $deliveryResult,
            Message::SENT_BY_SYSTEM_CODE_SCENARIO_WARMUP,
        );

        $channel->markReplySent();

        $run->forceFill([
            'current_step' => self::STEP_AWAITING_REACTION,
            'state_payload' => [
                'trigger_message_id' => $message->id,
                'prompt_message_id' => $outboundMessage->id,
            ],
        ])->save();
    }

    public function handleInbound(ScenarioRun $run, Message $message): ScenarioInboundResult
    {
        $statePayload = is_array($run->state_payload) ? $run->state_payload : [];
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

        if (! array_key_exists($callback['action'], $this->buttonLabels())) {
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
                'reaction_label' => $this->buttonLabels()[$action],
                'reaction_source' => 'telegram_callback',
            ]),
            exitOutcome: match ($action) {
                self::ACTION_POSITIVE => 'reacted_positive',
                self::ACTION_LATER => 'reacted_later',
                default => 'reacted_decline',
            },
        );
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    private function telegramInlineKeyboard(int $runId): array
    {
        return array_map(
            fn (string $action, string $label): array => [[
                'text' => $label,
                'callback_data' => sprintf('scenario:%s:%d:%s', self::code(), $runId, $action),
            ]],
            array_keys($this->buttonLabels()),
            array_values($this->buttonLabels()),
        );
    }

    private function messageText(): string
    {
        return (string) config(
            'bots.scenarios.warmup.telegram.text',
            'Прежде чем перейти дальше, подскажите, вам интересно получить несколько коротких материалов?'
        );
    }

    /**
     * @return array<string, string>
     */
    private function buttonLabels(): array
    {
        return [
            self::ACTION_POSITIVE => (string) config('bots.scenarios.warmup.telegram.buttons.positive', 'Да, интересно'),
            self::ACTION_LATER => (string) config('bots.scenarios.warmup.telegram.buttons.later', 'Позже'),
            self::ACTION_DECLINE => (string) config('bots.scenarios.warmup.telegram.buttons.decline', 'Не интересно'),
        ];
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
