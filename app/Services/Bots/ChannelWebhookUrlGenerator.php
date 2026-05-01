<?php

namespace App\Services\Bots;

use App\Models\Channel;
use InvalidArgumentException;

class ChannelWebhookUrlGenerator
{
    public function for(Channel $channel): string
    {
        $baseUrl = $this->baseUrl();

        if ($baseUrl === '') {
            throw new InvalidArgumentException('Не задан APP_URL для генерации webhook URL.');
        }

        $path = match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => route('webhooks.telegram.handle', ['channel' => $channel], absolute: false),
            Channel::PLATFORM_MAX => route('webhooks.max.handle', ['channel' => $channel], absolute: false),
            default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
        };

        return $baseUrl.$path;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public function ensureHttps(Channel $channel): string
    {
        $url = $this->for($channel);

        if (! str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException('Для регистрации webhook укажите публичный HTTPS APP_URL.');
        }

        return $url;
    }
}
