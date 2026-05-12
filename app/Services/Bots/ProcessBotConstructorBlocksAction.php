<?php

namespace App\Services\Bots;

use App\Models\BotConstructorBlock;
use App\Models\BotConstructorBlockRun;
use App\Models\BotConstructorDialogState;
use App\Models\BotConstructorExecution;
use App\Models\BotConstructorExecutionBlockRun;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Dialogs\ResolveDialogRouteSourceAction;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use App\Services\TelegramAccount\QueueTelegramAccountSystemReplyAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessBotConstructorBlocksAction
{
    private const STARTED_BUT_NOT_FINISHED = 'Срабатывание начато, но не завершено.';

    public function __construct(
        private readonly ChannelActivityLogger $channelActivityLogger,
        private readonly SendBotDialogTextAction $sendBotDialogTextAction,
        private readonly StoreOutboundBotConstructorBlockMessageAction $storeOutboundBotConstructorBlockMessageAction,
        private readonly ResolveDialogRouteSourceAction $resolveDialogRouteSourceAction,
        private readonly ResolveDialogRouteStatusAction $resolveDialogRouteStatusAction,
        private readonly QueueTelegramAccountSystemReplyAction $queueTelegramAccountSystemReplyAction,
    ) {}

    public function handle(Message $message): bool
    {
        $message->loadMissing(['channel', 'contactIdentity', 'contact', 'dialog']);

        $channel = $message->channel;
        $dialog = $message->dialog;

        if (! $channel instanceof Channel || ! $dialog instanceof Dialog) {
            return false;
        }

        $matchedBlocks = $this->matchedBlocks($message, $channel);

        if ($matchedBlocks->isEmpty()) {
            return false;
        }

        $execution = $this->runningInboundExecution($message);

        foreach ($matchedBlocks as $block) {
            $runs = $this->prepareInboundBlockRuns($message, $channel, $dialog, $block, $execution);

            if ($runs === null) {
                continue;
            }

            [$legacyRun, $executionBlockRun] = $runs;

            if ($executionBlockRun->status === BotConstructorExecutionBlockRun::STATUS_PROCESSING) {
                $this->executeBlock($message, $channel, $dialog, $block, $legacyRun, $executionBlockRun);
            }
        }

        if (
            $execution instanceof BotConstructorExecution
            && $execution->status === BotConstructorExecution::STATUS_RUNNING
            && ! $this->hasProcessingExecutionBlockRuns($execution)
        ) {
            $execution->forceFill([
                'status' => BotConstructorExecution::STATUS_COMPLETED,
            ])->save();
        }

        return true;
    }

    /**
     * @return Collection<int, BotConstructorBlock>
     */
    private function matchedBlocks(Message $message, Channel $channel): Collection
    {
        return BotConstructorBlock::query()
            ->active()
            ->forChannel($channel)
            ->with('channels')
            ->orderBy('id')
            ->get()
            ->filter(fn (BotConstructorBlock $block): bool => $block->matchesMessage($message))
            ->values();
    }

    /**
     * @return array{0:BotConstructorBlockRun,1:BotConstructorExecutionBlockRun}|null
     */
    private function prepareInboundBlockRuns(
        Message $message,
        Channel $channel,
        Dialog $dialog,
        BotConstructorBlock $block,
        ?BotConstructorExecution &$execution,
    ): ?array {
        try {
            return DB::transaction(function () use ($message, $channel, $dialog, $block, &$execution): ?array {
                $legacyRun = BotConstructorBlockRun::query()
                    ->where('inbound_message_id', $message->id)
                    ->where('bot_constructor_block_id', $block->id)
                    ->lockForUpdate()
                    ->first();

                if ($legacyRun instanceof BotConstructorBlockRun) {
                    if ($this->hasExecutionTrace($message, $block)) {
                        return null;
                    }

                    $execution = $this->ensureExecution($execution, $message, $channel, $dialog);

                    return [
                        $legacyRun,
                        $this->createRecoveryExecutionBlockRun($execution, $dialog, $channel, $block, $legacyRun),
                    ];
                }

                $execution = $this->ensureExecution($execution, $message, $channel, $dialog);
                $legacyRun = BotConstructorBlockRun::query()->create([
                    'inbound_message_id' => $message->id,
                    'bot_constructor_block_id' => $block->id,
                    'status' => BotConstructorBlockRun::STATUS_FAILED,
                    'error_message' => self::STARTED_BUT_NOT_FINISHED,
                ]);
                $executionBlockRun = $this->createExecutionBlockRun(
                    $execution,
                    $dialog,
                    $channel,
                    $block,
                    BotConstructorExecutionBlockRun::STATUS_PROCESSING,
                    null,
                    now(),
                );

                return [$legacyRun, $executionBlockRun];
            });
        } catch (QueryException $exception) {
            if ($this->wasUniqueConstraintViolation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    private function hasExecutionTrace(Message $message, BotConstructorBlock $block): bool
    {
        return BotConstructorExecutionBlockRun::query()
            ->where('bot_constructor_block_id', $block->id)
            ->whereHas('execution', function ($query) use ($message): void {
                $query->where('root_inbound_message_id', $message->id);
            })
            ->exists();
    }

    private function ensureExecution(
        ?BotConstructorExecution $execution,
        Message $message,
        Channel $channel,
        Dialog $dialog,
    ): BotConstructorExecution {
        if ($execution instanceof BotConstructorExecution) {
            return $execution;
        }

        $existingExecution = $this->runningInboundExecution($message, true);

        if ($existingExecution instanceof BotConstructorExecution) {
            return $existingExecution;
        }

        return BotConstructorExecution::query()->create([
            'root_inbound_message_id' => $message->id,
            'parent_execution_id' => null,
            'started_by_arrow_run_id' => null,
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
            'trigger_type' => BotConstructorExecution::TRIGGER_INBOUND,
            'auto_transition_count' => 0,
            'next_sequence_number' => 1,
            'status' => BotConstructorExecution::STATUS_RUNNING,
        ]);
    }

    private function runningInboundExecution(Message $message, bool $lock = false): ?BotConstructorExecution
    {
        $query = BotConstructorExecution::query()
            ->where('root_inbound_message_id', $message->id)
            ->where('trigger_type', BotConstructorExecution::TRIGGER_INBOUND)
            ->where('status', BotConstructorExecution::STATUS_RUNNING);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function hasProcessingExecutionBlockRuns(BotConstructorExecution $execution): bool
    {
        return BotConstructorExecutionBlockRun::query()
            ->where('bot_constructor_execution_id', $execution->id)
            ->where('status', BotConstructorExecutionBlockRun::STATUS_PROCESSING)
            ->exists();
    }

    private function createExecutionBlockRun(
        BotConstructorExecution $execution,
        Dialog $dialog,
        Channel $channel,
        BotConstructorBlock $block,
        string $status,
        ?string $errorMessage = null,
        mixed $processingStartedAt = null,
        ?int $outboundMessageId = null,
    ): BotConstructorExecutionBlockRun {
        $lockedExecution = BotConstructorExecution::query()
            ->whereKey($execution->id)
            ->lockForUpdate()
            ->firstOrFail();
        $sequenceNumber = (int) $lockedExecution->next_sequence_number;

        $lockedExecution->forceFill([
            'next_sequence_number' => $sequenceNumber + 1,
        ])->save();

        $execution->setRawAttributes($lockedExecution->getAttributes(), true);

        return BotConstructorExecutionBlockRun::query()->create([
            'bot_constructor_execution_id' => $lockedExecution->id,
            'bot_constructor_block_id' => $block->id,
            'bot_constructor_arrow_run_id' => null,
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
            'sequence_number' => $sequenceNumber,
            'status' => $status,
            'outbound_message_id' => $outboundMessageId,
            'processing_started_at' => $processingStartedAt,
            'error_message' => $errorMessage,
        ]);
    }

    private function createRecoveryExecutionBlockRun(
        BotConstructorExecution $execution,
        Dialog $dialog,
        Channel $channel,
        BotConstructorBlock $block,
        BotConstructorBlockRun $legacyRun,
    ): BotConstructorExecutionBlockRun {
        return $this->createExecutionBlockRun(
            $execution,
            $dialog,
            $channel,
            $block,
            BotConstructorExecutionBlockRun::STATUS_DELIVERY_UNCERTAIN,
            'Старый факт обработки уже существовал, trace восстановлен после частичного сбоя.',
            null,
            $legacyRun->outbound_message_id === null ? null : (int) $legacyRun->outbound_message_id,
        );
    }

    private function executeBlock(
        Message $message,
        Channel $channel,
        Dialog $dialog,
        BotConstructorBlock $block,
        BotConstructorBlockRun $run,
        BotConstructorExecutionBlockRun $executionBlockRun,
    ): void {
        $replyText = (string) $block->response_text;

        if (BotConstructorBlock::isNoReply($replyText)) {
            $this->markSucceeded(
                $run,
                $executionBlockRun,
                $dialog,
                $block,
                BotConstructorBlockRun::STATUS_NO_REPLY,
                BotConstructorExecutionBlockRun::STATUS_NO_REPLY,
            );

            $this->channelActivityLogger->info(
                $channel,
                'bot.constructor_block_no_reply',
                'Стартовое условие сработало без отправки ответа.',
                $this->logContext($message, $block),
            );

            return;
        }

        if (! $channel->isReadyForConstructorAutoReplies()) {
            $this->markFailed(
                $run,
                $executionBlockRun,
                'Канал сейчас не готов к отправке ответа: '.$channel->getHealthStatusLabel(),
            );

            $this->channelActivityLogger->info(
                $channel,
                'bot.constructor_block_failed',
                'Стартовое условие сработало, но канал сейчас не готов к отправке.',
                $this->logContext($message, $block) + [
                    'channel_health_status' => $channel->getHealthStatusLabel(),
                ],
            );

            return;
        }

        try {
            if ($this->shouldQueueThroughTelegramAccountGateway($channel)) {
                $this->queueTelegramAccountGatewayReply($message, $channel, $dialog, $block, $run, $executionBlockRun, $replyText);

                return;
            }

            $sendResult = $this->sendBotDialogTextAction->handleMessage($message, $replyText);

            if (! $sendResult->wasSent() || $sendResult->deliveryResult === null) {
                $this->markFailed(
                    $run,
                    $executionBlockRun,
                    $this->safeErrorMessage($sendResult->routeStatus->blockedReason
                        ?? $sendResult->routeStatus->label
                        ?? 'Маршрут ответа недоступен.', $channel),
                );

                $this->channelActivityLogger->info(
                    $channel,
                    'bot.constructor_block_failed',
                    'Стартовое условие сработало, но ответ не отправлен.',
                    $this->logContext($message, $block) + [
                        'route_status_code' => $sendResult->routeStatus->code,
                        'blocked_reason' => $sendResult->routeStatus->blockedReason,
                    ],
                );

                return;
            }

            $outboundMessage = $this->storeOutboundBotConstructorBlockMessageAction->handle(
                $channel,
                $message,
                $sendResult->deliveryResult,
                $sendResult->dialog instanceof Dialog ? $sendResult->dialog : null,
            );

            $this->markSucceeded(
                $run,
                $executionBlockRun,
                $dialog,
                $block,
                BotConstructorBlockRun::STATUS_SENT,
                BotConstructorExecutionBlockRun::STATUS_SENT,
                $outboundMessage->id,
            );

            $channel->markReplySent();

            $this->channelActivityLogger->info(
                $channel,
                'bot.constructor_block_sent',
                'Стартовое условие отправило ответ.',
                $this->logContext($message, $block) + [
                    'outbound_message_id' => $outboundMessage->id,
                    'outbound_external_message_id' => $sendResult->deliveryResult->externalMessageId,
                ],
            );
        } catch (Throwable $throwable) {
            $safeErrorMessage = $this->safeErrorMessage($throwable->getMessage(), $channel);

            $channel->markError($safeErrorMessage);
            $this->markFailed($run, $executionBlockRun, $safeErrorMessage);

            $this->channelActivityLogger->error(
                $channel,
                'bot.constructor_block_failed',
                'Стартовое условие сработало, но ответ не отправлен.',
                $this->logContext($message, $block) + [
                    'error' => $safeErrorMessage,
                ],
            );
        }
    }

    private function queueTelegramAccountGatewayReply(
        Message $message,
        Channel $channel,
        Dialog $dialog,
        BotConstructorBlock $block,
        BotConstructorBlockRun $run,
        BotConstructorExecutionBlockRun $executionBlockRun,
        string $replyText,
    ): void {
        $routeDialog = $this->resolveReplyDialog($message);

        if (! $routeDialog instanceof Dialog) {
            $this->markFailed($run, $executionBlockRun, 'Маршрут ответа недоступен.');

            $this->channelActivityLogger->info(
                $channel,
                'bot.constructor_block_failed',
                'Стартовое условие сработало, но ответ не отправлен.',
                $this->logContext($message, $block) + [
                    'route_status_code' => null,
                    'blocked_reason' => 'Маршрут ответа недоступен.',
                ],
            );

            return;
        }

        $routeStatus = $this->resolveDialogRouteStatusAction->handle($routeDialog);

        if (! $routeStatus->isSendable) {
            $this->markFailed(
                $run,
                $executionBlockRun,
                $this->safeErrorMessage($routeStatus->blockedReason
                    ?? $routeStatus->label
                    ?? 'Маршрут ответа недоступен.', $channel),
            );

            $this->channelActivityLogger->info(
                $channel,
                'bot.constructor_block_failed',
                'Стартовое условие сработало, но ответ не отправлен.',
                $this->logContext($message, $block) + [
                    'route_status_code' => $routeStatus->code,
                    'blocked_reason' => $routeStatus->blockedReason,
                ],
            );

            return;
        }

        $outboundMessage = $this->queueTelegramAccountSystemReplyAction->handle(
            $routeDialog,
            $replyText,
            $message,
            Message::SENT_BY_SYSTEM_CODE_BOT_CONSTRUCTOR_BLOCK,
        );

        $this->markSucceeded(
            $run,
            $executionBlockRun,
            $dialog,
            $block,
            BotConstructorBlockRun::STATUS_SENT,
            BotConstructorExecutionBlockRun::STATUS_SENT,
            $outboundMessage->id,
        );

        $this->channelActivityLogger->info(
            $channel,
            'bot.constructor_block_queued',
            'Стартовое условие поставило ответ в очередь Gateway.',
            $this->logContext($message, $block) + [
                'outbound_message_id' => $outboundMessage->id,
                'outgoing_message_id' => data_get($outboundMessage->raw_payload, 'outgoing_message_id'),
            ],
        );
    }

    private function resolveReplyDialog(Message $message): ?Dialog
    {
        $sendableDialog = $this->resolveDialogRouteSourceAction->forMessage($message);

        if ($sendableDialog instanceof Dialog) {
            return $sendableDialog;
        }

        $fallbackDialog = $this->resolveDialogRouteSourceAction->fallbackFromLegacyMessage($message);

        if ($fallbackDialog instanceof Dialog) {
            return $fallbackDialog;
        }

        $message->loadMissing(['dialog.channel', 'dialog.currentContactIdentity']);

        return $message->dialog instanceof Dialog ? $message->dialog : null;
    }

    private function shouldQueueThroughTelegramAccountGateway(Channel $channel): bool
    {
        return $channel->isAccountConnection()
            && $channel->platform === Channel::PLATFORM_TELEGRAM;
    }

    private function markSucceeded(
        BotConstructorBlockRun $run,
        BotConstructorExecutionBlockRun $executionBlockRun,
        Dialog $dialog,
        BotConstructorBlock $block,
        string $legacyStatus,
        string $executionStatus,
        ?int $outboundMessageId = null,
    ): void {
        DB::transaction(function () use ($run, $executionBlockRun, $dialog, $block, $legacyStatus, $executionStatus, $outboundMessageId): void {
            $run->forceFill([
                'outbound_message_id' => $outboundMessageId,
                'status' => $legacyStatus,
                'error_message' => null,
            ])->save();

            $executionBlockRun->forceFill([
                'outbound_message_id' => $outboundMessageId,
                'status' => $executionStatus,
                'processing_started_at' => null,
                'error_message' => null,
            ])->save();

            BotConstructorDialogState::query()->updateOrCreate(
                ['dialog_id' => $dialog->id],
                [
                    'current_block_id' => $block->id,
                    'last_execution_id' => $executionBlockRun->bot_constructor_execution_id,
                ],
            );
        });
    }

    private function markFailed(
        BotConstructorBlockRun $run,
        BotConstructorExecutionBlockRun $executionBlockRun,
        string $message,
    ): void {
        $safeMessage = mb_substr(trim($message), 0, 1000);

        DB::transaction(function () use ($run, $executionBlockRun, $safeMessage): void {
            $run->forceFill([
                'status' => BotConstructorBlockRun::STATUS_FAILED,
                'error_message' => $safeMessage,
            ])->save();

            $executionBlockRun->forceFill([
                'status' => BotConstructorExecutionBlockRun::STATUS_FAILED,
                'processing_started_at' => null,
                'error_message' => $safeMessage,
            ])->save();
        });
    }

    private function safeErrorMessage(string $message, Channel $channel): string
    {
        $safeMessage = trim($message);

        foreach ([$channel->getToken(), $channel->getWebhookSecret()] as $secret) {
            if (filled($secret)) {
                $safeMessage = str_replace((string) $secret, '[secret]', $safeMessage);
            }
        }

        return mb_substr($safeMessage, 0, 1000);
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(Message $message, BotConstructorBlock $block): array
    {
        return [
            'message_id' => $message->id,
            'provider_event_key' => $message->provider_event_key,
            'external_message_id' => $message->external_message_id,
            'constructor_block_id' => $block->id,
            'constructor_block_title' => $block->title,
            'match_type' => $block->match_type,
            'match_values' => $block->match_values,
        ];
    }

    private function wasUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['1062', '1555', '2067'], true);
    }
}
