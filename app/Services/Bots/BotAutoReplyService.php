<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
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

    public function handle(Channel $channel, IncomingBotMessage $message, Message $storedMessage): void
    {
        $replyText = (string) config('bots.default_auto_reply_text');

        Log::info('bot auto reply started', [
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_chat_id' => $message->externalChatId,
            'external_user_id' => $message->externalUserId,
        ]);

        $deliveryResult = match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->sendAutoReply($channel, $message, $replyText),
            Channel::PLATFORM_MAX => $this->maxBotApiService->sendAutoReply($channel, $message, $replyText),
            default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
        };

        $this->storeOutboundAutoReplyMessageAction->handle($channel, $storedMessage, $deliveryResult);

        $channel->markReplySent();

        Log::info('bot auto reply sent', [
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_chat_id' => $message->externalChatId,
            'external_user_id' => $message->externalUserId,
            'outbound_external_message_id' => $deliveryResult->externalMessageId,
        ]);
        $this->channelActivityLogger->info(
            $channel,
            'bot.reply_sent',
            'Автоответ отправлен.',
            [
                'platform' => $channel->platform,
                'external_chat_id' => $message->externalChatId,
                'external_user_id' => $message->externalUserId,
                'outbound_external_message_id' => $deliveryResult->externalMessageId,
            ],
        );
    }
}
