<?php

namespace Tests\Feature;

use App\Data\Bots\MaxChatAvatarData;
use App\Data\Bots\MaxVideoAttachmentDownloadData;
use App\Models\Channel;
use App\Services\Bots\MaxBotApiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MaxBotApiServiceTest extends TestCase
{
    public function test_delete_message_calls_max_delete_messages_endpoint(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);

        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response(['success' => true]),
        ]);

        app(MaxBotApiService::class)->deleteMessage($channel, 'mid-123');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://platform-api.max.ru/messages?message_id=mid-123'
            && $request->hasHeader('Authorization', 'max-token'));
    }

    public function test_delete_message_throws_when_max_returns_success_false(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);

        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'success' => false,
                'message' => 'message deletion is not allowed',
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message deletion is not allowed');

        app(MaxBotApiService::class)->deleteMessage($channel, 'mid-123');
    }

    public function test_fetch_webhook_urls_returns_unique_subscription_urls(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);

        Http::fake([
            'https://platform-api.max.ru/subscriptions' => Http::response([
                'subscriptions' => [
                    ['url' => 'https://connector.example/webhooks/max/1'],
                    ['url' => 'https://connector.example/webhooks/max/1'],
                    ['url' => ''],
                    ['unexpected' => 'ignored'],
                ],
            ]),
        ]);

        $this->assertSame(
            ['https://connector.example/webhooks/max/1'],
            app(MaxBotApiService::class)->fetchWebhookUrls($channel),
        );

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://platform-api.max.ru/subscriptions'
                && $request->hasHeader('Authorization', 'max-token');
        });
    }

    public function test_fetch_chat_avatar_data_returns_dialog_with_user_avatar_urls(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);

        Http::fake([
            'https://platform-api.max.ru/chats/65238156' => Http::response([
                'dialog_with_user' => [
                    'avatar_url' => 'https://i.oneme.ru/i?r=avatar-small',
                    'full_avatar_url' => 'https://i.oneme.ru/i?r=avatar-full',
                ],
            ]),
        ]);

        $result = app(MaxBotApiService::class)->fetchChatAvatarData($channel, '65238156');

        $this->assertInstanceOf(MaxChatAvatarData::class, $result);
        $this->assertSame('https://i.oneme.ru/i?r=avatar-small', $result->avatarUrl);
        $this->assertSame('https://i.oneme.ru/i?r=avatar-full', $result->fullAvatarUrl);
        $this->assertSame('https://i.oneme.ru/i?r=avatar-full', $result->preferredAvatarUrl());

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://platform-api.max.ru/chats/65238156'
                && $request->hasHeader('Authorization', 'max-token');
        });
    }

    public function test_fetch_chat_avatar_data_returns_null_urls_when_dialog_with_user_has_no_avatar_fields(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);

        Http::fake([
            'https://platform-api.max.ru/chats/65238156' => Http::response([
                'dialog_with_user' => [
                    'name' => 'MAX user',
                ],
            ]),
        ]);

        $result = app(MaxBotApiService::class)->fetchChatAvatarData($channel, '65238156');

        $this->assertNull($result->avatarUrl);
        $this->assertNull($result->fullAvatarUrl);
        $this->assertNull($result->preferredAvatarUrl());
    }

    public function test_fetch_chat_avatar_data_throws_when_max_response_does_not_contain_dialog_with_user(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);

        Http::fake([
            'https://platform-api.max.ru/chats/65238156' => Http::response([
                'chat_id' => '65238156',
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("MAX API did not return dialog_with_user for channel [{$channel->id}] chat [65238156].");

        app(MaxBotApiService::class)->fetchChatAvatarData($channel, '65238156');
    }

    public function test_fetch_video_attachment_download_data_returns_url_and_dimensions(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);

        Http::fake([
            'https://platform-api.max.ru/videos/video-token' => Http::response([
                'urls' => [
                    'mp4_480' => 'https://max.example/private/video-480.mp4',
                ],
                'width' => '480',
                'height' => 480,
                'duration' => '21000',
            ]),
        ]);

        $result = app(MaxBotApiService::class)->fetchVideoAttachmentDownloadData($channel, 'video-token');

        $this->assertInstanceOf(MaxVideoAttachmentDownloadData::class, $result);
        $this->assertSame('https://max.example/private/video-480.mp4', $result->downloadUrl);
        $this->assertSame(480, $result->width);
        $this->assertSame(480, $result->height);
        $this->assertSame(21, $result->duration);
        $this->assertSame([
            'width' => 480,
            'height' => 480,
            'duration' => 21,
        ], $result->metadata());
    }

    #[DataProvider('legacyMaxVideoDurationProvider')]
    public function test_fetch_video_attachment_download_data_normalizes_legacy_duration_milliseconds(
        int $durationMilliseconds,
        int $expectedDurationSeconds,
    ): void {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);

        Http::fake([
            'https://platform-api.max.ru/videos/video-token' => Http::response([
                'duration' => $durationMilliseconds,
            ]),
        ]);

        $result = app(MaxBotApiService::class)->fetchVideoAttachmentDownloadData($channel, 'video-token');

        $this->assertSame($expectedDurationSeconds, $result->duration);
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function legacyMaxVideoDurationProvider(): array
    {
        return [
            'one millisecond rounds up to one second' => [1, 1],
            'sub-second duration rounds up' => [999, 1],
            'one second boundary' => [1000, 1],
            'milliseconds above boundary round up' => [1001, 2],
            'long video is not confused with seconds' => [1_651_000, 1651],
        ];
    }
}
