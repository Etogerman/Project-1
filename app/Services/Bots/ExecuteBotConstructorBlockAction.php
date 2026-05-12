<?php

namespace App\Services\Bots;

use App\Models\BotConstructorBlock;
use App\Models\BotConstructorBlockRun;
use App\Models\BotConstructorDialogState;
use App\Models\BotConstructorExecutionBlockRun;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use App\Services\TelegramAccount\QueueTelegramAccountSystemReplyAction;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExecuteBotConstructorBlockAction
{
    public function __construct(
        private readonly ChannelActivityLogger $channelActivityLogger,
        private readonly SendBotDialogTextAction $sendBotDialogTextAction,
        private readonly StoreOutboundBotConstructorBlockMessageAction $storeOutboundBotConstructorBlockMessageAction,
        private readonly ResolveDialogRouteStatusAction $resolveDialogRouteStatusAction,
        private readonly QueueTelegramAccountSystemReplyAction $queueTelegramAccountSystemReplyAction,
    ) {}

    public function handle(
        Message $rootMessage,
        Dialog $dialog,
        BotConstructorBlock $block,
        BotConstructorExecutionBlockRun $executionBlockRun,
        ?BotConstructorBlockRun $legacyRun = null,
    ): BotConstructorExecutionBlockRun {
        $dialog->load(['channel', 'currentContactIdentity']);
        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            return $this->markFailed($legacyRun, $executionBlockRun, 'Канал диалога не найден.');
        }

        $replyText = (string) $block->response_text;

        if (BotConstructorBlock::isNoReply($replyText)) {
            $this->markSucceeded(
                $legacyRun,
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
                $this->logContext($rootMessage, $block),
            );

            return $executionBlockRun->fresh() ?? $executionBlockRun;
        }

        if (! $channel->isReadyForConstructorAutoReplies()) {
            $this->markFailed(
                $legacyRun,
                $executionBlockRun,
                'Канал сейчас не готов к отправке ответа: '.$channel->getHealthStatusLabel(),
            );

            $this->channelActivityLogger->info(
                $channel,
                'bot.constructor_block_failed',
                'Стартовое условие сработало, но канал сейчас не готов к отправке.',
                $this->logContext($rootMessage, $block) + [
                    'channel_health_status' => $channel->getHealthStatusLabel(),
                ],
            );

            return $executionBlockRun->fresh() ?? $executionBlockRun;
        }

        try {
            if ($this->shouldQueueThroughTelegramAccountGateway($channel)) {
                return $this->queueTelegramAccountGatewayReply($rootMessage, $channel, $dialog, $block, $legacyRun, $executionBlockRun, $replyText);
            }

            $sendResult = $this->sendBotDialogTextAction->handleMessage($rootMessage, $replyText, $dialog);

            if (! $sendResult->wasSent() || $sendResult->deliveryResult === null) {
                $this->markFailed(
                    $legacyRun,
                    $executionBlockRun,
                    $this->safeErrorMessage($sendResult->routeStatus->blockedReason
                        ?? $sendResult->routeStatus->label
                        ?? 'Маршрут ответа недоступен.', $channel),
                );

                $this->channelActivityLogger->info(
                    $channel,
                    'bot.constructor_block_failed',
                    'Стартовое условие сработало, но ответ не отправлен.',
                    $this->logContext($rootMessage, $block) + [
                        'route_status_code' => $sendResult->routeStatus->code,
                        'blocked_reason' => $sendResult->routeStatus->blockedReason,
                    ],
                );

                return $executionBlockRun->fresh() ?? $executionBlockRun;
            }

            $outboundMessage = $this->storeOutboundBotConstructorBlockMessageAction->handle(
                $channel,
                $rootMessage,
                $sendResult->deliveryResult,
                $sendResult->dialog instanceof Dialog ? $sendResult->dialog : $dialog,
            );

            $this->markSucceeded(
                $legacyRun,
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
                $this->logContext($rootMessage, $block) + [
                    'outbound_message_id' => $outboundMessage->id,
                    'outbound_external_message_id' => $sendResult->deliveryResult->externalMessageId,
                ],
            );
        } catch (Throwable $throwable) {
            $safeErrorMessage = $this->safeErrorMessage($throwable->getMessage(), $channel);

            $channel->markError($safeErrorMessage);
            $this->markFailed($legacyRun, $executionBlockRun, $safeErrorMessage);

            $this->channelActivityLogger->error(
                $channel,
                'bot.constructor_block_failed',
                'Стартовое условие сработало, но ответ не отправлен.',
                $this->logContext($rootMessage, $block) + [
                    'error' => $safeErrorMessage,
                ],
            );
        }

        return $executionBlockRun->fresh() ?? $executionBlockRun;
    }

    private function queueTelegramAccountGatewayReply(
        Message $rootMessage,
        Channel $channel,
        Dialog $dialog,
        BotConstructorBlock $block,
        ?BotConstructorBlockRun $legacyRun,
        BotConstructorExecutionBlockRun $executionBlockRun,
        string $replyText,
    ): BotConstructorExecutionBlockRun {
        $routeStatus = $this->resolveDialogRouteStatusAction->handle($dialog);

        if (! $routeStatus->isSendable) {
            $this->markFailed(
                $legacyRun,
                $executionBlockRun,
                $this->safeErrorMessage($routeStatus->blockedReason
                    ?? $routeStatus->label
                    ?? 'Маршрут ответа недоступен.', $channel),
            );

            $this->channelActivityLogger->info(
                $channel,
                'bot.constructor_block_failed',
                'Стартовое условие сработало, но ответ не отправлен.',
                $this->logContext($rootMessage, $block) + [
                    'route_status_code' => $routeStatus->code,
                    'blocked_reason' => $routeStatus->blockedReason,
                ],
            );

            return $executionBlockRun->fresh() ?? $executionBlockRun;
        }

        $outboundMessage = $this->queueTelegramAccountSystemReplyAction->handle(
            $dialog,
            $replyText,
            $rootMessage,
            Message::SENT_BY_SYSTEM_CODE_BOT_CONSTRUCTOR_BLOCK,
        );

        $this->markSucceeded(
            $legacyRun,
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
            $this->logContext($rootMessage, $block) + [
                'outbound_message_id' => $outboundMessage->id,
                'outgoing_message_id' => data_get($outboundMessage->raw_payload, 'outgoing_message_id'),
            ],
        );

        return $executionBlockRun->fresh() ?? $executionBlockRun;
    }

    private function shouldQueueThroughTelegramAccountGateway(Channel $channel): bool
    {
        return $channel->isAccountConnection()
            && $channel->platform === Channel::PLATFORM_TELEGRAM;
    }

    private function markSucceeded(
        ?BotConstructorBlockRun $legacyRun,
        BotConstructorExecutionBlockRun $executionBlockRun,
        Dialog $dialog,
        BotConstructorBlock $block,
        string $legacyStatus,
        string $executionStatus,
        ?int $outboundMessageId = null,
    ): void {
        DB::transaction(function () use ($legacyRun, $executionBlockRun, $dialog, $block, $legacyStatus, $executionStatus, $outboundMessageId): void {
            if ($legacyRun instanceof BotConstructorBlockRun) {
                $legacyRun->forceFill([
                    'outbound_message_id' => $outboundMessageId,
                    'status' => $legacyStatus,
                    'error_message' => null,
                ])->save();
            }

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
        ?BotConstructorBlockRun $legacyRun,
        BotConstructorExecutionBlockRun $executionBlockRun,
        string $message,
    ): BotConstructorExecutionBlockRun {
        $safeMessage = mb_substr(trim($message), 0, 1000);

        DB::transaction(function () use ($legacyRun, $executionBlockRun, $safeMessage): void {
            if ($legacyRun instanceof BotConstructorBlockRun) {
                $legacyRun->forceFill([
                    'status' => BotConstructorBlockRun::STATUS_FAILED,
                    'error_message' => $safeMessage,
                ])->save();
            }

            $executionBlockRun->forceFill([
                'status' => BotConstructorExecutionBlockRun::STATUS_FAILED,
                'processing_started_at' => null,
                'error_message' => $safeMessage,
            ])->save();
        });

        return $executionBlockRun->fresh() ?? $executionBlockRun;
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
}
