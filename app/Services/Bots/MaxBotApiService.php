<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Data\Bots\BotMetadata;
use App\Data\Bots\IncomingBotMessage;
use App\Data\Bots\MaxChatAvatarData;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MaxBotApiService
{
    private const DIALOG_SUSPENDED_ERROR_CODE = 'chat.denied';

    private const DIALOG_SUSPENDED_MESSAGE_KEY = 'error.dialog.suspended';

    public function sendAutoReply(Channel $channel, IncomingBotMessage $message, string $text): AutoReplyDeliveryResult
    {
        return $this->sendTextMessage($channel, $message->externalChatId, $message->externalUserId, $text);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $attachments
     */
    public function sendTextMessage(
        Channel $channel,
        ?string $externalChatId,
        ?string $externalUserId,
        string $text,
        ?array $attachments = null,
        string $textFormat = Message::TEXT_FORMAT_PLAIN_TEXT,
    ): AutoReplyDeliveryResult {
        $query = [];

        if (filled($externalChatId)) {
            $query['chat_id'] = $externalChatId;
        } elseif (filled($externalUserId)) {
            $query['user_id'] = $externalUserId;
        } else {
            throw new InvalidArgumentException("MAX message for channel [{$channel->id}] does not have chat or user id.");
        }

        $payload = [
            'text' => $text,
        ];

        if ($textFormat === Message::TEXT_FORMAT_HTML) {
            $payload['format'] = 'html';
        }

        if ($attachments !== null && $attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        $response = $this->client($channel)
            ->post(
                'https://platform-api.max.ru/messages?'.http_build_query($query),
                $payload,
            );

        if ($this->isDialogSuspendedResponse($response)) {
            throw MaxDialogSuspendedException::fromResponse($response);
        }

        $response = $response
            ->throw()
            ->json();

        $rawPayload = is_array($response)
            ? $response
            : ['response' => $response];

        return new AutoReplyDeliveryResult(
            text: $text,
            externalMessageId: $this->resolveSentMessageId($rawPayload),
            rawPayload: $rawPayload,
        );
    }

    public function deleteMessage(Channel $channel, string $messageId): void
    {
        if (! filled($messageId)) {
            throw new InvalidArgumentException("MAX message for channel [{$channel->id}] does not have message id.");
        }

        $this->client($channel)
            ->delete(
                'https://platform-api.max.ru/messages?'.http_build_query([
                    'message_id' => $messageId,
                ]),
            )
            ->throw();
    }

    public function registerWebhook(Channel $channel, string $url, string $secret): void
    {
        $client = $this->client($channel);

        $subscriptionsResponse = $client
            ->get('https://platform-api.max.ru/subscriptions')
            ->throw()
            ->json();

        $subscriptions = data_get($subscriptionsResponse, 'subscriptions', $subscriptionsResponse);

        if (is_array($subscriptions)) {
            foreach ($subscriptions as $subscription) {
                $subscriptionUrl = is_array($subscription) ? data_get($subscription, 'url') : null;

                if (! filled($subscriptionUrl)) {
                    continue;
                }

                $client
                    ->delete('https://platform-api.max.ru/subscriptions?'.http_build_query([
                        'url' => $subscriptionUrl,
                    ]))
                    ->throw();
            }
        }

        $client
            ->post('https://platform-api.max.ru/subscriptions', [
                'url' => $url,
                'secret' => $secret,
                'update_types' => (array) config('bots.max.update_types', ['message_created']),
            ])
            ->throw();
    }

    public function fetchBotMetadata(Channel $channel): BotMetadata
    {
        $bot = $this->client($channel)
            ->get('https://platform-api.max.ru/me')
            ->throw()
            ->json();

        if (! is_array($bot)) {
            throw new InvalidArgumentException("MAX API did not return bot metadata for channel [{$channel->id}].");
        }

        $username = filled(data_get($bot, 'username')) ? ltrim((string) data_get($bot, 'username'), '@') : null;
        $name = filled(data_get($bot, 'name'))
            ? trim((string) data_get($bot, 'name'))
            : trim(implode(' ', array_filter([
                data_get($bot, 'first_name'),
                data_get($bot, 'last_name'),
            ], fn (mixed $value): bool => filled($value))));

        return new BotMetadata(
            externalId: filled(data_get($bot, 'user_id')) ? (string) data_get($bot, 'user_id') : null,
            username: $username,
            name: filled($name) ? Str::limit($name, 255, '') : null,
            profileUrl: filled($username) ? 'https://max.ru/'.$username : null,
        );
    }

    /**
     * @return list<string>
     */
    public function fetchWebhookUrls(Channel $channel): array
    {
        $subscriptionsResponse = $this->client($channel)
            ->get('https://platform-api.max.ru/subscriptions')
            ->throw()
            ->json();

        $subscriptions = data_get($subscriptionsResponse, 'subscriptions', $subscriptionsResponse);

        if (! is_array($subscriptions)) {
            return [];
        }

        $urls = [];

        foreach ($subscriptions as $subscription) {
            $url = is_array($subscription) ? data_get($subscription, 'url') : null;

            if (filled($url)) {
                $urls[] = trim((string) $url);
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }

    public function fetchChatAvatarData(Channel $channel, string $externalChatId): MaxChatAvatarData
    {
        if (! filled($externalChatId)) {
            throw new InvalidArgumentException("MAX chat id is required for channel [{$channel->id}].");
        }

        $chat = $this->client($channel)
            ->get('https://platform-api.max.ru/chats/'.urlencode($externalChatId))
            ->throw()
            ->json();

        if (! is_array($chat)) {
            throw new InvalidArgumentException("MAX API did not return chat metadata for channel [{$channel->id}] chat [{$externalChatId}].");
        }

        $dialogWithUser = data_get($chat, 'dialog_with_user');

        if (! is_array($dialogWithUser)) {
            throw new InvalidArgumentException("MAX API did not return dialog_with_user for channel [{$channel->id}] chat [{$externalChatId}].");
        }

        return new MaxChatAvatarData(
            avatarUrl: $this->normalizeOptionalUrl(data_get($dialogWithUser, 'avatar_url')),
            fullAvatarUrl: $this->normalizeOptionalUrl(data_get($dialogWithUser, 'full_avatar_url')),
        );
    }

    protected function client(Channel $channel): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => $this->token($channel),
        ])->asJson();
    }

    protected function isDialogSuspendedResponse(Response $response): bool
    {
        if ($response->status() !== 403) {
            return false;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return false;
        }

        return data_get($payload, 'code') === self::DIALOG_SUSPENDED_ERROR_CODE
            && str_contains((string) data_get($payload, 'message', ''), self::DIALOG_SUSPENDED_MESSAGE_KEY);
    }

    protected function token(Channel $channel): string
    {
        $token = $channel->getToken();

        if (! filled($token)) {
            throw new InvalidArgumentException("Channel [{$channel->id}] does not have a bot token.");
        }

        return $token;
    }

    protected function normalizeOptionalUrl(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveSentMessageId(array $payload): ?string
    {
        $messageId = data_get($payload, 'message.body.mid')
            ?? data_get($payload, 'message.message_id')
            ?? data_get($payload, 'message.id')
            ?? data_get($payload, 'body.mid')
            ?? data_get($payload, 'message_id')
            ?? data_get($payload, 'id');

        if (! filled($messageId)) {
            return null;
        }

        return trim((string) $messageId);
    }
}
