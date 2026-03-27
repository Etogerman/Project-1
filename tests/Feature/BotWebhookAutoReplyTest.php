<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ContactIdentity;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload());

        $response
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
            ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
                && $request['chat_id'] === '300'
                && $request['text'] === 'Привет бот находится в разработке. Напишите нам чуть позже.';
        });

        $channel->refresh();

        $this->assertNotNull($channel->last_webhook_received_at);
        $this->assertNotNull($channel->last_reply_sent_at);
        $this->assertNull($channel->last_error_at);
        $this->assertSame('Работает', $channel->getHealthStatusLabel());
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.received',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_sent',
        ]);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => '300',
            'external_message_id' => '10',
            'text' => 'hello',
        ]);

        $identity = ContactIdentity::query()->firstOrFail();
        $message = Message::query()->firstOrFail();

        $this->assertSame($identity->contact_id, $message->contact_id);
        $this->assertSame($identity->id, $message->contact_identity_id);
        $this->assertNotNull($message->auto_reply_sent_at);
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
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload())->assertOk();

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
                && $request->hasHeader('Authorization', 'max-token')
                && $request['text'] === 'Привет бот находится в разработке. Напишите нам чуть позже.';
        });

        $channel->refresh();

        $this->assertNotNull($channel->last_webhook_received_at);
        $this->assertNotNull($channel->last_reply_sent_at);
        $this->assertNull($channel->last_error_at);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => '700',
            'external_message_id' => 'max-10',
            'text' => 'hello',
        ]);

        $this->assertNotNull(Message::query()->firstOrFail()->auto_reply_sent_at);
    }

    public function test_max_webhook_uses_real_payload_fields_for_contact_name_and_message_id(): void
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

        $timestamp = Carbon::create(2026, 3, 20, 12, 34, 56, 'UTC')->getTimestampMs() + 123;

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'timestamp' => $timestamp,
            'message' => [
                'timestamp' => $timestamp,
                'sender' => [
                    'user_id' => 228532008,
                    'first_name' => 'German',
                    'last_name' => 'Abrikosov',
                    'username' => null,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-mid-42',
                    'text' => 'Привет из MAX',
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $this->assertDatabaseHas('contacts', [
            'name' => 'German Abrikosov',
        ]);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'external_user_id' => '228532008',
            'external_username' => null,
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'external_message_id' => 'max-mid-42',
            'text' => 'Привет из MAX',
        ]);

        $message = Message::query()->firstOrFail();

        $this->assertSame(intdiv($timestamp, 1000), $message->received_at->getTimestamp());
        $this->assertSame('2026-03-20 12:34:56', $message->received_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_repeated_telegram_webhook_with_same_update_id_creates_one_message_and_sends_one_auto_reply(): void
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

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $payload = $this->telegramPayload(
            messageId: 42,
            text: 'duplicate telegram message',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        Http::assertSentCount(1);
        $this->assertDatabaseCount('messages', 1);

        $message = Message::query()->firstOrFail();

        $this->assertSame('42', $message->provider_event_key);
        $this->assertNotNull($message->auto_reply_sent_at);
    }

    public function test_repeated_telegram_webhook_with_same_update_id_retries_auto_reply_after_failure(): void
    {
        $replyAttempts = 0;

        Http::fake(function ($request) use (&$replyAttempts) {
            if (str_starts_with($request->url(), 'https://api.telegram.org/')) {
                $replyAttempts++;

                return $replyAttempts === 1
                    ? Http::response(['ok' => false], 500)
                    : Http::response(['ok' => true]);
            }

            return Http::response([], 404);
        });

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $payload = $this->telegramPayload(
            messageId: 43,
            text: 'telegram retry message',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertStatus(500);

        $message = Message::query()->firstOrFail();

        $this->assertSame('43', $message->provider_event_key);
        $this->assertNull($message->auto_reply_sent_at);

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        Http::assertSentCount(2);
        $this->assertDatabaseCount('messages', 1);

        $message->refresh();

        $this->assertNotNull($message->auto_reply_sent_at);
    }

    public function test_repeated_max_webhook_with_same_external_message_id_creates_one_message_and_sends_one_auto_reply(): void
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

        $headers = [
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ];

        $payload = $this->maxPayload(
            messageId: 'max-42',
            text: 'duplicate max message',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk();

        Http::assertSentCount(1);
        $this->assertDatabaseCount('messages', 1);

        $message = Message::query()->firstOrFail();

        $this->assertSame('max-42', $message->provider_event_key);
        $this->assertNotNull($message->auto_reply_sent_at);
    }

    public function test_repeated_max_webhook_with_same_external_message_id_retries_auto_reply_after_failure(): void
    {
        $replyAttempts = 0;

        Http::fake(function ($request) use (&$replyAttempts) {
            if (str_starts_with($request->url(), 'https://platform-api.max.ru/')) {
                $replyAttempts++;

                return $replyAttempts === 1
                    ? Http::response(['message' => []], 500)
                    : Http::response(['message' => []]);
            }

            return Http::response([], 404);
        });

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $headers = [
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ];

        $payload = $this->maxPayload(
            messageId: 'max-43',
            text: 'max retry message',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertStatus(500);

        $message = Message::query()->firstOrFail();

        $this->assertSame('max-43', $message->provider_event_key);
        $this->assertNull($message->auto_reply_sent_at);

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk();

        Http::assertSentCount(2);
        $this->assertDatabaseCount('messages', 1);

        $message->refresh();

        $this->assertNotNull($message->auto_reply_sent_at);
    }

    public function test_repeat_max_webhook_from_same_user_with_different_message_ids_creates_two_messages(): void
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

        $headers = [
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ];

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
                messageId: 'max-100',
                text: 'first max message',
            ))
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
                messageId: 'max-101',
                text: 'second max message',
            ))
            ->assertOk();

        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 2);
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
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload())->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_reply_error_updates_channel_error_status_and_timestamp(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
            ], 500),
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
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload())->assertStatus(500);

        $channel->refresh();

        $this->assertNotNull($channel->last_webhook_received_at);
        $this->assertNull($channel->last_reply_sent_at);
        $this->assertNotNull($channel->last_error_at);
        $this->assertNotNull($channel->last_error_message);
        $this->assertSame('Ошибка', $channel->getHealthStatusLabel());
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_failed',
            'level' => 'error',
        ]);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertNull(Message::query()->firstOrFail()->auto_reply_sent_at);
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
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload())->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('messages', 0);
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
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('messages', 0);
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
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload())->assertOk();

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
                && $request['text'] === 'Тестовый ответ из конфига.';
        });
    }

    public function test_repeat_telegram_webhook_from_same_user_reuses_contact_identity_and_contact(): void
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

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
                messageId: 10,
                text: 'first message',
            ))
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
                messageId: 11,
                text: 'second message',
            ))
            ->assertOk();

        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_telegram_webhook_without_update_id_keeps_legacy_non_deduplicated_behavior(): void
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

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $payload = $this->telegramPayload(
            messageId: 77,
            text: 'legacy telegram message',
            includeUpdateId: false,
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        Http::assertSentCount(2);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'provider_event_key' => null,
        ]);
    }

    public function test_new_telegram_webhook_from_different_user_creates_new_contact_and_identity(): void
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

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
                userId: 200,
                chatId: 300,
                messageId: 10,
                text: 'first message',
                username: 'telegram_user',
            ))
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
                userId: 201,
                chatId: 301,
                messageId: 11,
                text: 'second message',
                username: 'telegram_user_2',
            ))
            ->assertOk();

        $this->assertDatabaseCount('contacts', 2);
        $this->assertDatabaseCount('contact_identities', 2);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'external_user_id' => '201',
            'external_username' => 'telegram_user_2',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function telegramPayload(
        int|string $userId = 200,
        int|string $chatId = 300,
        int|string $messageId = 10,
        ?string $text = 'hello',
        ?string $username = 'telegram_user',
        int $date = 1_711_539_200,
        bool $includeUpdateId = true,
    ): array {
        $payload = [
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

        if ($includeUpdateId) {
            $payload['update_id'] = $messageId;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function maxPayload(
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
