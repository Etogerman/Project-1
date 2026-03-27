<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class TelegramBotApiService
{
    public function sendAutoReply(Channel $channel, IncomingBotMessage $message): void
    {
        if (! filled($message->externalChatId)) {
            throw new InvalidArgumentException("Telegram message for channel [{$channel->id}] does not have chat id.");
        }

        Http::asJson()
            ->post(
                sprintf('https://api.telegram.org/bot%s/sendMessage', $this->token($channel)),
                [
                    'chat_id' => $message->externalChatId,
                    'text' => (string) config('bots.default_auto_reply_text'),
                ],
            )
            ->throw();
    }

    public function registerWebhook(Channel $channel, string $url, string $secret): void
    {
        Http::asJson()
            ->post(
                sprintf('https://api.telegram.org/bot%s/setWebhook', $this->token($channel)),
                [
                    'url' => $url,
                    'secret_token' => $secret,
                    'allowed_updates' => (array) config('bots.telegram.allowed_updates', ['message']),
                ],
            )
            ->throw();
    }

    protected function token(Channel $channel): string
    {
        $token = $channel->getToken();

        if (! filled($token)) {
            throw new InvalidArgumentException("Channel [{$channel->id}] does not have a bot token.");
        }

        return $token;
    }
}
