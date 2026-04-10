<?php

namespace App\Services\Scenarios;

use App\Data\Messages\PreparedMessageContentData;
use App\Data\Scenarios\ScenarioInboundResult;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioRun;
use App\Models\ScenarioVersion;
use App\Services\Bots\MaxBotApiService;
use App\Services\Bots\StoreOutboundScenarioMessageAction;
use App\Services\Bots\TelegramBotApiService;
use App\Services\Messages\PrepareMessageContentAction;
use RuntimeException;

class GenericDbScenarioRuntime implements ResolvedScenarioRuntime
{
    public function __construct(
        private readonly Scenario $scenario,
        private readonly ScenarioVersion $publishedVersion,
        private readonly ValidateScenarioSchemaPayloadAction $validateScenarioSchemaPayloadAction,
        private readonly StoreOutboundScenarioMessageAction $storeOutboundScenarioMessageAction,
        private readonly TelegramBotApiService $telegramBotApiService,
        private readonly MaxBotApiService $maxBotApiService,
        private readonly PrepareMessageContentAction $prepareMessageContentAction,
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

        if (($block['type'] ?? null) !== 'question') {
            return new ScenarioInboundResult(
                consumed: true,
                status: ScenarioRun::STATUS_CANCELLED,
                currentStep: null,
                statePayload: $statePayload,
                exitOutcome: 'invalid_current_step',
            );
        }

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
            'message' => $this->advanceAfterMessageBlock($message, $schema, $block, $statePayload, $remainingTransitions - 1),
            'question' => $this->enterQuestionBlock($message, $nextBlockId, $block, $statePayload),
            'complete' => [
                'status' => ScenarioRun::STATUS_COMPLETED,
                'current_step' => null,
                'state_payload' => $statePayload,
                'exit_outcome' => 'completed',
            ],
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
    ): array
    {
        $this->dispatchScenarioMessage(
            $message,
            (string) $block['text'],
            (string) $block['text_format'],
        );

        return $this->advanceFromBlock(
            $message,
            $schema,
            (string) $block['next'],
            $statePayload,
            $remainingTransitions,
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
    private function enterQuestionBlock(Message $message, string $blockId, array $block, array $statePayload): array
    {
        $this->dispatchScenarioMessage(
            $message,
            (string) $block['text'],
            (string) $block['text_format'],
        );

        return [
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => $blockId,
            'state_payload' => $statePayload,
            'exit_outcome' => null,
        ];
    }

    private function dispatchScenarioMessage(Message $message, string $text, string $textFormat): void
    {
        $channel = $message->channel;

        if (! $channel instanceof Channel) {
            throw new RuntimeException("Scenario [{$this->code()}] message does not have an active channel.");
        }

        $content = $this->prepareMessageContentAction->handle($text, $textFormat);
        $deliveryResult = $this->deliverScenarioMessage($channel, $message, $content);

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
    ): \App\Data\Bots\AutoReplyDeliveryResult {
        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->sendTextMessage(
                $channel,
                $message->external_chat_id,
                $message->contactIdentity?->external_user_id,
                $content->transportText,
                null,
                $content->textFormat,
            ),
            Channel::PLATFORM_MAX => $this->maxBotApiService->sendTextMessage(
                $channel,
                $message->external_chat_id,
                $message->contactIdentity?->external_user_id,
                $content->transportText,
                null,
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

    private function systemCode(): string
    {
        return 'scenario_'.$this->code();
    }
}
