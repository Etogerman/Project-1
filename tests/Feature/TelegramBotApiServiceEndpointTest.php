<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Services\Bots\TelegramBotApiService;
use App\Services\Messages\MediaDownloadIntegrityException;
use App\Services\Messages\StreamHttpResponseToTemporaryFileAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TelegramBotApiServiceEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_media_get_file_request_has_long_timeout_and_heartbeat_progress(): void
    {
        config([
            'inbound_media.attempt_deadline_seconds' => 600,
            'inbound_media.lease_stale_seconds' => 3,
        ]);
        $heartbeats = [];
        $method = new ReflectionMethod(TelegramBotApiService::class, 'mediaApiRequest');
        $request = $method->invoke(
            app(TelegramBotApiService::class),
            true,
            static function (int $receivedBytes) use (&$heartbeats): void {
                $heartbeats[] = $receivedBytes;
            },
        );
        $options = $request->getOptions();
        $progress = $options['progress'];

        $this->assertSame(600, $options['timeout']);
        $this->assertSame(600, $options['read_timeout']);
        $this->assertArrayNotHasKey('curl', $options);
        $this->assertIsCallable($progress);
        $this->assertSame([0], $heartbeats);

        $progress(0, 0, 0, 0);

        $this->assertSame([0], $heartbeats);

        usleep(1_100_000);
        $progress(0, 0, 0, 0);

        $this->assertSame([0, 0], $heartbeats);
    }

    public function test_local_media_progress_aborts_curl_when_heartbeat_loses_lease(): void
    {
        config(['inbound_media.lease_stale_seconds' => 3]);
        $heartbeats = 0;
        $method = new ReflectionMethod(TelegramBotApiService::class, 'mediaApiRequest');
        $request = $method->invoke(
            app(TelegramBotApiService::class),
            true,
            static function () use (&$heartbeats): void {
                $heartbeats++;

                if ($heartbeats > 1) {
                    throw new RuntimeException('lease lost');
                }
            },
        );
        $progress = $request->getOptions()['progress'];

        usleep(1_100_000);

        try {
            $progress(0, 0, 0, 0);
            $this->fail('Expected lease loss to abort Telegram getFile progress.');
        } catch (RuntimeException $exception) {
            $this->assertSame('lease lost', $exception->getMessage());
        }

        $this->assertSame(2, $heartbeats);
    }

    public function test_remote_local_api_rejects_plain_http_without_explicit_local_override(): void
    {
        config([
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'http://telegram-gateway.example/api',
            'bots.telegram.local_api_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_allow_insecure_http' => false,
        ]);
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ]);

        try {
            app(TelegramBotApiService::class)->fetchBotMetadata($channel);
            $this->fail('Plain HTTP Local Bot API URL must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Telegram Local Bot API requires HTTPS outside an explicitly allowed local network.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
    }

    public function test_local_api_requests_do_not_follow_redirects(): void
    {
        $method = new ReflectionMethod(TelegramBotApiService::class, 'apiRequest');
        $request = $method->invoke(app(TelegramBotApiService::class), true);

        $this->assertFalse($request->getOptions()['allow_redirects']);
    }

    public function test_file_bridge_uses_attempt_deadline_without_disabling_idle_timeout(): void
    {
        $sink = tmpfile();
        $progress = static function (): void {};

        $this->assertIsResource($sink);
        config([
            'inbound_media.attempt_deadline_seconds' => 600,
            'inbound_media.lease_stale_seconds' => 120,
            'bots.telegram.local_api_file_bridge_username' => 'file-reader',
            'bots.telegram.local_api_file_bridge_password' => 'file-secret',
        ]);
        $method = new ReflectionMethod(TelegramBotApiService::class, 'localApiFileBridgeRequest');
        $request = $method->invoke(app(TelegramBotApiService::class), $sink, $progress, 600);
        $options = $request->getOptions();

        try {
            $this->assertSame(600, $options['timeout']);
            $this->assertSame(10, $options['connect_timeout']);
            $this->assertSame($sink, $options['sink']);
            $this->assertSame($progress, $options['progress']);
            $this->assertArrayNotHasKey('stream', $options);
            $this->assertArrayNotHasKey('read_timeout', $options);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(90, $options['curl'][CURLOPT_LOW_SPEED_TIME]);
            $this->assertSame(1024, $options['curl'][CURLOPT_LOW_SPEED_LIMIT]);
            $this->assertArrayNotHasKey(CURLOPT_XFERINFOFUNCTION, $options['curl']);
        } finally {
            fclose($sink);
        }
    }

    public function test_file_bridge_accepts_short_total_timeout_for_avatar_download(): void
    {
        $sink = tmpfile();

        $this->assertIsResource($sink);
        config([
            'inbound_media.attempt_deadline_seconds' => 6 * 60 * 60,
            'inbound_media.lease_stale_seconds' => 120,
            'bots.telegram.local_api_file_bridge_username' => 'file-reader',
            'bots.telegram.local_api_file_bridge_password' => 'file-secret',
        ]);
        $method = new ReflectionMethod(TelegramBotApiService::class, 'localApiFileBridgeRequest');
        $request = $method->invoke(
            app(TelegramBotApiService::class),
            $sink,
            static function (): void {},
            30,
        );
        $options = $request->getOptions();

        try {
            $this->assertSame(30, $options['timeout']);
            $this->assertSame(30, $options['curl'][CURLOPT_LOW_SPEED_TIME]);
            $this->assertSame(1024, $options['curl'][CURLOPT_LOW_SPEED_LIMIT]);
            $this->assertArrayNotHasKey('stream', $options);
        } finally {
            fclose($sink);
        }
    }

    public function test_file_bridge_progress_aborts_when_heartbeat_loses_lease(): void
    {
        $heartbeats = 0;
        config([
            'inbound_media.lease_stale_seconds' => 3,
            'bots.telegram.local_api_file_bridge_username' => 'file-reader',
            'bots.telegram.local_api_file_bridge_password' => 'file-secret',
        ]);
        $method = new ReflectionMethod(TelegramBotApiService::class, 'localApiFileBridgeProgress');
        $progress = $method->invoke(
            app(TelegramBotApiService::class),
            static function () use (&$heartbeats): void {
                $heartbeats++;

                if ($heartbeats > 1) {
                    throw new RuntimeException('lease lost');
                }
            },
            100,
            600,
            storage_path('framework/testing'),
        );

        usleep(1_100_000);

        try {
            $progress(100, 10, 0, 0);
            $this->fail('Expected lease loss to abort file bridge progress.');
        } catch (RuntimeException $exception) {
            $this->assertSame('lease lost', $exception->getMessage());
        }

        $this->assertSame(2, $heartbeats);
    }

    public function test_file_bridge_progress_rejects_transfer_above_limit(): void
    {
        $method = new ReflectionMethod(TelegramBotApiService::class, 'localApiFileBridgeProgress');
        $progress = $method->invoke(
            app(TelegramBotApiService::class),
            null,
            100,
            600,
            storage_path('framework/testing'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Telegram Bot media file is larger than the local download limit.');

        $progress(101, 0, 0, 0);
    }

    public function test_file_bridge_closes_sink_when_initial_heartbeat_loses_lease(): void
    {
        $sink = tmpfile();
        $action = Mockery::mock(StreamHttpResponseToTemporaryFileAction::class);

        $this->assertIsResource($sink);
        $action->shouldReceive('openTemporaryDownloadSink')
            ->once()
            ->with(100)
            ->andReturn([
                'stream' => $sink,
                'directory' => storage_path('framework/testing'),
            ]);
        $method = new ReflectionMethod(TelegramBotApiService::class, 'streamLocalApiFileFromBridge');

        try {
            $method->invoke(
                new TelegramBotApiService($action),
                '/var/lib/telegram-bot-api/videos/large.mp4',
                100,
                static function (): void {
                    throw new RuntimeException('lease lost before bridge request');
                },
                100,
            );

            $this->fail('Expected initial lease heartbeat to abort bridge request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('lease lost before bridge request', $exception->getMessage());
            $this->assertFalse(is_resource($sink));
        }
    }

    public function test_remote_file_bridge_rejects_plain_http_without_explicit_local_override(): void
    {
        config([
            'bots.telegram.local_api_allow_insecure_http' => false,
            'bots.telegram.local_api_files_root' => '/var/lib/telegram-bot-api',
            'bots.telegram.local_api_file_bridge_base_url' => 'http://telegram-gateway.example/files',
            'bots.telegram.local_api_file_bridge_trusted_hosts' => ['telegram-gateway.example'],
        ]);

        $method = new ReflectionMethod(TelegramBotApiService::class, 'localApiFileBridgeUrl');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Telegram Local Bot API file bridge requires HTTPS outside an explicitly allowed local network.',
        );

        $method->invoke(
            app(TelegramBotApiService::class),
            '/var/lib/telegram-bot-api/videos/large.mp4',
        );
    }

    public function test_file_bridge_rejects_untrusted_host(): void
    {
        config([
            'bots.telegram.local_api_files_root' => '/var/lib/telegram-bot-api',
            'bots.telegram.local_api_file_bridge_base_url' => 'https://untrusted.example/files',
            'bots.telegram.local_api_file_bridge_trusted_hosts' => ['telegram-gateway.example'],
        ]);

        $method = new ReflectionMethod(TelegramBotApiService::class, 'localApiFileBridgeUrl');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Telegram Local Bot API file bridge URL is not trusted.');

        $method->invoke(
            app(TelegramBotApiService::class),
            '/var/lib/telegram-bot-api/videos/large.mp4',
        );
    }

    public function test_file_bridge_rejects_unsafe_provider_paths(): void
    {
        config(['bots.telegram.local_api_files_root' => '/var/lib/telegram-bot-api']);
        $method = new ReflectionMethod(TelegramBotApiService::class, 'resolveLocalApiFileRelativePath');
        $service = app(TelegramBotApiService::class);
        $unsafePaths = [
            '/var/lib/telegram-bot-api/../private/secret.mp4',
            '/var/lib/telegram-bot-api/./videos/secret.mp4',
            '/var/lib/telegram-bot-api//videos/secret.mp4',
            '/var/lib/telegram-bot-api-evil/videos/secret.mp4',
            "/var/lib/telegram-bot-api/videos/secret\0.mp4",
            '/var/lib/telegram-bot-api\\..\\private\\secret.mp4',
        ];

        foreach ($unsafePaths as $unsafePath) {
            try {
                $method->invoke($service, $unsafePath);
                $this->fail("Unsafe Telegram Local Bot API path was accepted: {$unsafePath}");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringStartsWith('Telegram Local Bot API media path', $exception->getMessage());
            }
        }
    }

    public function test_local_bot_api_mode_routes_control_plane_and_outgoing_calls_to_local_server(): void
    {
        config([
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'https://telegram-gateway.example/api',
            'bots.telegram.local_api_username' => 'media-reader',
            'bots.telegram.local_api_password' => 'gateway-secret',
            'bots.telegram.local_api_trusted_hosts' => ['telegram-gateway.example'],
        ]);

        Http::fake(function (Request $request) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));

            return match ($method) {
                'sendMessage' => Http::response([
                    'ok' => true,
                    'result' => ['message_id' => 101],
                ]),
                'getWebhookInfo' => Http::response([
                    'ok' => true,
                    'result' => ['url' => 'https://connector.example/webhooks/telegram/1'],
                ]),
                'getMe' => Http::response([
                    'ok' => true,
                    'result' => [
                        'id' => 8225189348,
                        'is_bot' => true,
                        'first_name' => 'Local Bot',
                        'username' => 'local22_bot',
                    ],
                ]),
                default => Http::response(['ok' => true, 'result' => true]),
            };
        });

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $service = app(TelegramBotApiService::class);

        $service->sendTextMessage($channel, 'chat-1', 'user-1', 'Тест');
        $service->deleteMessage($channel, 'chat-1', '101');
        $service->registerWebhook($channel, 'https://connector.example/webhooks/telegram/1', 'secret');
        $service->fetchWebhookInfo($channel);
        $service->answerCallbackQuery($channel, 'callback-1');
        $service->editMessageReplyMarkup($channel, 'chat-1', '101');
        $service->fetchBotMetadata($channel);

        $expectedMethods = [
            'sendMessage',
            'deleteMessage',
            'setWebhook',
            'getWebhookInfo',
            'answerCallbackQuery',
            'editMessageReplyMarkup',
            'getMe',
        ];

        foreach ($expectedMethods as $method) {
            Http::assertSent(fn (Request $request): bool => $request->url() === "https://telegram-gateway.example/api/bottelegram-token/{$method}"
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('media-reader:gateway-secret')));
        }

        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.telegram.org/'));
    }

    public function test_local_bot_api_mode_reads_telegram_avatar_from_shared_files_root(): void
    {
        $root = storage_path('framework/testing/telegram-local-bot-api-avatar');
        $filePath = $root.'/photos/avatar.jpg';
        File::deleteDirectory($root);
        File::ensureDirectoryExists(dirname($filePath));
        file_put_contents($filePath, 'local-avatar-bytes');

        config([
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'http://telegram-bot-api:8081',
            'bots.telegram.local_api_allow_insecure_http' => true,
            'bots.telegram.local_api_trusted_hosts' => ['telegram-bot-api'],
            'bots.telegram.local_api_files_root' => $root,
        ]);

        Http::fake([
            'http://telegram-bot-api:8081/bottelegram-token/getChat*' => Http::response([
                'ok' => true,
                'result' => [
                    'photo' => ['big_file_id' => 'avatar-file-id'],
                ],
            ]),
            'http://telegram-bot-api:8081/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => $filePath],
            ]),
        ]);

        try {
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'credentials' => ['token' => 'telegram-token'],
            ]);

            $result = app(TelegramBotApiService::class)->downloadChatAvatar($channel, 'chat-1');

            $this->assertSame('local-avatar-bytes', $result->avatar?->contents);
            $this->assertSame('avatar.jpg', $result->avatar?->filenameHint);
            Http::assertSentCount(2);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_local_bot_api_mode_reads_telegram_avatar_through_authenticated_file_bridge(): void
    {
        $payload = 'bridged-avatar-bytes';

        config([
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'https://telegram-gateway.example/api',
            'bots.telegram.local_api_username' => 'media-reader',
            'bots.telegram.local_api_password' => 'gateway-secret',
            'bots.telegram.local_api_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_file_transport' => 'http_bridge',
            'bots.telegram.local_api_files_root' => '/var/lib/telegram-bot-api',
            'bots.telegram.local_api_file_bridge_base_url' => 'https://telegram-gateway.example/files',
            'bots.telegram.local_api_file_bridge_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_file_bridge_username' => 'file-reader',
            'bots.telegram.local_api_file_bridge_password' => 'file-secret',
        ]);

        Http::fake([
            'https://telegram-gateway.example/api/bottelegram-token/getChat*' => Http::response([
                'ok' => true,
                'result' => [
                    'photo' => ['big_file_id' => 'avatar-file-id'],
                ],
            ]),
            'https://telegram-gateway.example/api/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => '/var/lib/telegram-bot-api/photos/avatar.jpg',
                    'file_size' => strlen($payload),
                ],
            ]),
            'https://telegram-gateway.example/files/photos/avatar.jpg' => Http::response(
                $payload,
                200,
                [
                    'Content-Type' => 'image/jpeg',
                    'Content-Length' => (string) strlen($payload),
                ],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ]);

        $result = app(TelegramBotApiService::class)->downloadChatAvatar($channel, 'chat-1');

        $this->assertSame($payload, $result->avatar?->contents);
        $this->assertSame('image/jpeg', $result->avatar?->contentType);
        $this->assertSame('avatar.jpg', $result->avatar?->filenameHint);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://telegram-gateway.example/api/bottelegram-token/getChat?chat_id=chat-1'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('media-reader:gateway-secret')));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://telegram-gateway.example/api/bottelegram-token/getFile?file_id=avatar-file-id'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('media-reader:gateway-secret')));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://telegram-gateway.example/files/photos/avatar.jpg'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('file-reader:file-secret')));
        Http::assertSentCount(3);
    }

    public function test_file_bridge_rejects_http_length_that_differs_from_telegram_size(): void
    {
        $telegramSize = 51 * 1024 * 1024;
        $truncatedPayload = 'truncated-file';

        config([
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'https://telegram-gateway.example/api',
            'bots.telegram.local_api_username' => 'media-reader',
            'bots.telegram.local_api_password' => 'gateway-secret',
            'bots.telegram.local_api_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_file_transport' => 'http_bridge',
            'bots.telegram.local_api_files_root' => '/var/lib/telegram-bot-api',
            'bots.telegram.local_api_file_bridge_base_url' => 'https://telegram-gateway.example/files',
            'bots.telegram.local_api_file_bridge_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_file_bridge_username' => 'file-reader',
            'bots.telegram.local_api_file_bridge_password' => 'file-secret',
        ]);

        Http::fake([
            'https://telegram-gateway.example/api/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => '/var/lib/telegram-bot-api/videos/large.mp4',
                    'file_size' => $telegramSize,
                ],
            ]),
            'https://telegram-gateway.example/files/videos/large.mp4' => Http::response(
                $truncatedPayload,
                200,
                ['Content-Length' => (string) strlen($truncatedPayload)],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ]);

        $this->expectException(MediaDownloadIntegrityException::class);
        $this->expectExceptionMessage(
            'Downloaded media HTTP length does not match the provider-declared length.',
        );

        app(TelegramBotApiService::class)->downloadFileToStream(
            channel: $channel,
            fileId: 'large-file-id',
            maxBytes: 64 * 1024 * 1024,
        );
    }

    public function test_real_file_bridge_response_does_not_close_returned_download_stream(): void
    {
        [$process, $port] = $this->startTelegramFileBridgeFixture();
        $temporaryDirectory = storage_path('framework/testing/telegram-real-bridge-'.uniqid('', true));

        try {
            $this->configureRealTelegramFileBridge($port, $temporaryDirectory);
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'credentials' => ['token' => 'telegram-token'],
            ]);

            $download = app(TelegramBotApiService::class)->downloadFileToStream(
                channel: $channel,
                fileId: 'ownership-file-id',
                maxBytes: 1024,
            );

            gc_collect_cycles();

            try {
                $this->assertIsResource($download->stream);
                $this->assertSame('ownership-stays-open', stream_get_contents($download->stream));
            } finally {
                if (is_resource($download->stream)) {
                    fclose($download->stream);
                }
            }
        } finally {
            $process->stop(1);
            File::deleteDirectory($temporaryDirectory);
        }
    }

    public function test_real_file_bridge_streams_51_mib_file_into_temporary_storage(): void
    {
        [$process, $port] = $this->startTelegramFileBridgeFixture();
        $temporaryDirectory = storage_path('framework/testing/telegram-real-bridge-'.uniqid('', true));
        $sizeBytes = 51 * 1024 * 1024;

        try {
            $this->configureRealTelegramFileBridge($port, $temporaryDirectory);
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'credentials' => ['token' => 'telegram-token'],
            ]);

            $download = app(TelegramBotApiService::class)->downloadFileToStream(
                channel: $channel,
                fileId: 'large-file-id',
                maxBytes: 64 * 1024 * 1024,
            );

            try {
                $this->assertSame($sizeBytes, $download->sizeBytes);
                $this->assertSame($sizeBytes, $download->expectedLengthBytes);
                $this->assertSame('video/mp4', $download->contentType);
                $this->assertSame('z', fread($download->stream, 1));
                fseek($download->stream, -1, SEEK_END);
                $this->assertSame('z', fread($download->stream, 1));
            } finally {
                if (is_resource($download->stream)) {
                    fclose($download->stream);
                }
            }
        } finally {
            $process->stop(1);
            File::deleteDirectory($temporaryDirectory);
        }
    }

    public function test_real_file_bridge_progress_aborts_transfer_and_preserves_lease_failure(): void
    {
        [$process, $port] = $this->startTelegramFileBridgeFixture();
        $temporaryDirectory = storage_path('framework/testing/telegram-real-bridge-'.uniqid('', true));

        try {
            $this->configureRealTelegramFileBridge($port, $temporaryDirectory);
            config(['inbound_media.lease_stale_seconds' => 3]);
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'credentials' => ['token' => 'telegram-token'],
            ]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('lease lost during real bridge transfer');

            app(TelegramBotApiService::class)->downloadFileToStream(
                channel: $channel,
                fileId: 'slow-file-id',
                maxBytes: 1024 * 1024,
                onProgress: static function (int $receivedBytes): void {
                    if ($receivedBytes > 0) {
                        throw new RuntimeException('lease lost during real bridge transfer');
                    }
                },
            );
        } finally {
            $process->stop(1);
            File::deleteDirectory($temporaryDirectory);
        }
    }

    /**
     * @return array{0:Process,1:int}
     */
    private function startTelegramFileBridgeFixture(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($socket === false) {
            $this->fail("Failed to reserve Telegram bridge fixture port: {$errorMessage} ({$errorCode}).");
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $portSeparator = is_string($address) ? strrchr($address, ':') : false;
        $port = is_string($portSeparator) ? (int) substr($portSeparator, 1) : 0;

        if ($port < 1) {
            $this->fail('Failed to determine Telegram bridge fixture port.');
        }

        $process = new Process([
            PHP_BINARY,
            '-d',
            'output_buffering=0',
            '-d',
            'zlib.output_compression=0',
            '-S',
            "127.0.0.1:{$port}",
            base_path('tests/Fixtures/telegram_file_bridge_router.php'),
        ], base_path());
        $process->start();
        $deadline = microtime(true) + 5;

        do {
            $connection = @fsockopen('127.0.0.1', $port, $connectErrorCode, $connectErrorMessage, 0.1);

            if (is_resource($connection)) {
                fclose($connection);

                return [$process, $port];
            }

            usleep(50_000);
        } while ($process->isRunning() && microtime(true) < $deadline);

        $process->stop(1);
        $this->fail(sprintf(
            'Telegram bridge fixture did not start: %s %s',
            $process->getOutput(),
            $process->getErrorOutput(),
        ));
    }

    private function configureRealTelegramFileBridge(int $port, string $temporaryDirectory): void
    {
        config([
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => "http://127.0.0.1:{$port}/api",
            'bots.telegram.local_api_allow_insecure_http' => true,
            'bots.telegram.local_api_trusted_hosts' => ['127.0.0.1'],
            'bots.telegram.local_api_file_transport' => 'http_bridge',
            'bots.telegram.local_api_files_root' => '/var/lib/telegram-bot-api',
            'bots.telegram.local_api_file_bridge_base_url' => "http://127.0.0.1:{$port}/files",
            'bots.telegram.local_api_file_bridge_trusted_hosts' => ['127.0.0.1'],
            'bots.telegram.local_api_file_bridge_username' => 'file-reader',
            'bots.telegram.local_api_file_bridge_password' => 'file-secret',
            'inbound_media.temporary_directory' => $temporaryDirectory,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
            'inbound_media.attempt_deadline_seconds' => 30,
        ]);
    }
}
