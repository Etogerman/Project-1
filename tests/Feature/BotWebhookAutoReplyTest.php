<?php

namespace Tests\Feature;

use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotWebhookAutoReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_webhook_endpoint_accepts_valid_event_and_sends_auto_reply(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", [
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'text' => 'hello',
                'from' => [
                    'id' => 200,
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
            ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
                && $request['chat_id'] === 300
                && $request['text'] === 'Привет бот находится в разработке. Напишите нам чуть позже.';
        });
    }

    public function test_max_webhook_endpoint_accepts_valid_event_and_sends_auto_reply(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'text' => 'hello',
                ],
            ],
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
                && $request->hasHeader('Authorization', 'max-token')
                && $request['text'] === 'Привет бот находится в разработке. Напишите нам чуть позже.';
        });
    }

    public function test_inactive_channel_does_not_process_event(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => false,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", [
            'message' => [
                'chat' => [
                    'id' => 1,
                    'type' => 'private',
                ],
            ],
        ])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_invalid_telegram_webhook_secret_is_rejected(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'expected-secret',
            ],
        ]);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", [
            'message' => [
                'chat' => [
                    'id' => 1,
                    'type' => 'private',
                ],
            ],
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_empty_max_webhook_secret_is_rejected(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'expected-secret',
            ],
        ]);

        $this->postJson("/webhooks/max/{$channel->id}", [
            'update_type' => 'message_created',
            'message' => [
                'sender' => [
                    'user_id' => 1,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'user_id' => 2,
                ],
            ],
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_auto_reply_text_is_taken_from_central_config(): void
    {
        config()->set('bots.default_auto_reply_text', 'Тестовый ответ из конфига.');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", [
            'message' => [
                'text' => 'hello',
                'from' => [
                    'id' => 200,
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
            ],
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
                && $request['text'] === 'Тестовый ответ из конфига.';
        });
    }
}
