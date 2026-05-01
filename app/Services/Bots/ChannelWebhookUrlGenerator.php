<?php

namespace App\Services\Bots;

use App\Models\Channel;
use InvalidArgumentException;
use Throwable;

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
        return $this->publicRequestBaseUrl() ?? rtrim((string) config('app.url'), '/');
    }

    protected function publicRequestBaseUrl(): ?string
    {
        try {
            $request = request();
        } catch (Throwable) {
            return null;
        }

        $scheme = $this->firstForwardedValue((string) ($request->headers->get('x-forwarded-proto') ?: $request->getScheme()));

        if (strtolower($scheme) !== 'https') {
            return null;
        }

        $host = $this->firstForwardedValue((string) ($request->headers->get('x-forwarded-host') ?: $request->getHttpHost()));
        [$hostOnly, $port] = $this->splitHostAndPort($host);

        if (! $this->isPublicHost($hostOnly)) {
            return null;
        }

        return 'https://'.$hostOnly.($port !== null && $port !== 443 ? ':'.$port : '');
    }

    protected function firstForwardedValue(string $value): string
    {
        return trim(explode(',', $value)[0] ?? '');
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    protected function splitHostAndPort(string $host): array
    {
        $host = trim($host);

        if (str_starts_with($host, '[') && preg_match('/^\[([^\]]+)](?::(\d+))?$/', $host, $matches)) {
            return [strtolower($matches[1]), isset($matches[2]) ? (int) $matches[2] : null];
        }

        if (substr_count($host, ':') === 1) {
            [$hostOnly, $port] = explode(':', $host, 2);

            return [strtolower($hostOnly), ctype_digit($port) ? (int) $port : null];
        }

        return [strtolower($host), null];
    }

    protected function isPublicHost(string $host): bool
    {
        if ($host === '' || in_array($host, ['localhost', '0.0.0.0', '127.0.0.1', '::1'], true)) {
            return false;
        }

        if (str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return str_contains($host, '.');
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
