<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Data\Bots\BotMetadata;
use App\Data\Bots\DownloadedAvatarData;
use App\Data\Bots\IncomingBotMessage;
use App\Data\Bots\TelegramChatAvatarFetchResult;
use App\Models\Channel;
use App\Models\Message;
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
        string $textFormat = Message::TEXT_FORMAT_PLAIN_TEXT,
        bool $disableNotification = false,
    ): AutoReplyDeliveryResult {
        if (! filled($externalChatId)) {
            throw new InvalidArgumentException("Telegram message for channel [{$channel->id}] does not have chat id.");
        }

        $payload = [
            'chat_id' => $externalChatId,
            'text' => $text,
        ];

        if ($disableNotification) {
            $payload['disable_notification'] = true;
        }

        if ($textFormat === Message::TEXT_FORMAT_HTML) {
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

    public function deleteMessage(Channel $channel, ?string $externalChatId, string $messageId): void
    {
        if (! filled($externalChatId)) {
            throw new InvalidArgumentException("Telegram message for channel [{$channel->id}] does not have chat id.");
        }

        Http::asJson()
            ->post(
                sprintf('https://api.telegram.org/bot%s/deleteMessage', $this->token($channel)),
                [
                    'chat_id' => $externalChatId,
                    'message_id' => $messageId,
                ],
            )
            ->throw();
    }

    public function registerWebhook(Channel $channel, string $url, string $secret): void
    {
        $payload = [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => (array) config('bots.telegram.allowed_updates', ['message']),
        ];

        $webhookIpAddress = trim((string) config('bots.telegram.webhook_ip_address', ''));

        if ($webhookIpAddress !== '') {
            $payload['ip_address'] = $webhookIpAddress;
        }

        Http::asJson()
            ->post(
                sprintf('https://api.telegram.org/bot%s/setWebhook', $this->token($channel)),
                $payload,
            )
            ->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchWebhookInfo(Channel $channel): array
    {
        $response = Http::timeout(10)
            ->asJson()
            ->get(sprintf('https://api.telegram.org/bot%s/getWebhookInfo', $this->token($channel)))
            ->throw()
            ->json();

        $webhookInfo = data_get($response, 'result');

        if (! is_array($webhookInfo)) {
            throw new InvalidArgumentException("Telegram API did not return webhook info for channel [{$channel->id}].");
        }

        return $webhookInfo;
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

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editMessageReplyMarkup(Channel $channel, string $chatId, string $messageId, ?array $replyMarkup = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        Http::asJson()
            ->post(
                sprintf('https://api.telegram.org/bot%s/editMessageReplyMarkup', $this->token($channel)),
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

    public function downloadChatAvatar(Channel $channel, string $chatId): TelegramChatAvatarFetchResult
    {
        $chatResponse = Http::asJson()
            ->get(
                sprintf('https://api.telegram.org/bot%s/getChat', $this->token($channel)),
                ['chat_id' => $chatId],
            )
            ->throw()
            ->json();

        $fileId = data_get($chatResponse, 'result.photo.big_file_id')
            ?? data_get($chatResponse, 'result.photo.small_file_id');

        if (! filled($fileId)) {
            return TelegramChatAvatarFetchResult::photoMissing();
        }

        $fileResponse = Http::asJson()
            ->get(
                sprintf('https://api.telegram.org/bot%s/getFile', $this->token($channel)),
                ['file_id' => (string) $fileId],
            )
            ->throw()
            ->json();

        $filePath = data_get($fileResponse, 'result.file_path');

        if (! filled($filePath)) {
            throw new InvalidArgumentException("Telegram API did not return avatar file path for channel [{$channel->id}] and chat [{$chatId}].");
        }

        $downloadResponse = Http::timeout(15)
            ->get(sprintf('https://api.telegram.org/file/bot%s/%s', $this->token($channel), ltrim((string) $filePath, '/')))
            ->throw();

        if ($downloadResponse->body() === '') {
            throw new InvalidArgumentException("Telegram API returned an empty avatar file for channel [{$channel->id}] and chat [{$chatId}].");
        }

        return TelegramChatAvatarFetchResult::avatar(
            new DownloadedAvatarData(
                contents: $downloadResponse->body(),
                contentType: $downloadResponse->header('Content-Type'),
                filenameHint: (string) $filePath,
            ),
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
