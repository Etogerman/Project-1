<?php

namespace App\Services\Bots;

use App\Models\Channel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Throwable;

class CheckChannelConnectionAction
{
    public function __construct(
        protected ChannelWebhookUrlGenerator $channelWebhookUrlGenerator,
        protected TelegramBotApiService $telegramBotApiService,
        protected MaxBotApiService $maxBotApiService,
    ) {}

    /**
     * @return array{connection_status: string, webhook_status: string, connection_error_message: ?string, provider_webhook_url: ?string, expected_webhook_url: ?string, connection_checked_at: mixed}
     */
    public function handle(Channel $channel, ?string $confirmationFailureMessage = null): array
    {
        $state = $this->resolveState($channel, $confirmationFailureMessage);

        $channel->forceFill($state)->saveQuietly();

        return $state;
    }

    /**
     * @return array{connection_status: string, webhook_status: string, connection_error_message: ?string, provider_webhook_url: ?string, expected_webhook_url: ?string, connection_checked_at: mixed}
     */
    public function resolveEffectiveState(Channel $channel): array
    {
        $localState = $this->resolveLocalState($channel, false);

        if ($localState !== null) {
            return $localState;
        }

        $checkedAt = $channel->connection_checked_at;

        if ($checkedAt === null) {
            return $this->notConnectedState(Channel::CONNECTION_ERROR_NOT_CHECKED);
        }

        $expectedWebhookUrl = $this->safeExpectedWebhookUrl($channel);

        if (
            $expectedWebhookUrl !== null
            && filled($channel->expected_webhook_url)
            && ! $this->webhookUrlsMatch($expectedWebhookUrl, (string) $channel->expected_webhook_url)
        ) {
            return $this->notConnectedState(
                Channel::CONNECTION_ERROR_EXPECTED_URL_CHANGED,
                $expectedWebhookUrl,
                filled($channel->provider_webhook_url) ? (string) $channel->provider_webhook_url : null,
                $checkedAt,
            );
        }

        if ($checkedAt->lt(now()->subMinutes(2))) {
            return $this->notConnectedState(
                Channel::CONNECTION_ERROR_STALE,
                $expectedWebhookUrl ?? (filled($channel->expected_webhook_url) ? (string) $channel->expected_webhook_url : null),
                filled($channel->provider_webhook_url) ? (string) $channel->provider_webhook_url : null,
                $checkedAt,
            );
        }

        return [
            'connection_status' => $channel->connection_status ?? Channel::CONNECTION_STATUS_NOT_CONNECTED,
            'webhook_status' => $channel->webhook_status ?? Channel::WEBHOOK_STATUS_NOT_INSTALLED,
            'connection_error_message' => filled($channel->connection_error_message) ? (string) $channel->connection_error_message : null,
            'provider_webhook_url' => filled($channel->provider_webhook_url) ? (string) $channel->provider_webhook_url : null,
            'expected_webhook_url' => filled($channel->expected_webhook_url) ? (string) $channel->expected_webhook_url : $expectedWebhookUrl,
            'connection_checked_at' => $checkedAt,
        ];
    }

    /**
     * @return array{connection_status: string, webhook_status: string, connection_error_message: ?string, provider_webhook_url: ?string, expected_webhook_url: ?string, connection_checked_at: mixed}
     */
    protected function resolveState(Channel $channel, ?string $confirmationFailureMessage = null): array
    {
        $localState = $this->resolveLocalState($channel, true);

        if ($localState !== null) {
            return $localState;
        }

        $expectedWebhookUrl = $this->safeExpectedWebhookUrl($channel);

        if ($expectedWebhookUrl === null) {
            return $this->notConnectedState('Не удалось построить ожидаемый webhook URL', null, null, now());
        }

        try {
            $providerWebhookUrls = $this->fetchProviderWebhookUrls($channel);
            $providerWebhookUrl = $this->selectProviderWebhookUrl($expectedWebhookUrl, $providerWebhookUrls);

            if ($providerWebhookUrl === null) {
                return $this->notConnectedState(
                    $confirmationFailureMessage ?? 'Webhook не установлен',
                    $expectedWebhookUrl,
                    null,
                    now(),
                );
            }

            if (! $this->webhookUrlsMatch($expectedWebhookUrl, $providerWebhookUrl)) {
                return $this->notConnectedState(
                    $confirmationFailureMessage ?? 'Webhook установлен не на эту админку',
                    $expectedWebhookUrl,
                    $providerWebhookUrl,
                    now(),
                );
            }

            return [
                'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
                'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
                'connection_error_message' => null,
                'provider_webhook_url' => $providerWebhookUrl,
                'expected_webhook_url' => $expectedWebhookUrl,
                'connection_checked_at' => now(),
            ];
        } catch (RequestException $throwable) {
            return $this->notConnectedState(
                $this->formatRequestExceptionMessage($throwable, $channel),
                $expectedWebhookUrl,
                null,
                now(),
            );
        } catch (ConnectionException) {
            return $this->notConnectedState($this->genericCheckFailureMessage($channel), $expectedWebhookUrl, null, now());
        } catch (Throwable) {
            return $this->notConnectedState($this->genericCheckFailureMessage($channel), $expectedWebhookUrl, null, now());
        }
    }

