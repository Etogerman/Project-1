<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoReplyJob;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bots.legacy_auto_reply_rules_enabled', true);
    }

    public function test_telegram_webhook_below_limit_still_creates_message_and_queues_job(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.rate_limit.telegram.max_per_minute', 5);

        $channel = $this->createTelegramChannel();

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(messageId: 10));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_message_id' => '10',
        ]);
        Queue::assertPushed(ProcessAutoReplyJob::class, 1);
    }

    public function test_max_webhook_below_limit_still_creates_message_and_queues_job(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.rate_limit.max.max_per_minute', 5);

        $channel = $this->createMaxChannel();

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(messageId: 'max-10'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_message_id' => 'max-10',
        ]);
        Queue::assertPushed(ProcessAutoReplyJob::class, 1);
    }

    public function test_valid_webhook_over_limit_returns_429_and_stops_processing(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.rate_limit.telegram.max_per_minute', 1);

        $channel = $this->createTelegramChannel();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(messageId: 10))
            ->assertOk();

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(messageId: 11));

        $response->assertStatus(429)
            ->assertExactJson([
                'ok' => false,
                'error' => 'rate_limited',
            ]);

        $retryAfter = (int) $response->headers->get('Retry-After');

        $this->assertGreaterThan(0, $retryAfter);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseMissing('messages', [
            'channel_id' => $channel->id,
            'external_message_id' => '11',
        ]);
        Queue::assertPushed(ProcessAutoReplyJob::class, 1);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.rate_limited',
            'level' => 'warning',
        ]);
    }

    public function test_invalid_secret_requests_do_not_consume_valid_channel_quota(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.rate_limit.telegram.max_per_minute', 1);

        $channel = $this->createTelegramChannel();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(messageId: 10))
            ->assertForbidden();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(messageId: 11))
            ->assertForbidden();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(messageId: 12))
            ->assertForbidden();

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(messageId: 13));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'external_message_id' => '13',
        ]);
        Queue::assertPushed(ProcessAutoReplyJob::class, 1);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.rate_limited',
        ]);
    }

    public function test_different_channels_have_independent_buckets(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.rate_limit.telegram.max_per_minute', 1);

        $firstChannel = $this->createTelegramChannel();
        $secondChannel = $this->createTelegramChannel(secret: 'telegram-secret-2');

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$firstChannel->id}", $this->telegramPayload(messageId: 10))
            ->assertOk();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret-2',
        ])->postJson("/webhooks/telegram/{$secondChannel->id}", $this->telegramPayload(messageId: 20))
            ->assertOk();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$firstChannel->id}", $this->telegramPayload(messageId: 11))
            ->assertStatus(429);

        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $firstChannel->id,
            'external_message_id' => '10',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $secondChannel->id,
            'external_message_id' => '20',
        ]);
        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
    }

    private function createTelegramChannel(string $secret = 'telegram-secret'): Channel
    {
        return Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => $secret,
            ],
        ]);
    }

    private function createMaxChannel(string $secret = 'max-secret'): Channel
    {
        return Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => $secret,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramPayload(
        int|string $userId = 200,
        int|string $chatId = 300,
        int|string $messageId = 10,
        ?string $text = 'hello',
        ?string $username = 'telegram_user',
        int $date = 1_711_539_200,
    ): array {
        return [
            'update_id' => $messageId,
            'message' => [
                'message_id' => $messageId,
                'date' => $date,
                'text' => $text,
                'from' => [
                    'id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => $chatId,
                    'type' => 'private',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function maxPayload(
        int|string $userId = 500,
        int|string $chatId = 700,
        int|string $messageId = 'max-10',
        ?string $text = 'hello',
        ?string $username = 'max_user',
        string $timestamp = '2026-03-27T12:00:00+03:00',
    ): array {
        return [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'timestamp' => $timestamp,
            'message' => [
                'message_id' => $messageId,
                'timestamp' => $timestamp,
                'sender' => [
                    'user_id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => $chatId,
                ],
                'body' => [
                    'text' => $text,
                ],
            ],
        ];
    }
}
