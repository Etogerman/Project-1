<?php

namespace App\Services\Bots;

use App\Models\BotConstructorBlock;
use App\Models\BotConstructorBlockRun;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Dialogs\ResolveDialogRouteSourceAction;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use App\Services\TelegramAccount\QueueTelegramAccountSystemReplyAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
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

        if (! $channel instanceof Channel) {
            return false;
        }

        $matchedBlocks = $this->matchedBlocks($message, $channel);

        if ($matchedBlocks->isEmpty()) {
            return false;
        }

        foreach ($matchedBlocks as $block) {
            $run = $this->createRunMarker($message, $block);

            if (! $run instanceof BotConstructorBlockRun) {
                continue;
            }

            $this->executeBlock($message, $channel, $block, $run);
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

    private function createRunMarker(Message $message, BotConstructorBlock $block): ?BotConstructorBlockRun
    {
        try {
            $run = BotConstructorBlockRun::query()->firstOrCreate(
                [
                    'inbound_message_id' => $message->id,
                    'bot_constructor_block_id' => $block->id,
                ],
                [
                    'status' => BotConstructorBlockRun::STATUS_FAILED,
                    'error_message' => self::STARTED_BUT_NOT_FINISHED,
                ],
            );
        } catch (QueryException $exception) {
            if ($this->wasUniqueConstraintViolation($exception)) {
                return null;
            }

            throw $exception;
        }

        return $run->wasRecentlyCreated ? $run : null;
    }

    private function executeBlock(
        Message $message,
        Channel $channel,
        BotConstructorBlock $block,
        BotConstructorBlockRun $run,
    ): void {
        $replyText = (string) $block->response_text;

        if (BotConstructorBlock::isNoReply($replyText)) {
            $run->forceFill([
                'status' => BotConstructorBlockRun::STATUS_NO_REPLY,
                'error_message' => null,
            ])->save();

            $this->channelActivityLogger->info(
                $channel,
                'bot.constructor_block_no_reply',
                'Стартовое условие сработало без отправки ответа.',
                $this->logContext($message, $block),
            );

            return;
        }

        if (! $channel->isReadyForConstructorAutoReplies()) {
            $this->markFailed($run, 'Канал сейчас не готов к отправке ответа: '.$channel->getHealthStatusLabel());

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
                $this->queueTelegramAccountGatewayReply($message, $channel, $block, $run, $replyText);

                return;
            }

            $sendResult = $this->sendBotDialogTextAction->handleMessage($message, $replyText);

            if (! $sendResult->wasSent() || $sendResult->deliveryResult === null) {
                $this->markFailed(
                    $run,
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

            $run->forceFill([
                'outbound_message_id' => $outboundMessage->id,
                'status' => BotConstructorBlockRun::STATUS_SENT,
                'error_message' => null,
            ])->save();

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
            $this->markFailed($run, $safeErrorMessage);

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
        BotConstructorBlock $block,
        BotConstructorBlockRun $run,
        string $replyText,
    ): void {
        $routeDialog = $this->resolveReplyDialog($message);

        if (! $routeDialog instanceof Dialog) {
            $this->markFailed($run, 'Маршрут ответа недоступен.');

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

        $run->forceFill([
            'outbound_message_id' => $outboundMessage->id,
            'status' => BotConstructorBlockRun::STATUS_SENT,
            'error_message' => null,
        ])->save();

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

    private function markFailed(BotConstructorBlockRun $run, string $message): void
    {
        $run->forceFill([
            'status' => BotConstructorBlockRun::STATUS_FAILED,
            'error_message' => mb_substr(trim($message), 0, 1000),
        ])->save();
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
