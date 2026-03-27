<?php

namespace App\Services\Bots;

use App\Data\Bots\BotMetadata;
use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MaxBotApiService
{
    public function sendAutoReply(Channel $channel, IncomingBotMessage $message): void
    {
        $query = [];

        if (filled($message->externalChatId)) {
            $query['chat_id'] = $message->externalChatId;
        } elseif (filled($message->externalUserId)) {
            $query['user_id'] = $message->externalUserId;
        } else {
            throw new InvalidArgumentException("MAX message for channel [{$channel->id}] does not have chat or user id.");
        }

        $this->client($channel)
            ->post(
                'https://platform-api.max.ru/messages?'.http_build_query($query),
                [
                    'text' => (string) config('bots.default_auto_reply_text'),
                ],
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
        $name = trim(implode(' ', array_filter([
            data_get($bot, 'first_name'),
            data_get($bot, 'last_name'),
            data_get($bot, 'name'),
        ], fn (mixed $value): bool => filled($value))));

        return new BotMetadata(
            externalId: filled(data_get($bot, 'user_id')) ? (string) data_get($bot, 'user_id') : null,
            username: $username,
            name: filled($name) ? Str::limit($name, 255, '') : null,
            profileUrl: filled($username) ? 'https://max.ru/'.$username : null,
        );
    }

    protected function client(Channel $channel): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => $this->token($channel),
        ])->asJson();
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
