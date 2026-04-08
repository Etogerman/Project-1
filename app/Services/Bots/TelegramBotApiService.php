<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Data\Bots\BotMetadata;
use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TelegramBotApiService
{
    public function sendAutoReply(Channel $channel, IncomingBotMessage $message, string $text): AutoReplyDeliveryResult
    {
        return $this->sendTextMessage($channel, $message->externalChatId, $message->externalUserId, $text);
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendTextMessage(
        Channel $channel,
        ?string $externalChatId,
        ?string $externalUserId,
        string $text,
        ?array $replyMarkup = null,
        string $textFormat = \App\Models\Message::TEXT_FORMAT_PLAIN_TEXT,
    ): AutoReplyDeliveryResult
    {
        if (! filled($externalChatId)) {
            throw new InvalidArgumentException("Telegram message for channel [{$channel->id}] does not have chat id.");
        }

        $payload = [
            'chat_id' => $externalChatId,
            'text' => $text,
        ];

        if ($textFormat === \App\Models\Message::TEXT_FORMAT_HTML) {
            $payload['parse_mode'] = 'HTML';
        }

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $response = Http::asJson()
            ->post(
                sprintf('https://api.telegram.org/bot%s/sendMessage', $this->token($channel)),
                $payload,
            )
            ->throw()
            ->json();

        $rawPayload = is_array($response)
            ? $response
            : ['response' => $response];

        return new AutoReplyDeliveryResult(
            text: $text,
            externalMessageId: filled(data_get($rawPayload, 'result.message_id'))
                ? (string) data_get($rawPayload, 'result.message_id')
                : null,
            rawPayload: $rawPayload,
        );
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

    public function answerCallbackQuery(Channel $channel, string $callbackQueryId, ?string $text = null): void
    {
        $payload = [
            'callback_query_id' => $callbackQueryId,
        ];

        if (filled($text)) {
            $payload['text'] = $text;
        }

        Http::asJson()
            ->post(
                sprintf('https://api.telegram.org/bot%s/answerCallbackQuery', $this->token($channel)),
                $payload,
            )
            ->throw();
    }

    public function fetchBotMetadata(Channel $channel): BotMetadata
    {
        $response = Http::asJson()
            ->get(sprintf('https://api.telegram.org/bot%s/getMe', $this->token($channel)))
            ->throw()
            ->json();

        $bot = data_get($response, 'result');

        if (! is_array($bot)) {
            throw new InvalidArgumentException("Telegram API did not return bot metadata for channel [{$channel->id}].");
        }

        $username = filled(data_get($bot, 'username')) ? ltrim((string) data_get($bot, 'username'), '@') : null;
        $name = trim(implode(' ', array_filter([
            data_get($bot, 'first_name'),
            data_get($bot, 'last_name'),
        ], fn (mixed $value): bool => filled($value))));

        return new BotMetadata(
            externalId: filled(data_get($bot, 'id')) ? (string) data_get($bot, 'id') : null,
            username: $username,
            name: filled($name) ? Str::limit($name, 255, '') : null,
            profileUrl: filled($username) ? 'https://t.me/'.$username : null,
        );
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
