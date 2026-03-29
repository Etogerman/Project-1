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
        $this->assertSame('88', $message->externalMessageId);
        $this->assertSame('77', $message->providerEventKey);
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
