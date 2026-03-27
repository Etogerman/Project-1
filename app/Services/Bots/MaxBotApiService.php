<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
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
