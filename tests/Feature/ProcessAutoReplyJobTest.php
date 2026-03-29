<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoReplyJob;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessAutoReplyJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_telegram_auto_reply_and_creates_outbound_message(): void
    {
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
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Тестовый queued ответ.',
            'is_active' => true,
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
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $channel->auto_reply_mode);
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
            'event' => 'bot.reply_rule_matched',
            'level' => 'info',
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
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'MAX queued ответ.',
            'is_active' => true,
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
                && $request['text'] === 'MAX queued ответ.';
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
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
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

    public function test_job_uses_exact_match_rule_text_when_rule_exists(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9101,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'reply_text' => 'Шаблон 1',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-rule',
            'text' => '  тЕст1  ',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(function ($request): bool {
            return $request['text'] === 'Шаблон 1';
        });

        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $message->id,
            'text' => 'Шаблон 1',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_rule_matched',
            'level' => 'info',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_sent',
            'level' => 'info',
        ]);

        $matchedLog = $channel->activityLogs()->where('event', 'bot.reply_rule_matched')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $matchedLog->context['auto_reply_mode']);
        $this->assertSame('rule', $matchedLog->context['auto_reply_source']);

        $this->assertTrue($rule->exists);
    }

    public function test_job_applies_has_phone_condition_for_exact_rule(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9102,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'reply_text' => 'Привет!',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-rule-has-phone',
            'text' => 'Тест1',
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $message->contact_id,
            'is_primary' => true,
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => $request['text'] === 'Привет!');
        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'reply_to_message_id' => $message->id,
            'text' => 'Привет!',
        ]);
    }

    public function test_job_uses_any_inbound_rule_when_contact_has_no_phone(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-out-any-1',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
            'reply_text' => 'Пожалуйста, поделитесь номером телефона.',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'max-any-inbound-missing-phone',
            'text' => 'Любое входящее сообщение',
        ], [
            'external_user_id' => '777',
            'external_username' => 'max_any_user',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => $request['text'] === 'Пожалуйста, поделитесь номером телефона.');
        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'reply_to_message_id' => $message->id,
            'text' => 'Пожалуйста, поделитесь номером телефона.',
        ]);
    }

    public function test_job_sends_request_phone_button_for_telegram_rule(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9105,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Телефон',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Телефон'),
            'reply_text' => 'Нажмите кнопку ниже',
            'telegram_button_type' => AutoReplyRule::TELEGRAM_BUTTON_TYPE_REQUEST_PHONE,
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-request-phone',
            'text' => 'телефон',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
                && $request['text'] === 'Нажмите кнопку ниже'
                && data_get($request->data(), 'reply_markup.keyboard.0.0.request_contact') === true
                && data_get($request->data(), 'reply_markup.keyboard.0.0.text') === 'Запросить номер телефона';
        });
    }

    public function test_job_sends_request_phone_button_for_max_rule(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-request-phone-1',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Телефон',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Телефон'),
            'reply_text' => 'Нажмите кнопку ниже',
            'max_button_type' => AutoReplyRule::MAX_BUTTON_TYPE_REQUEST_PHONE,
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'max-request-phone',
            'text' => 'телефон',
            'external_chat_id' => '700',
        ], [
            'external_user_id' => '500',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
                && $request['text'] === 'Нажмите кнопку ниже'
                && data_get($request->data(), 'attachments.0.type') === 'inline_keyboard'
                && data_get($request->data(), 'attachments.0.payload.buttons.0.0.type') === 'request_contact'
                && data_get($request->data(), 'attachments.0.payload.buttons.0.0.text') === '📱 Поделиться номером телефона';
        });
    }

    public function test_job_skips_reply_when_channel_is_rules_only_and_no_rule_matches(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'reply_text' => 'Шаблон 1',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-no-match',
            'text' => 'Другой текст',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        $message->refresh();

        Http::assertNothingSent();
        $this->assertNull($message->auto_reply_sent_at);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_skipped_no_rule',
            'level' => 'info',
        ]);

        $skipLog = $channel->activityLogs()->where('event', 'bot.reply_skipped_no_rule')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $skipLog->context['auto_reply_mode']);
        $this->assertSame('skipped_no_rule', $skipLog->context['auto_reply_source']);
    }

    public function test_job_skips_reply_when_no_rule_matches(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'reply_text' => 'Шаблон 1',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-legacy-no-match',
            'text' => 'Совсем другой текст',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();
        $this->assertNull($message->fresh()->auto_reply_sent_at);
        $this->assertDatabaseCount('messages', 1);

        $skipLog = $channel->activityLogs()->where('event', 'bot.reply_skipped_no_rule')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $skipLog->context['auto_reply_mode']);
        $this->assertSame('skipped_no_rule', $skipLog->context['auto_reply_source']);
    }

    public function test_job_skips_reply_when_contact_auto_reply_is_disabled(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Повторяемый ответ',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage(
            $channel,
            ['provider_event_key' => 'telegram-disabled'],
            [],
            ['is_auto_reply_enabled' => false],
        );

        ProcessAutoReplyJob::dispatchSync($message->id);

        $message->refresh();

        Http::assertNothingSent();
        $this->assertNull($message->auto_reply_sent_at);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_skipped_contact_disabled',
            'level' => 'info',
        ]);

        $skipLog = $channel->activityLogs()->where('event', 'bot.reply_skipped_contact_disabled')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $skipLog->context['auto_reply_mode']);
        $this->assertSame('skipped_contact_disabled', $skipLog->context['auto_reply_source']);
    }

    public function test_job_skips_reply_when_contact_auto_reply_is_disabled_in_rules_only_mode(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'reply_text' => 'Шаблон 1',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage(
            $channel,
            [
                'provider_event_key' => 'telegram-disabled-rules-only',
                'text' => 'Тест1',
            ],
            [],
            ['is_auto_reply_enabled' => false],
        );

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();
        $this->assertNull($message->fresh()->auto_reply_sent_at);

        $skipLog = $channel->activityLogs()->where('event', 'bot.reply_skipped_contact_disabled')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $skipLog->context['auto_reply_mode']);
        $this->assertSame('skipped_contact_disabled', $skipLog->context['auto_reply_source']);
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

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Ответ, который сломается на отправке',
            'is_active' => true,
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

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Ответ, который сломается на отправке',
            'is_active' => true,
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

        $failedLog = $channel->activityLogs()->where('event', 'bot.reply_failed')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $failedLog->context['auto_reply_mode']);
        $this->assertSame('rule', $failedLog->context['auto_reply_source']);
        $this->assertNull($failedLog->context['button_type']);
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

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Ответ после ретрая',
            'is_active' => true,
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
     * @param  array<string, mixed>  $contactOverrides
     */
    protected function createInboundMessage(
        Channel $channel,
        array $messageOverrides = [],
        array $identityOverrides = [],
        array $contactOverrides = [],
    ): Message
    {
        $contact = Contact::factory()->create($contactOverrides);
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

    public function test_channel_without_explicit_mode_defaults_to_rules_only_and_skips_without_rule(): void
    {
        Http::fake();

        $channelId = Channel::query()->create([
            'name' => 'Compat Channel',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => [
                'token' => 'telegram-token',
            ],
            'is_active' => true,
        ])->id;

        $channel = Channel::query()->findOrFail($channelId);
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $channel->auto_reply_mode);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-compat-mode',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();

        $skipLog = $channel->fresh()->activityLogs()->where('event', 'bot.reply_skipped_no_rule')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $skipLog->context['auto_reply_mode']);
        $this->assertSame('skipped_no_rule', $skipLog->context['auto_reply_source']);
    }
}
