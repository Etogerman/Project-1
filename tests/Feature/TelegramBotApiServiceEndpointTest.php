<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Services\Bots\TelegramBotApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramBotApiServiceEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_bot_api_mode_routes_control_plane_and_outgoing_calls_to_local_server(): void
    {
        config([
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'http://telegram-bot-api:8081',
            'bots.telegram.local_api_trusted_hosts' => ['telegram-bot-api'],
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
            Http::assertSent(fn (Request $request): bool => $request->url() === "http://telegram-bot-api:8081/bottelegram-token/{$method}");
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
}
