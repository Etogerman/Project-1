<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoReplyJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessAutoReplyJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_telegram_auto_reply_and_creates_outbound_message(): void
    {
        config()->set('bots.default_auto_reply_text', 'Тестовый queued ответ.');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9001,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $message = $this->createInboundMessage($channel, [
            'external_chat_id' => '300',
            'external_message_id' => '10',
            'provider_event_key' => 'telegram-10',
        ], [
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
                && $request['chat_id'] === '300'
                && $request['text'] === 'Тестовый queued ответ.';
        });

        $message->refresh();
        $channel->refresh();

        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertNotNull($channel->last_reply_sent_at);
        $this->assertNull($channel->last_error_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $message->id,
            'external_message_id' => '9001',
            'text' => 'Тестовый queued ответ.',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_sent',
            'level' => 'info',
        ]);
    }

    public function test_job_sends_max_auto_reply_and_creates_outbound_message(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-out-9001',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        $message = $this->createInboundMessage($channel, [
            'external_chat_id' => '700',
            'external_message_id' => 'max-10',
            'provider_event_key' => 'max-10',
        ], [
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
                && $request->hasHeader('Authorization', 'max-token')
                && $request['text'] === 'Привет бот находится в разработке. Напишите нам чуть позже.';
        });

        $message->refresh();

        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $message->id,
            'external_message_id' => 'max-out-9001',
        ]);
    }

    public function test_job_does_not_send_when_auto_reply_was_already_sent(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-sent',
            'auto_reply_sent_at' => now(),
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_repeated_job_execution_for_same_inbound_message_creates_one_outbound_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9011,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-repeat',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);
        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertSame(1, Message::query()->where('direction', Message::DIRECTION_OUTBOUND)->count());
    }

    public function test_job_marks_channel_error_and_keeps_inbound_pending_when_transport_fails(): void
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
            ],
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-failure',
        ]);

        $thrown = null;

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
        } catch (\Throwable $throwable) {
            $thrown = $throwable;
        }

        $this->assertNotNull($thrown);

        $message->refresh();
        $channel->refresh();

        $this->assertNull($message->auto_reply_sent_at);
        $this->assertNull($channel->last_reply_sent_at);
        $this->assertNotNull($channel->last_error_at);
        $this->assertNotNull($channel->last_error_message);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_failed',
            'level' => 'error',
        ]);
    }

    public function test_job_can_succeed_after_previous_failure(): void
    {
        $attempt = 0;

        Http::fake(function ($request) use (&$attempt) {
            $attempt++;

            return $attempt === 1
                ? Http::response(['ok' => false], 500)
                : Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9022,
                    ],
                ]);
        });

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-retry',
        ]);

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
        } catch (\Throwable) {
        }

        ProcessAutoReplyJob::dispatchSync($message->id);

        $message->refresh();
        $channel->refresh();

        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertNotNull($channel->last_reply_sent_at);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'external_message_id' => '9022',
        ]);
    }

    /**
     * @param  array<string, mixed>  $messageOverrides
     * @param  array<string, mixed>  $identityOverrides
     */
    protected function createInboundMessage(Channel $channel, array $messageOverrides = [], array $identityOverrides = []): Message
    {
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ], $identityOverrides));

        return Message::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'reply_to_message_id' => null,
            'provider_event_key' => 'provider-event-key',
            'external_chat_id' => '300',
            'external_message_id' => '10',
            'text' => 'hello',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
            'auto_reply_sent_at' => null,
        ], $messageOverrides));
    }
}
