<?php

namespace Tests\Feature;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use App\Services\Bots\BotIncomingMessageNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotIncomingMessageNormalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_contact_share_payload_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 77,
            'message' => [
                'message_id' => 88,
                'date' => 1_711_539_200,
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
                'contact' => [
                    'phone_number' => '+7 999 123 45 67',
                    'user_id' => 200,
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE, $message->inboundKind);
        $this->assertSame('+7 999 123 45 67', $message->sharedPhoneNumber);
        $this->assertSame('200', $message->sharedContactUserId);
        $this->assertNull($message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('88', $message->externalMessageId);
        $this->assertSame('77', $message->providerEventKey);
    }

    public function test_telegram_start_payload_is_normalized_into_message_parameter(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 78,
            'message' => [
                'message_id' => 89,
                'date' => 1_711_539_200,
                'text' => '/start TEXT_1',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('/start TEXT_1', $message->text);
        $this->assertSame('TEXT_1', $message->messageParameter);
    }

    public function test_plain_telegram_start_does_not_produce_message_parameter(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 79,
            'message' => [
                'message_id' => 90,
                'date' => 1_711_539_200,
                'text' => '/start',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('/start', $message->text);
        $this->assertNull($message->messageParameter);
    }

    public function test_regular_telegram_text_does_not_produce_message_parameter(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 80,
            'message' => [
                'message_id' => 91,
                'date' => 1_711_539_200,
                'text' => 'hello',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('hello', $message->text);
        $this->assertNull($message->messageParameter);
    }

    public function test_telegram_age_range_callback_query_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 91,
            'callback_query' => [
                'id' => 'callback-91',
                'data' => 'age_range:24_29',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'first_name' => 'Герман',
                    'is_bot' => false,
                ],
                'message' => [
                    'message_id' => 88,
                    'date' => 1_711_539_200,
                    'chat' => [
                        'id' => 300,
                        'type' => 'private',
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame('24_29', $message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('callback-91', $message->externalMessageId);
        $this->assertSame('91', $message->providerEventKey);
    }

    public function test_telegram_russian_region_confirm_callback_query_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 92,
            'callback_query' => [
                'id' => 'callback-92',
                'data' => 'russian_region_confirm:2',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'first_name' => 'Герман',
                    'is_bot' => false,
                ],
                'message' => [
                    'message_id' => 89,
                    'date' => 1_711_539_200,
                    'chat' => [
                        'id' => 300,
                        'type' => 'private',
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame('russian_region_confirm:2', $message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('callback-92', $message->externalMessageId);
        $this->assertSame('92', $message->providerEventKey);
    }

    public function test_telegram_warmup_scenario_callback_query_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 93,
            'callback_query' => [
                'id' => 'callback-93',
                'data' => 'scenario:warmup:17:positive',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'first_name' => 'Герман',
                    'is_bot' => false,
                ],
                'message' => [
                    'message_id' => 90,
                    'date' => 1_711_539_200,
                    'chat' => [
                        'id' => 300,
                        'type' => 'private',
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame('warmup:positive', $message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('callback-93', $message->externalMessageId);
        $this->assertSame('93', $message->providerEventKey);
    }

    public function test_telegram_generic_scenario_callback_query_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 94,
            'callback_query' => [
                'id' => 'callback-94',
                'data' => 'scenario:17:start_selection',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'first_name' => 'Герман',
                    'is_bot' => false,
                ],
                'message' => [
                    'message_id' => 91,
                    'date' => 1_711_539_200,
                    'chat' => [
                        'id' => 300,
                        'type' => 'private',
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame('scenario:start_selection', $message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('callback-94', $message->externalMessageId);
        $this->assertSame('94', $message->providerEventKey);
    }

    public function test_max_bot_started_payload_is_normalized_as_store_only_inbound_event(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'bot_started',
            'chat_id' => 700,
            'payload' => 'promo_123',
            'timestamp' => '2026-04-03T10:00:00+03:00',
            'user' => [
                'user_id' => 500,
                'username' => 'max_user',
                'name' => 'Герман',
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame('700', $message->externalChatId);
        $this->assertSame('500', $message->externalUserId);
        $this->assertSame('max_user', $message->externalUsername);
        $this->assertSame('Герман', $message->contactName);
        $this->assertNull($message->externalMessageId);
        $this->assertNull($message->text);
        $this->assertSame('promo_123', $message->messageParameter);
        $this->assertSame('bot_started', data_get($message->rawPayload, 'update_type'));
        $this->assertStringStartsWith('max-bot-started:', $message->providerEventKey ?? '');
        $this->assertSame('2026-04-03 07:00:00', $message->receivedAt->utc()->format('Y-m-d H:i:s'));
    }

    public function test_max_bot_started_payload_without_deep_link_payload_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'bot_started',
            'chat_id' => 701,
            'timestamp' => '2026-04-03T10:05:00+03:00',
            'user' => [
                'user_id' => 501,
                'is_bot' => false,
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame('701', $message->externalChatId);
        $this->assertSame('501', $message->externalUserId);
        $this->assertNull($message->text);
        $this->assertNull($message->messageParameter);
        $this->assertStringStartsWith('max-bot-started:', $message->providerEventKey ?? '');
    }

    public function test_max_bot_started_payload_with_blank_deep_link_payload_does_not_produce_message_parameter(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'bot_started',
            'chat_id' => 702,
            'payload' => '   ',
            'timestamp' => '2026-04-03T10:06:00+03:00',
            'user' => [
                'user_id' => 502,
                'is_bot' => false,
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('702', $message->externalChatId);
        $this->assertSame('502', $message->externalUserId);
        $this->assertNull($message->text);
        $this->assertNull($message->messageParameter);
        $this->assertStringStartsWith('max-bot-started:', $message->providerEventKey ?? '');
    }

    public function test_max_contact_share_payload_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'username' => 'max_user',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-contact-77',
                    'contact' => [
                        'phone' => '+7 999 123 45 67',
                        'user_id' => 500,
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE, $message->inboundKind);
        $this->assertSame('+7 999 123 45 67', $message->sharedPhoneNumber);
        $this->assertSame('500', $message->sharedContactUserId);
        $this->assertNull($message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('max-contact-77', $message->externalMessageId);
        $this->assertSame('max-contact-77', $message->providerEventKey);
    }

    public function test_max_contact_share_vcf_attachment_payload_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 228532008,
                    'first_name' => 'German',
                    'last_name' => 'Abrikosov',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 66552012,
                ],
                'body' => [
                    'mid' => 'max-contact-vcf-1',
                    'text' => null,
                    'attachments' => [[
                        'type' => 'contact',
                        'payload' => [
                            'max_info' => [
                                'user_id' => 228532008,
                            ],
                            'vcf_info' => "BEGIN:VCARD\r\nVERSION:3.0\r\nTEL;TYPE=cell:79263527111\r\nFN:German Abrikosov\r\nEND:VCARD",
                        ],
                    ]],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE, $message->inboundKind);
        $this->assertSame('79263527111', $message->sharedPhoneNumber);
        $this->assertSame('228532008', $message->sharedContactUserId);
        $this->assertNull($message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('max-contact-vcf-1', $message->externalMessageId);
        $this->assertSame('max-contact-vcf-1', $message->providerEventKey);
    }

    public function test_max_contact_share_with_unknown_format_is_still_marked_as_contact_share(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
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
                    'mid' => 'max-contact-unknown',
                    'contact' => [],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE, $message->inboundKind);
        $this->assertNull($message->sharedPhoneNumber);
        $this->assertNull($message->text);
    }
}
