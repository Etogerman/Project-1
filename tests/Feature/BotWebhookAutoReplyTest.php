<?php

namespace Tests\Feature;

use App\Data\Bots\StoredInboundMessageResult;
use App\Jobs\ExportMessageToBitrix24OpenLinesJob;
use App\Jobs\ProcessDataCollectionResponseJob;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessPhoneCaptureFollowUpJob;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BotWebhookAutoReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_webhook_endpoint_accepts_valid_event_and_queues_auto_reply(): void
    {
        Queue::fake();
        Http::fake();

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

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Http::assertNothingSent();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($inboundMessage): bool {
            return $job->inboundMessageId === $inboundMessage->id;
        });

        $channel->refresh();

        $this->assertNotNull($channel->last_webhook_received_at);
        $this->assertNull($channel->last_reply_sent_at);
        $this->assertNull($channel->last_error_at);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.received',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_queued',
        ]);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '300',
            'external_message_id' => '10',
            'text' => 'hello',
        ]);
        $this->assertSame('10', $inboundMessage->provider_event_key);
        $this->assertNull($inboundMessage->auto_reply_sent_at);
    }

    public function test_max_webhook_endpoint_accepts_valid_event_and_queues_auto_reply(): void
    {
        Queue::fake();
        Http::fake();

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

        Http::assertNothingSent();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($inboundMessage): bool {
            return $job->inboundMessageId === $inboundMessage->id;
        });

        $channel->refresh();

        $this->assertNotNull($channel->last_webhook_received_at);
        $this->assertNull($channel->last_reply_sent_at);
        $this->assertNull($channel->last_error_at);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '700',
            'external_message_id' => 'max-10',
            'text' => 'hello',
        ]);
        $this->assertSame('max-10', $inboundMessage->provider_event_key);
        $this->assertNull($inboundMessage->auto_reply_sent_at);
    }

    public function test_max_bot_started_webhook_is_saved_without_queuing_runtime_jobs(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'promo_123'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
        Http::assertNothingSent();

        $this->assertSame(Message::KIND_INBOUND_USER, $storedMessage->message_kind);
        $this->assertNull($storedMessage->text);
        $this->assertNull($storedMessage->external_message_id);
        $this->assertSame('bot_started', data_get($storedMessage->raw_payload, 'update_type'));
        $this->assertStringStartsWith('max-bot-started:', $storedMessage->provider_event_key ?? '');
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
    }

    public function test_max_bot_started_webhook_does_not_queue_collector_response_for_contact_in_active_data_collection(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'promo_123'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);

        $storedMessage = $this->inboundMessages()->latest('id')->firstOrFail();

        $this->assertSame($contact->id, $storedMessage->contact_id);
        $this->assertSame('bot_started', data_get($storedMessage->raw_payload, 'update_type'));
    }

    public function test_max_webhook_uses_real_payload_fields_for_contact_name_and_message_id(): void
    {
        Queue::fake();
        Http::fake();

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

        Queue::assertPushed(ProcessAutoReplyJob::class);
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
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_message_id' => 'max-mid-42',
            'text' => 'Привет из MAX',
        ]);

        $message = $this->inboundMessages()->firstOrFail();

        $this->assertSame(intdiv($timestamp, 1000), $message->received_at->getTimestamp());
        $this->assertSame('2026-03-20 12:34:56', $message->received_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_telegram_contact_share_webhook_saves_phone_and_queues_confirmation_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $payload = $this->telegramPayload(messageId: 90, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertNothingSent();

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $storedMessage->contact_id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_captured',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_queued',
        ]);
    }

    public function test_max_contact_share_webhook_saves_phone_and_queues_confirmation_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = $this->maxPayload(messageId: 'max-contact-90', text: null);
        $payload['message']['body'] = [
            'mid' => 'max-contact-90',
            'contact' => [
                'phone' => '+7 999 123 45 67',
                'user_id' => 500,
            ],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertNothingSent();

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $storedMessage->contact_id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_captured',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_queued',
        ]);
    }

    public function test_max_contact_share_webhook_with_vcf_attachment_saves_phone_and_queues_confirmation_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = $this->maxPayload(messageId: 'max-contact-vcf-90', text: null);
        $payload['message']['sender']['user_id'] = 228532008;
        $payload['message']['body'] = [
            'mid' => 'max-contact-vcf-90',
            'text' => null,
            'attachments' => [[
                'type' => 'contact',
                'payload' => [
                    'max_info' => [
                        'user_id' => 228532008,
                    ],
                    'vcf_info' => "BEGIN:VCARD\r\nVERSION:3.0\r\nTEL;TYPE=cell:79263527111\r\nFN:Герман Абрикосов\r\nEND:VCARD",
                ],
            ]],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertNothingSent();

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $storedMessage->contact_id,
            'phone_raw' => '79263527111',
            'phone_normalized' => '+79263527111',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_captured',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_queued',
        ]);
    }

    public function test_late_max_contact_share_logs_delayed_received_and_phone_capture_arrived_late(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.max.delayed_webhook_threshold_seconds', 60);

        Carbon::setTestNow(Carbon::parse('2026-03-31 19:06:46+03:00'));

        try {
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_MAX,
                'credentials' => [
                    'token' => 'max-token',
                    'webhook_secret' => 'max-secret',
                ],
            ]);

            $payload = $this->maxPayload(
                messageId: 'max-contact-late-90',
                text: null,
                timestamp: '2026-03-31T18:40:58+03:00',
            );
            $payload['message']['body'] = [
                'mid' => 'max-contact-late-90',
                'contact' => [
                    'phone' => '+7 999 123 45 67',
                    'user_id' => 500,
                ],
            ];

            $response = $this->withHeaders([
                'X-Max-Bot-Api-Secret' => 'max-secret',
            ])->postJson("/webhooks/max/{$channel->id}", $payload);

            $response->assertOk()->assertExactJson([
                'ok' => true,
            ]);

            $storedMessage = $this->inboundMessages()
                ->where('external_message_id', 'max-contact-late-90')
                ->firstOrFail();

            Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
                return $job->inboundMessageId === $storedMessage->id
                    && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
            });

            $delayedLog = ChannelActivityLog::query()
                ->where('channel_id', $channel->id)
                ->where('event', 'webhook.delayed_received')
                ->latest('id')
                ->firstOrFail();

            $latePhoneCaptureLog = ChannelActivityLog::query()
                ->where('channel_id', $channel->id)
                ->where('event', 'contact.phone_capture_arrived_late')
                ->latest('id')
                ->firstOrFail();

            $this->assertGreaterThan(60, (int) data_get($delayedLog->context, 'delivery_lag_seconds'));
            $this->assertSame('max-contact-late-90', data_get($delayedLog->context, 'external_message_id'));
            $this->assertSame(
                StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW,
                data_get($latePhoneCaptureLog->context, 'phone_capture_status'),
            );
            $this->assertGreaterThan(60, (int) data_get($latePhoneCaptureLog->context, 'delivery_lag_seconds'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_max_contact_share_logs_out_of_order_when_newer_inbound_exists(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.max.delayed_webhook_threshold_seconds', 60);

        Carbon::setTestNow(Carbon::parse('2026-03-31 19:06:46+03:00'));

        try {
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_MAX,
                'credentials' => [
                    'token' => 'max-token',
                    'webhook_secret' => 'max-secret',
                ],
            ]);

            $newerPayload = $this->maxPayload(
                messageId: 'max-user-newer-91',
                text: 'что?',
                timestamp: '2026-03-31T19:05:30+03:00',
            );

            $this->withHeaders([
                'X-Max-Bot-Api-Secret' => 'max-secret',
            ])->postJson("/webhooks/max/{$channel->id}", $newerPayload)->assertOk();

            $newerInbound = $this->inboundMessages()
                ->where('external_message_id', 'max-user-newer-91')
                ->firstOrFail();

            $latePayload = $this->maxPayload(
                messageId: 'max-contact-late-order-92',
                text: null,
                timestamp: '2026-03-31T18:40:58+03:00',
            );
            $latePayload['message']['body'] = [
                'mid' => 'max-contact-late-order-92',
                'contact' => [
                    'phone' => '+7 999 123 45 67',
                    'user_id' => 500,
                ],
            ];

            $this->withHeaders([
                'X-Max-Bot-Api-Secret' => 'max-secret',
            ])->postJson("/webhooks/max/{$channel->id}", $latePayload)->assertOk();

            $lateInbound = $this->inboundMessages()
                ->where('external_message_id', 'max-contact-late-order-92')
                ->firstOrFail();

            $outOfOrderLog = ChannelActivityLog::query()
                ->where('channel_id', $channel->id)
                ->where('event', 'webhook.out_of_order_received')
                ->latest('id')
                ->firstOrFail();

            $this->assertSame($lateInbound->id, (int) data_get($outOfOrderLog->context, 'message_id'));
            $this->assertSame($newerInbound->id, (int) data_get($outOfOrderLog->context, 'newer_inbound_message_id'));
            $this->assertGreaterThan(0, (int) data_get($outOfOrderLog->context, 'seconds_behind_latest_inbound'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_max_contact_share_still_merges_into_existing_root_and_queues_follow_up(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.max.delayed_webhook_threshold_seconds', 60);

        Carbon::setTestNow(Carbon::parse('2026-03-31 19:06:46+03:00'));

        try {
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_MAX,
                'credentials' => [
                    'token' => 'max-token',
                    'webhook_secret' => 'max-secret',
                ],
            ]);

            $existingRoot = Contact::factory()->create([
                'first_name' => 'Герман',
                'country' => 'Россия',
                'city' => 'Москва',
                'age_range' => '30_39',
            ]);
            ContactPhoneNumber::factory()->create([
                'contact_id' => $existingRoot->id,
                'phone_raw' => '+7 999 123 45 67',
                'phone_normalized' => '+79991234567',
                'is_primary' => true,
            ]);

            $payload = $this->maxPayload(
                userId: 228532008,
                messageId: 'max-contact-late-merge-93',
                text: null,
                username: 'max_user_merge',
                timestamp: '2026-03-31T18:40:58+03:00',
            );
            $payload['message']['body'] = [
                'mid' => 'max-contact-late-merge-93',
                'attachments' => [[
                    'type' => 'contact',
                    'payload' => [
                        'max_info' => [
                            'user_id' => 228532008,
                        ],
                        'vcf_info' => "BEGIN:VCARD\r\nVERSION:3.0\r\nTEL;TYPE=cell:79991234567\r\nFN:Герман Абрикосов\r\nEND:VCARD",
                    ],
                ]],
            ];

            $response = $this->withHeaders([
                'X-Max-Bot-Api-Secret' => 'max-secret',
            ])->postJson("/webhooks/max/{$channel->id}", $payload);

            $response->assertOk()->assertExactJson([
                'ok' => true,
            ]);

            $storedMessage = $this->inboundMessages()
                ->where('external_message_id', 'max-contact-late-merge-93')
                ->firstOrFail();

            Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
                return $job->inboundMessageId === $storedMessage->id
                    && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT;
            });

            $this->assertSame($existingRoot->id, $storedMessage->contact_id);
            $this->assertDatabaseHas('channel_activity_logs', [
                'channel_id' => $channel->id,
                'event' => 'webhook.delayed_received',
            ]);
            $this->assertDatabaseHas('channel_activity_logs', [
                'channel_id' => $channel->id,
                'event' => 'contact.phone_capture_arrived_late',
            ]);
            $this->assertDatabaseHas('channel_activity_logs', [
                'channel_id' => $channel->id,
                'event' => 'contact.phone_merged_to_existing_root',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_max_contact_share_with_unknown_format_logs_skip_event_and_does_not_queue_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = $this->maxPayload(messageId: 'max-contact-91', text: null);
        $payload['message']['body'] = [
            'mid' => 'max-contact-91',
            'contact' => [],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNothingPushed();
        Http::assertNothingSent();

        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseCount('contact_phone_numbers', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'max.contact_share_unknown_format',
        ]);
    }

    public function test_telegram_contact_share_webhook_merges_into_existing_root_and_queues_merged_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $existingRoot = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => '30_39',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $existingRoot->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        $payload = $this->telegramPayload(messageId: 190, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT;
        });

        $this->assertSame($existingRoot->id, $storedMessage->contact_id);
        $this->assertDatabaseCount('contact_merge_logs', 1);
        $this->assertDatabaseCount('contact_duplicate_reviews', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_merged_to_existing_root',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_queued',
        ]);
    }

    public function test_telegram_contact_share_webhook_marks_review_pending_when_phone_matches_multiple_roots(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        foreach ([1, 2] as $index) {
            $contact = Contact::factory()->create([
                'first_name' => 'Контакт '.$index,
            ]);
            ContactPhoneNumber::factory()->create([
                'contact_id' => $contact->id,
                'phone_raw' => '+7 999 123 45 67',
                'phone_normalized' => '+79991234567',
                'is_primary' => true,
            ]);
        }

        $payload = $this->telegramPayload(messageId: 191, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_REVIEW_PENDING;
        });

        $this->assertDatabaseCount('contact_merge_logs', 0);
        $this->assertDatabaseHas('contact_duplicate_reviews', [
            'contact_id' => $storedMessage->contact_id,
            'phone_normalized' => '+79991234567',
            'review_type' => ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_review_pending_multiple_roots',
        ]);
    }

    public function test_telegram_contact_share_with_sender_mismatch_does_not_queue_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $payload = $this->telegramPayload(messageId: 91, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 999,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('contact_phone_numbers', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_skipped_sender_mismatch',
        ]);
    }

    public function test_repeated_telegram_webhook_with_same_update_id_does_not_queue_second_job_after_successful_auto_reply(): void
    {
        Queue::fake();
        Http::fake();

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

        $message = $this->inboundMessages()->firstOrFail();
        $message->forceFill([
            'auto_reply_sent_at' => now(),
        ])->save();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.duplicate_ignored',
        ]);
    }

    public function test_repeated_telegram_webhook_with_same_update_id_requeues_after_previous_failure(): void
    {
        Queue::fake();
        Http::fake();

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
            ->assertOk();

        $message = $this->inboundMessages()->firstOrFail();

        $this->assertSame('43', $message->provider_event_key);
        $this->assertNull($message->auto_reply_sent_at);

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('messages', 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.duplicate_retry_reply',
        ]);
    }

    public function test_repeated_max_webhook_with_same_external_message_id_requeues_after_previous_failure(): void
    {
        Queue::fake();
        Http::fake();

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
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('messages', 1);
        $this->assertSame('max-43', $this->inboundMessages()->firstOrFail()->provider_event_key);
    }

    public function test_repeat_max_webhook_from_same_user_with_different_message_ids_creates_two_inbound_messages(): void
    {
        Queue::fake();
        Http::fake();

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

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
    }

    public function test_inactive_channel_does_not_process_event(): void
    {
        Queue::fake();
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

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_invalid_telegram_webhook_secret_is_rejected(): void
    {
        Queue::fake();
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

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_empty_max_webhook_secret_is_rejected(): void
    {
        Queue::fake();
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

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_repeat_telegram_webhook_from_same_user_reuses_contact_identity_and_contact(): void
    {
        Queue::fake();
        Http::fake();

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

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
    }

    public function test_telegram_webhook_without_update_id_keeps_legacy_non_deduplicated_behavior(): void
    {
        Queue::fake();
        Http::fake();

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

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('messages', 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => null,
        ]);
    }

    public function test_new_telegram_webhook_from_different_user_creates_new_contact_and_identity(): void
    {
        Queue::fake();
        Http::fake();

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

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('contacts', 2);
        $this->assertDatabaseCount('contact_identities', 2);
        $this->assertDatabaseCount('messages', 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'external_user_id' => '201',
            'external_username' => 'telegram_user_2',
        ]);
    }

    public function test_active_data_collection_routes_inbound_user_to_collector_instead_of_auto_reply(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now(),
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 901,
            text: 'Герман',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessDataCollectionResponseJob::class, function (ProcessDataCollectionResponseJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_response_queued',
        ]);
    }

    public function test_active_age_range_callback_routes_to_collector_and_answers_callback(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'data_collection_started_at' => now(),
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            userId: 200,
            chatId: 300,
            callbackId: 'callback-901',
            callbackData: 'age_range:24_29',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame('24_29', $storedMessage->text);
        Queue::assertPushed(ProcessDataCollectionResponseJob::class, function (ProcessDataCollectionResponseJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-901');
    }

    public function test_stale_age_range_callback_is_answered_and_ignored(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            userId: 200,
            chatId: 300,
            callbackId: 'callback-902',
            callbackData: 'age_range:24_29',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-902');
    }

    public function test_active_russian_region_confirm_callback_routes_to_collector_and_answers_callback(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM,
            'pending_region_candidates' => ['Волгоградская область', 'Приморский край'],
            'data_collection_started_at' => now(),
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            userId: 200,
            chatId: 300,
            callbackId: 'callback-903',
            callbackData: 'russian_region_confirm:2',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame('russian_region_confirm:2', $storedMessage->text);
        Queue::assertPushed(ProcessDataCollectionResponseJob::class, function (ProcessDataCollectionResponseJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-903');
    }

    public function test_stale_russian_region_confirm_callback_is_answered_and_ignored(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'pending_region_candidates' => ['Волгоградская область', 'Приморский край'],
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            userId: 200,
            chatId: 300,
            callbackId: 'callback-904',
            callbackData: 'russian_region_confirm:2',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-904');
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
    protected function telegramCallbackPayload(
        int|string $userId = 200,
        int|string $chatId = 300,
        string $callbackId = 'callback-1',
        string $callbackData = 'age_range:24_29',
        int|string $messageId = 10,
        ?string $username = 'telegram_user',
        int $date = 1_711_539_200,
        bool $includeUpdateId = true,
    ): array {
        $payload = [
            'callback_query' => [
                'id' => $callbackId,
                'data' => $callbackData,
                'from' => [
                    'id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'message' => [
                    'message_id' => $messageId,
                    'date' => $date,
                    'chat' => [
                        'id' => $chatId,
                        'type' => 'private',
                    ],
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

    /**
     * @return array<string, mixed>
     */
    protected function maxBotStartedPayload(
        int|string $userId = 500,
        int|string $chatId = 700,
        ?string $payload = 'promo_123',
        string $timestamp = '2026-04-03T10:00:00+03:00',
    ): array {
        $update = [
            'update_type' => 'bot_started',
            'chat_id' => $chatId,
            'timestamp' => $timestamp,
            'user' => [
                'user_id' => $userId,
                'username' => 'max_user',
                'name' => 'Герман',
            ],
        ];

        if ($payload !== null) {
            $update['payload'] = $payload;
        }

        return $update;
    }

    protected function assertMessageDirectionCount(string $direction, int $expectedCount): void
    {
        $this->assertSame(
            $expectedCount,
            Message::query()->where('direction', $direction)->count(),
        );
    }

    protected function inboundMessages()
    {
        return Message::query()->where('direction', Message::DIRECTION_INBOUND);
    }

    protected function outboundMessages()
    {
        return Message::query()->where('direction', Message::DIRECTION_OUTBOUND);
    }
}
