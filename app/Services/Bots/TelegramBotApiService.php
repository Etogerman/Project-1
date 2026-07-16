<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Data\Bots\BotMetadata;
use App\Data\Bots\DownloadedAvatarData;
use App\Data\Bots\IncomingBotMessage;
use App\Data\Bots\TelegramChatAvatarFetchResult;
use App\Data\Messages\DownloadedMediaStreamData;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Messages\StreamHttpResponseToTemporaryFileAction;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class TelegramBotApiService
{
    public function __construct(
        private readonly StreamHttpResponseToTemporaryFileAction $streamHttpResponseToTemporaryFileAction,
    ) {}

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
                $this->apiMethodUrl($channel, 'sendMessage'),
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
                $this->apiMethodUrl($channel, 'deleteMessage'),
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
                $this->apiMethodUrl($channel, 'setWebhook'),
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
            ->get($this->apiMethodUrl($channel, 'getWebhookInfo'))
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
                $this->apiMethodUrl($channel, 'answerCallbackQuery'),
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
                $this->apiMethodUrl($channel, 'editMessageReplyMarkup'),
                $payload,
            )
            ->throw();
    }

    public function fetchBotMetadata(Channel $channel): BotMetadata
    {
        $response = Http::asJson()
            ->get($this->apiMethodUrl($channel, 'getMe'))
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
        $usesLocalApi = $this->usesLocalApi();
        $apiBaseUrl = $this->apiBaseUrl($usesLocalApi);
        $chatResponse = Http::asJson()
            ->get(
                sprintf('%s/bot%s/getChat', $apiBaseUrl, $this->token($channel)),
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
                sprintf('%s/bot%s/getFile', $apiBaseUrl, $this->token($channel)),
                ['file_id' => (string) $fileId],
            )
            ->throw()
            ->json();

        $filePath = data_get($fileResponse, 'result.file_path');

        if (! filled($filePath)) {
            throw new InvalidArgumentException("Telegram API did not return avatar file path for channel [{$channel->id}] and chat [{$chatId}].");
        }

        if ($usesLocalApi) {
            $localPath = $this->resolveLocalApiFilePath((string) $filePath);
            $contents = file_get_contents($localPath);

            if (! is_string($contents) || $contents === '') {
                throw new InvalidArgumentException("Telegram API returned an empty avatar file for channel [{$channel->id}] and chat [{$chatId}].");
            }

            return TelegramChatAvatarFetchResult::avatar(
                new DownloadedAvatarData(
                    contents: $contents,
                    filenameHint: basename($localPath),
                ),
            );
        }

        $downloadResponse = Http::timeout(15)
            ->get(sprintf('%s/file/bot%s/%s', $apiBaseUrl, $this->token($channel), ltrim((string) $filePath, '/')))
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

    /**
     * @param  Closure(int): void|null  $onProgress
     */
    public function downloadFileToStream(
        Channel $channel,
        string $fileId,
        int $maxBytes,
        ?Closure $onProgress = null,
    ): DownloadedMediaStreamData {
        if (! filled($fileId)) {
            throw new InvalidArgumentException("Telegram file id is required for channel [{$channel->id}].");
        }

        $usesLocalApi = $this->usesLocalApi();
        $apiBaseUrl = $this->apiBaseUrl($usesLocalApi);
        $fileResponse = Http::asJson()
            ->get(
                sprintf('%s/bot%s/getFile', $apiBaseUrl, $this->token($channel)),
                ['file_id' => $fileId],
            )
            ->throw()
            ->json();

        $filePath = data_get($fileResponse, 'result.file_path');
        $fileSize = data_get($fileResponse, 'result.file_size');
        $providerDeclaredSizeBytes = is_numeric($fileSize) && (int) $fileSize >= 0
            ? (int) $fileSize
            : null;

        if (is_numeric($fileSize) && (int) $fileSize > $maxBytes) {
            throw new InvalidArgumentException('Telegram Bot media file is larger than the local download limit.');
        }

        if (! filled($filePath)) {
            throw new InvalidArgumentException("Telegram API did not return file path for channel [{$channel->id}].");
        }

        if ($usesLocalApi) {
            return $this->streamLocalApiFile(
                (string) $filePath,
                $maxBytes,
                $onProgress,
            )->withMetadata([
                'provider_declared_size_bytes' => $providerDeclaredSizeBytes,
            ]);
        }

        $downloadResponse = Http::withOptions([
            'stream' => true,
            'allow_redirects' => false,
            'connect_timeout' => 10,
            'read_timeout' => max(
                1,
                min(
                    90,
                    max(1, (int) config('inbound_media.lease_stale_seconds', 120)) - 30,
                ),
            ),
            'timeout' => max(30, (int) config('inbound_media.attempt_deadline_seconds', 6 * 60 * 60)),
        ])
            ->get(sprintf('%s/file/bot%s/%s', $apiBaseUrl, $this->token($channel), ltrim((string) $filePath, '/')))
            ->throw();

        return $this->streamHttpResponseToTemporaryFileAction->handle(
            response: $downloadResponse,
            maxBytes: $maxBytes,
            filenameHint: (string) $filePath,
            metadata: [
                'provider_declared_size_bytes' => $providerDeclaredSizeBytes,
            ],
            onProgress: $onProgress,
            tooLargeMessage: 'Telegram Bot media file is larger than the local download limit.',
            emptyMessage: "Telegram API returned an empty file for channel [{$channel->id}].",
        );
    }

    private function apiMethodUrl(Channel $channel, string $method): string
    {
        return sprintf(
            '%s/bot%s/%s',
            $this->apiBaseUrl($this->usesLocalApi()),
            $this->token($channel),
            $method,
        );
    }

    private function usesLocalApi(): bool
    {
        return (bool) config('bots.telegram.local_api_media_download_enabled', false);
    }

    private function apiBaseUrl(bool $usesLocalApi): string
    {
        $configured = $usesLocalApi
            ? config('bots.telegram.local_api_base_url')
            : config('bots.telegram.cloud_api_base_url', 'https://api.telegram.org');
        $baseUrl = rtrim(trim(is_string($configured) ? $configured : ''), '/');

        if ($baseUrl === '') {
            throw new InvalidArgumentException('Telegram Local Bot API base URL is not configured.');
        }

        $parts = parse_url($baseUrl);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('Telegram Bot API base URL is invalid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (
            ! in_array($scheme, ['http', 'https'], true)
            || blank($parts['host'] ?? null)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            throw new InvalidArgumentException('Telegram Bot API base URL is invalid.');
        }

        if ($usesLocalApi) {
            $host = mb_strtolower(rtrim((string) $parts['host'], '.'));
            $trustedHosts = array_values(array_filter(
                (array) config('bots.telegram.local_api_trusted_hosts', []),
                static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
            ));
            $trustedHosts = array_map(
                static fn (string $value): string => mb_strtolower(rtrim(trim($value), '.')),
                $trustedHosts,
            );

            if (! in_array($host, $trustedHosts, true)) {
                throw new InvalidArgumentException('Telegram Local Bot API host is not trusted.');
            }
        }

        return $baseUrl;
    }

    /**
     * @param  Closure(int): void|null  $onProgress
     */
    private function streamLocalApiFile(
        string $filePath,
        int $maxBytes,
        ?Closure $onProgress,
    ): DownloadedMediaStreamData {
        $path = $this->resolveLocalApiFilePath($filePath);

        $source = fopen($path, 'rb');

        if ($source === false) {
            throw new RuntimeException('Failed to open Telegram Local Bot API media stream.');
        }

        try {
            $fileSize = filesize($path);

            return $this->streamHttpResponseToTemporaryFileAction->handleStream(
                source: $source,
                maxBytes: $maxBytes,
                expectedLength: is_int($fileSize) ? $fileSize : null,
                filenameHint: basename($path),
                onProgress: $onProgress,
                tooLargeMessage: 'Telegram Bot media file is larger than the local download limit.',
                emptyMessage: 'Telegram Local Bot API returned an empty file.',
            );
        } finally {
            fclose($source);
        }
    }

    private function resolveLocalApiFilePath(string $filePath): string
    {
        $configuredRoot = config('bots.telegram.local_api_files_root');
        $root = realpath(is_string($configuredRoot) ? $configuredRoot : '');
        $path = realpath($filePath);

        if ($root === false || $path === false || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Telegram Local Bot API media path is unavailable.');
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if ($path !== $root && ! str_starts_with($path, $rootPrefix)) {
            throw new InvalidArgumentException('Telegram Local Bot API media path is outside the configured root.');
        }

        return $path;
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