    /**
     * @return list<string>
     */
    protected function fetchProviderWebhookUrls(Channel $channel): array
    {
        if ($channel->platform === Channel::PLATFORM_TELEGRAM) {
            $webhookInfo = $this->telegramBotApiService->fetchWebhookInfo($channel);
            $url = data_get($webhookInfo, 'url');

            return filled($url) ? [trim((string) $url)] : [];
        }

        if ($channel->platform === Channel::PLATFORM_MAX) {
            return $this->maxBotApiService->fetchWebhookUrls($channel);
        }

        return [];
    }

    /**
     * @param  list<string>  $providerWebhookUrls
     */
    protected function selectProviderWebhookUrl(string $expectedWebhookUrl, array $providerWebhookUrls): ?string
    {
        foreach ($providerWebhookUrls as $providerWebhookUrl) {
            if ($this->webhookUrlsMatch($expectedWebhookUrl, $providerWebhookUrl)) {
                return $providerWebhookUrl;
            }
        }

        return $providerWebhookUrls[0] ?? null;
    }

    /**
     * @return array{connection_status: string, webhook_status: string, connection_error_message: ?string, provider_webhook_url: ?string, expected_webhook_url: ?string, connection_checked_at: mixed}|null
     */
    protected function resolveLocalState(Channel $channel, bool $markCheckedAt): ?array
    {
        if (! $channel->supportsConnectionCheck()) {
            return [
                'connection_status' => Channel::CONNECTION_STATUS_UNSUPPORTED,
                'webhook_status' => Channel::WEBHOOK_STATUS_UNSUPPORTED,
                'connection_error_message' => Channel::CONNECTION_ERROR_UNSUPPORTED,
                'provider_webhook_url' => null,
                'expected_webhook_url' => null,
                'connection_checked_at' => $markCheckedAt ? now() : $channel->connection_checked_at,
            ];
        }

        if (! $channel->is_active) {
            return $this->notConnectedState(Channel::CONNECTION_ERROR_DISABLED, null, null, $markCheckedAt ? now() : $channel->connection_checked_at);
        }

        if (! $channel->hasBotTokenConfigured()) {
            return $this->notConnectedState(Channel::CONNECTION_ERROR_NO_TOKEN, null, null, $markCheckedAt ? now() : $channel->connection_checked_at);
        }

        return null;
    }

    /**
     * @return array{connection_status: string, webhook_status: string, connection_error_message: ?string, provider_webhook_url: ?string, expected_webhook_url: ?string, connection_checked_at: mixed}
     */
    protected function notConnectedState(
        string $message,
        ?string $expectedWebhookUrl = null,
        ?string $providerWebhookUrl = null,
        mixed $checkedAt = null,
    ): array {
        return [
            'connection_status' => Channel::CONNECTION_STATUS_NOT_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_NOT_INSTALLED,
            'connection_error_message' => Str::limit(trim($message), 1000, ''),
            'provider_webhook_url' => $providerWebhookUrl,
            'expected_webhook_url' => $expectedWebhookUrl,
            'connection_checked_at' => $checkedAt,
        ];
    }

    protected function safeExpectedWebhookUrl(Channel $channel): ?string
    {
        try {
            return $this->channelWebhookUrlGenerator->for($channel);
        } catch (Throwable) {
            return null;
        }
    }

    protected function webhookUrlsMatch(string $expectedWebhookUrl, string $providerWebhookUrl): bool
    {
        $expected = $this->normalizeWebhookUrl($expectedWebhookUrl);
        $provider = $this->normalizeWebhookUrl($providerWebhookUrl);

        return $expected !== null
            && $provider !== null
            && $expected === $provider;
    }

    /**
     * @return array{scheme: string, host: string, port: ?int, path: string, query: string}|null
     */
    protected function normalizeWebhookUrl(string $url): ?array
    {
        $trimmed = trim($url);

        if (str_ends_with($trimmed, '/')) {
            $trimmed = substr($trimmed, 0, -1);
        }

        $parts = parse_url($trimmed);

        if (! is_array($parts) || ! filled($parts['scheme'] ?? null) || ! filled($parts['host'] ?? null)) {
            return null;
        }

        return [
            'scheme' => strtolower((string) $parts['scheme']),
            'host' => strtolower((string) $parts['host']),
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'path' => (string) ($parts['path'] ?? ''),
            'query' => (string) ($parts['query'] ?? ''),
        ];
    }

    protected function formatRequestExceptionMessage(RequestException $throwable, Channel $channel): string
    {
        $status = $throwable->response?->status();

        if (in_array($status, [401, 403, 404], true)) {
            return sprintf('Токен не принят %s', $this->providerLabel($channel));
        }

        return $this->genericCheckFailureMessage($channel);
    }

    protected function genericCheckFailureMessage(Channel $channel): string
    {
        return sprintf('Не удалось проверить %s', $this->providerLabel($channel));
    }

    protected function providerLabel(Channel $channel): string
    {
        return Channel::platformOptions()[$channel->platform] ?? (string) $channel->platform;
    }
}
