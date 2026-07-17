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
use App\Support\TelegramLocalApiConfiguration;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TelegramBotApiService
{
    private const AVATAR_FILE_DOWNLOAD_TIMEOUT_SECONDS = 30;

    private const TRANSFER_PROGRESS_CHECKPOINT_BYTES = 1024 * 1024;

    private const TRANSFER_PROGRESS_CHECKPOINT_SECONDS = 30;

    private const LOCAL_API_FILE_TRANSPORT_FILESYSTEM = 'filesystem';

    private const LOCAL_API_FILE_TRANSPORT_HTTP_BRIDGE = 'http_bridge';

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

        $response = $this->apiRequest($this->usesLocalApi())
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

        $this->apiRequest($this->usesLocalApi())
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

        $this->apiRequest($this->usesLocalApi())
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
        $response = $this->apiRequest($this->usesLocalApi())
            ->timeout(10)
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

        $this->apiRequest($this->usesLocalApi())
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

        $this->apiRequest($this->usesLocalApi())
            ->post(
                $this->apiMethodUrl($channel, 'editMessageReplyMarkup'),
                $payload,
            )
            ->throw();
    }

    public function fetchBotMetadata(Channel $channel): BotMetadata
    {
        $response = $this->apiRequest($this->usesLocalApi())
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
        $chatResponse = $this->apiRequest($usesLocalApi)
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

        $fileResponse = $this->apiRequest($usesLocalApi)
            ->timeout(30)
            ->get(
                sprintf('%s/bot%s/getFile', $apiBaseUrl, $this->token($channel)),
                ['file_id' => (string) $fileId],
            )
            ->throw()
            ->json();

        $filePath = data_get($fileResponse, 'result.file_path');
        $fileSize = data_get($fileResponse, 'result.file_size');
        $providerDeclaredSizeBytes = $this->providerDeclaredSizeBytes($fileSize);

        if (! filled($filePath)) {
            throw new InvalidArgumentException("Telegram API did not return avatar file path for channel [{$channel->id}] and chat [{$chatId}].");
        }

        if ($usesLocalApi) {
            $maxBytes = max(1, (int) config('bots.media.download_max_bytes', 20 * 1024 * 1024));

            if ($providerDeclaredSizeBytes !== null && $providerDeclaredSizeBytes > $maxBytes) {
                throw new InvalidArgumentException('Telegram Bot media file is larger than the local download limit.');
            }

            $downloaded = $this->streamLocalApiFile(
                (string) $filePath,
                $maxBytes,
                null,
                $providerDeclaredSizeBytes,
                self::AVATAR_FILE_DOWNLOAD_TIMEOUT_SECONDS,
            );
            $contents = stream_get_contents($downloaded->stream);
            fclose($downloaded->stream);

            if (! is_string($contents) || $contents === '') {
                throw new InvalidArgumentException("Telegram API returned an empty avatar file for channel [{$channel->id}] and chat [{$chatId}].");
            }

            return TelegramChatAvatarFetchResult::avatar(
                new DownloadedAvatarData(
                    contents: $contents,
                    contentType: $downloaded->contentType,
                    filenameHint: $downloaded->filenameHint,
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
        $progressFailure = null;
        $heartbeat = null;

        if ($onProgress instanceof Closure) {
            $heartbeat = static function (int $receivedBytes) use ($onProgress, &$progressFailure): void {
                try {
                    $onProgress($receivedBytes);
                } catch (Throwable $throwable) {
                    $progressFailure = $throwable;

                    throw $throwable;
                }
            };
        }

        try {
            $fileResponse = $this->mediaApiRequest($usesLocalApi, $heartbeat)
                ->get(
                    sprintf('%s/bot%s/getFile', $apiBaseUrl, $this->token($channel)),
                    ['file_id' => $fileId],
                )
                ->throw()
                ->json();
        } catch (Throwable $throwable) {
            throw $progressFailure instanceof Throwable
                ? $progressFailure
                : $throwable;
        }

        $filePath = data_get($fileResponse, 'result.file_path');
        $fileSize = data_get($fileResponse, 'result.file_size');
        $providerDeclaredSizeBytes = $this->providerDeclaredSizeBytes($fileSize);

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
                $providerDeclaredSizeBytes,
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

    private function apiRequest(bool $usesLocalApi): PendingRequest
    {
        $request = Http::asJson();

        if (! $usesLocalApi) {
            return $request;
        }

        $request = $request->withoutRedirecting();

        [$username, $password] = $this->localApiCredentials();

        return $username === null
            ? $request
            : $request->withBasicAuth($username, $password ?? '');
    }

    /**
     * @param  Closure(int): void|null  $onProgress
     */
    private function mediaApiRequest(bool $usesLocalApi, ?Closure $onProgress = null): PendingRequest
    {
        $request = $this->apiRequest($usesLocalApi);

        if (! $usesLocalApi) {
            return $request->timeout(30);
        }

        $options = [
            'connect_timeout' => 10,
            'read_timeout' => $this->attemptDeadlineSeconds(),
            'timeout' => $this->attemptDeadlineSeconds(),
        ];

        if ($onProgress instanceof Closure) {
            // Local getFile may block while the companion downloads the file.
            // Guzzle's progress option keeps the attachment lease alive while
            // preserving CurlHandler compatibility across Guzzle versions.
            $options['progress'] = $this->heartbeatProgress($onProgress);
        }

        return $request->withOptions($options);
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function localApiCredentials(): array
    {
        $username = trim((string) config('bots.telegram.local_api_username', ''));
        $configuredPassword = config('bots.telegram.local_api_password');
        $password = is_string($configuredPassword) ? $configuredPassword : '';

        if ($username === '' && $password === '') {
            return [null, null];
        }

        if ($username === '' || $password === '') {
            throw new InvalidArgumentException('Telegram Local Bot API credentials are incomplete.');
        }

        return [$username, $password];
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

        if (
            $usesLocalApi
            && $scheme === 'http'
            && ! (bool) config('bots.telegram.local_api_allow_insecure_http', false)
        ) {
            throw new InvalidArgumentException('Telegram Local Bot API requires HTTPS outside an explicitly allowed local network.');
        }

        if ($usesLocalApi) {
            $host = TelegramLocalApiConfiguration::normalizedHost($parts['host'] ?? null);
            $trustedHosts = TelegramLocalApiConfiguration::normalizedTrustedHosts(
                config('bots.telegram.local_api_trusted_hosts', []),
            );

            if ($host === null || ! in_array($host, $trustedHosts, true)) {
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
        ?int $providerDeclaredSizeBytes,
        ?int $requestTimeoutSeconds = null,
    ): DownloadedMediaStreamData {
        if ($this->localApiFileTransport() === self::LOCAL_API_FILE_TRANSPORT_HTTP_BRIDGE) {
            return $this->streamLocalApiFileFromBridge(
                $filePath,
                $maxBytes,
                $onProgress,
                $providerDeclaredSizeBytes,
                $requestTimeoutSeconds,
            );
        }

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
                expectedLength: $providerDeclaredSizeBytes
                    ?? (is_int($fileSize) ? $fileSize : null),
                filenameHint: basename($path),
                onProgress: $onProgress,
                tooLargeMessage: 'Telegram Bot media file is larger than the local download limit.',
                emptyMessage: 'Telegram Local Bot API returned an empty file.',
            );
        } finally {
            fclose($source);
        }
    }

    /**
     * @param  Closure(int): void|null  $onProgress
     */
    private function streamLocalApiFileFromBridge(
        string $filePath,
        int $maxBytes,
        ?Closure $onProgress,
        ?int $providerDeclaredSizeBytes,
        ?int $requestTimeoutSeconds = null,
    ): DownloadedMediaStreamData {
        $progressFailure = null;
        $transferFailure = null;
        $heartbeat = null;
        $requestTimeoutSeconds ??= $this->attemptDeadlineSeconds();

        if ($onProgress instanceof Closure) {
            $heartbeat = static function (int $receivedBytes) use ($onProgress, &$progressFailure): void {
                try {
                    $onProgress($receivedBytes);
                } catch (Throwable $throwable) {
                    $progressFailure = $throwable;

                    throw $throwable;
                }
            };
        }

        $sinkData = $this->streamHttpResponseToTemporaryFileAction
            ->openTemporaryDownloadSink($providerDeclaredSizeBytes);
        $sink = $sinkData['stream'];

        try {
            $progress = $this->localApiFileBridgeProgress(
                onProgress: $heartbeat,
                maxBytes: $maxBytes,
                timeoutSeconds: $requestTimeoutSeconds,
                temporaryDirectory: $sinkData['directory'],
            );
            $trackedProgress = static function (
                int $downloadTotal,
                int $downloadedBytes,
                int $uploadTotal,
                int $uploadedBytes,
            ) use ($progress, &$transferFailure): void {
                try {
                    $progress($downloadTotal, $downloadedBytes, $uploadTotal, $uploadedBytes);
                } catch (Throwable $throwable) {
                    $transferFailure = $throwable;

                    throw $throwable;
                }
            };
            $response = $this->localApiFileBridgeRequest(
                sink: $sink,
                progress: $trackedProgress,
                requestTimeoutSeconds: $requestTimeoutSeconds,
            )
                ->get($this->localApiFileBridgeUrl($filePath))
                ->throw();

            $sink = $this->detachLocalApiFileBridgeSink($response, $sink);

            return $this->streamHttpResponseToTemporaryFileAction->finalizeTemporaryDownloadSink(
                response: $response,
                sink: $sink,
                maxBytes: $maxBytes,
                expectedLengthFallback: $providerDeclaredSizeBytes,
                filenameHint: basename(str_replace('\\', '/', $filePath)),
                onProgress: $heartbeat,
                tooLargeMessage: 'Telegram Bot media file is larger than the local download limit.',
                emptyMessage: 'Telegram Local Bot API file bridge returned an empty file.',
            );
        } catch (Throwable $throwable) {
            if (is_resource($sink)) {
                fclose($sink);
            }

            throw $progressFailure instanceof Throwable
                ? $progressFailure
                : ($transferFailure instanceof Throwable ? $transferFailure : $throwable);
        }
    }

    /**
     * Guzzle owns a resource passed through the sink option and closes it when
     * the response body is destroyed. Detach the prepared temporary file from
     * the response before returning it to the attachment storage pipeline.
     *
     * Laravel HTTP fakes do not bind their response body to the configured
     * sink, so those responses intentionally keep the original sink here.
     *
     * @param  resource  $sink
     * @return resource
     */
    private function detachLocalApiFileBridgeSink(
        Response $response,
        mixed $sink,
    ): mixed {
        if (! is_resource($sink)) {
            throw new RuntimeException('Telegram Local Bot API file bridge sink is unavailable.');
        }

        $body = $response->toPsrResponse()->getBody();
        $sinkMetadata = stream_get_meta_data($sink);
        $sinkUri = $sinkMetadata['uri'] ?? null;
        $bodyUri = $body->getMetadata('uri');

        if (! is_string($sinkUri) || $bodyUri !== $sinkUri) {
            return $sink;
        }

        $detached = $body->detach();

        if (! is_resource($detached) || $detached !== $sink) {
            if (is_resource($detached)) {
                fclose($detached);
            }

            throw new RuntimeException('Failed to transfer Telegram Local Bot API file bridge sink ownership.');
        }

        return $detached;
    }

    private function localApiFileTransport(): string
    {
        $transport = mb_strtolower(trim((string) config(
            'bots.telegram.local_api_file_transport',
            self::LOCAL_API_FILE_TRANSPORT_FILESYSTEM,
        )));

        if (! in_array($transport, [
            self::LOCAL_API_FILE_TRANSPORT_FILESYSTEM,
            self::LOCAL_API_FILE_TRANSPORT_HTTP_BRIDGE,
        ], true)) {
            throw new InvalidArgumentException('Telegram Local Bot API file transport is invalid.');
        }

        return $transport;
    }

    private function streamIdleTimeoutSeconds(): int
    {
        return max(
            1,
            min(
                90,
                max(1, (int) config('inbound_media.lease_stale_seconds', 120)) - 30,
            ),
        );
    }

    private function attemptDeadlineSeconds(): int
    {
        return max(30, (int) config('inbound_media.attempt_deadline_seconds', 6 * 60 * 60));
    }

    /**
     * @param  resource  $sink
     * @param  Closure(int, int, int, int): void  $progress
     */
    private function localApiFileBridgeRequest(
        mixed $sink,
        Closure $progress,
        int $requestTimeoutSeconds,
    ): PendingRequest {
        [$username, $password] = $this->localApiFileBridgeCredentials();
        $idleTimeoutSeconds = min(
            $this->streamIdleTimeoutSeconds(),
            max(1, $requestTimeoutSeconds),
        );

        return Http::withBasicAuth($username, $password)
            ->withoutRedirecting()
            ->withOptions([
                'sink' => $sink,
                'connect_timeout' => 10,
                'timeout' => max(1, $requestTimeoutSeconds),
                'progress' => $progress,
                'curl' => [
                    CURLOPT_LOW_SPEED_TIME => $idleTimeoutSeconds,
                    CURLOPT_LOW_SPEED_LIMIT => 1024,
                ],
            ]);
    }

    /**
     * @param  Closure(int): void  $onProgress
     * @return Closure(int, int, int, int): void
     */
    private function heartbeatProgress(Closure $onProgress): Closure
    {
        $onProgress(0);
        $heartbeatIntervalSeconds = max(
            1,
            min(
                30,
                intdiv(max(3, (int) config('inbound_media.lease_stale_seconds', 120)), 3),
            ),
        );
        $lastHeartbeatAt = microtime(true);

        return static function (
            int $downloadTotal,
            int $downloadedBytes,
            int $uploadTotal,
            int $uploadedBytes,
        ) use ($onProgress, $heartbeatIntervalSeconds, &$lastHeartbeatAt): void {
            $now = microtime(true);

            if ($now - $lastHeartbeatAt < $heartbeatIntervalSeconds) {
                return;
            }

            $lastHeartbeatAt = $now;
            $onProgress($downloadedBytes);
        };
    }

    /**
     * @param  Closure(int): void|null  $onProgress
     * @return Closure(int, int, int, int): void
     */
    private function localApiFileBridgeProgress(
        ?Closure $onProgress,
        int $maxBytes,
        int $timeoutSeconds,
        string $temporaryDirectory,
    ): Closure {
        if ($onProgress instanceof Closure) {
            $onProgress(0);
        }

        $startedAt = microtime(true);
        $lastHeartbeatAt = $startedAt;
        $lastCapacityCheckAt = $startedAt;
        $lastCapacityCheckBytes = 0;
        $capacityChecked = false;
        $heartbeatIntervalSeconds = max(
            1,
            min(
                30,
                intdiv(max(3, (int) config('inbound_media.lease_stale_seconds', 120)), 3),
            ),
        );

        return function (
            int $downloadTotal,
            int $downloadedBytes,
            int $uploadTotal,
            int $uploadedBytes,
        ) use (
            $onProgress,
            $maxBytes,
            $timeoutSeconds,
            $temporaryDirectory,
            $startedAt,
            $heartbeatIntervalSeconds,
            &$lastHeartbeatAt,
            &$lastCapacityCheckAt,
            &$lastCapacityCheckBytes,
            &$capacityChecked,
        ): void {
            $now = microtime(true);

            if ($now - $startedAt > $timeoutSeconds) {
                throw new RuntimeException('Telegram Local Bot API file bridge exceeded the download deadline.');
            }

            if ($downloadTotal > $maxBytes || $downloadedBytes > $maxBytes) {
                throw new InvalidArgumentException('Telegram Bot media file is larger than the local download limit.');
            }

            if (
                ! $capacityChecked
                || $downloadedBytes - $lastCapacityCheckBytes >= self::TRANSFER_PROGRESS_CHECKPOINT_BYTES
                || $now - $lastCapacityCheckAt >= self::TRANSFER_PROGRESS_CHECKPOINT_SECONDS
            ) {
                $requiredHeadroomBytes = $downloadTotal > 0
                    ? max(0, min($downloadTotal, $maxBytes) - $downloadedBytes)
                    : min(
                        self::TRANSFER_PROGRESS_CHECKPOINT_BYTES,
                        max(0, $maxBytes - $downloadedBytes),
                    );
                $this->streamHttpResponseToTemporaryFileAction
                    ->assertTemporaryDownloadSinkCapacity(
                        $temporaryDirectory,
                        $requiredHeadroomBytes,
                        $downloadedBytes,
                    );
                $lastCapacityCheckAt = $now;
                $lastCapacityCheckBytes = $downloadedBytes;
                $capacityChecked = true;
            }

            if (
                $onProgress instanceof Closure
                && $now - $lastHeartbeatAt >= $heartbeatIntervalSeconds
            ) {
                $lastHeartbeatAt = $now;
                $onProgress($downloadedBytes);
            }
        };
    }

    private function localApiFileBridgeUrl(string $filePath): string
    {
        $configured = trim((string) config('bots.telegram.local_api_file_bridge_base_url', ''));
        $parts = parse_url($configured);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('Telegram Local Bot API file bridge URL is invalid.');
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = TelegramLocalApiConfiguration::normalizedHost($parts['host'] ?? null);
        $trustedHosts = TelegramLocalApiConfiguration::normalizedTrustedHosts(
            config('bots.telegram.local_api_file_bridge_trusted_hosts', []),
        );

        if (
            ! in_array($scheme, ['http', 'https'], true)
            || $host === null
            || ! in_array($host, $trustedHosts, true)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            throw new InvalidArgumentException('Telegram Local Bot API file bridge URL is not trusted.');
        }

        if (
            $scheme === 'http'
            && ! (bool) config('bots.telegram.local_api_allow_insecure_http', false)
        ) {
            throw new InvalidArgumentException('Telegram Local Bot API file bridge requires HTTPS outside an explicitly allowed local network.');
        }

        $relativePath = $this->resolveLocalApiFileRelativePath($filePath);
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));

        return rtrim($configured, '/').'/'.$encodedPath;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function localApiFileBridgeCredentials(): array
    {
        $username = trim((string) config('bots.telegram.local_api_file_bridge_username', ''));
        $configuredPassword = config('bots.telegram.local_api_file_bridge_password');
        $password = is_string($configuredPassword) ? $configuredPassword : '';

        if ($username === '' || $password === '') {
            throw new InvalidArgumentException('Telegram Local Bot API file bridge credentials are not configured.');
        }

        return [$username, $password];
    }

    private function resolveLocalApiFileRelativePath(string $filePath): string
    {
        return TelegramLocalApiConfiguration::relativeFilePath(
            $filePath,
            config('bots.telegram.local_api_files_root', ''),
        );
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

    private function providerDeclaredSizeBytes(mixed $fileSize): ?int
    {
        return is_numeric($fileSize) && (int) $fileSize >= 0
            ? (int) $fileSize
            : null;
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
