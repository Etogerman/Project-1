<?php

namespace App\Services\Bots;

use App\Models\Message;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class BotAutoReplyService
{
    public function __construct(
        protected ChannelActivityLogger $channelActivityLogger,
        protected StoreOutboundAutoReplyMessageAction $storeOutboundAutoReplyMessageAction,
        protected TelegramBotApiService $telegramBotApiService,
        protected MaxBotApiService $maxBotApiService,
    ) {}

    public function handle(Message $storedMessage): void
    {
        if ($storedMessage->hasSuccessfulAutoReply()) {
            return;
        }

        $storedMessage->loadMissing(['channel', 'contactIdentity']);

        $channel = $storedMessage->channel;

        if ($channel === null) {
            throw new InvalidArgumentException("Inbound message [{$storedMessage->id}] does not have a channel.");
        }

        $replyText = (string) config('bots.default_auto_reply_text');
        $externalChatId = $storedMessage->external_chat_id;
        $externalUserId = $storedMessage->contactIdentity?->external_user_id;

        Log::info('bot auto reply started', [
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'message_id' => $storedMessage->id,
            'external_chat_id' => $externalChatId,
            'external_user_id' => $externalUserId,
        ]);

        $deliveryResult = match ($channel->platform) {
            \App\Models\Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->sendTextMessage(
                $channel,
                $externalChatId,
                $externalUserId,
                $replyText,
            ),
            \App\Models\Channel::PLATFORM_MAX => $this->maxBotApiService->sendTextMessage(
                $channel,
                $externalChatId,
                $externalUserId,
                $replyText,
            ),
            default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
        };

        $this->storeOutboundAutoReplyMessageAction->handle($channel, $storedMessage, $deliveryResult);

        $channel->markReplySent();

        Log::info('bot auto reply sent', [
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'message_id' => $storedMessage->id,
            'external_chat_id' => $externalChatId,
            'external_user_id' => $externalUserId,
            'outbound_external_message_id' => $deliveryResult->externalMessageId,
        ]);
        $this->channelActivityLogger->info(
            $channel,
            'bot.reply_sent',
            'Автоответ отправлен.',
            [
                'platform' => $channel->platform,
                'message_id' => $storedMessage->id,
                'external_chat_id' => $externalChatId,
                'external_user_id' => $externalUserId,
                'outbound_external_message_id' => $deliveryResult->externalMessageId,
            ],
        );
    }
}
