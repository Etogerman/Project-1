<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class BotAutoReplyService
{
    public function __construct(
        protected TelegramBotApiService $telegramBotApiService,
        protected MaxBotApiService $maxBotApiService,
    ) {}

    public function handle(Channel $channel, IncomingBotMessage $message): void
    {
        Log::info('bot auto reply started', [
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_chat_id' => $message->externalChatId,
            'external_user_id' => $message->externalUserId,
        ]);

        match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->sendAutoReply($channel, $message),
            Channel::PLATFORM_MAX => $this->maxBotApiService->sendAutoReply($channel, $message),
            default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
        };

        Log::info('bot auto reply sent', [
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_chat_id' => $message->externalChatId,
            'external_user_id' => $message->externalUserId,
        ]);
    }
}
