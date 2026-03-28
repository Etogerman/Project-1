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
}
