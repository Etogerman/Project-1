<?php

namespace Tests\Feature;

use App\Data\Bots\IncomingBotMessage;
use App\Data\Bots\StoredInboundMessageResult;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Bots\StoreInboundMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StoreInboundMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_inbound_message_saves_phone_from_contact_share(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '300',
                externalUserId: '200',
                providerEventKey: 'telegram-update-101',
                externalMessageId: '101',
                externalUsername: 'telegram_user',
                contactName: 'Тестовый контакт',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 123 45 67',
                sharedContactUserId: '200',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67']]],
                receivedAt: Carbon::parse('2026-03-28 18:00:00'),
            ),
        );

        $storedMessage = $storedResult->message;

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED, $storedResult->phoneCaptureStatus);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $storedMessage->contact_id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'source' => 'telegram_contact_share',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_captured',
        ]);
    }

    public function test_store_inbound_message_skips_phone_capture_on_sender_mismatch(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '300',
                externalUserId: '200',
                providerEventKey: 'telegram-update-102',
                externalMessageId: '102',
                externalUsername: 'telegram_user',
                contactName: 'Тестовый контакт',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 123 45 67',
                sharedContactUserId: '999',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67', 'user_id' => '999']]],
                receivedAt: Carbon::parse('2026-03-28 18:00:00'),
            ),
        );

        $storedMessage = $storedResult->message;

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_SENDER_MISMATCH, $storedResult->phoneCaptureStatus);
        $this->assertDatabaseCount('contact_phone_numbers', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_skipped_sender_mismatch',
        ]);
    }

    public function test_store_inbound_message_marks_duplicate_phone_capture_status_when_number_already_exists(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $firstResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '300',
                externalUserId: '200',
                providerEventKey: 'telegram-update-201',
                externalMessageId: '201',
                externalUsername: 'telegram_user',
                contactName: 'Тестовый контакт',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 123 45 67',
                sharedContactUserId: '200',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67']]],
                receivedAt: Carbon::parse('2026-03-28 18:00:00'),
            ),
        );

        $duplicateResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '300',
                externalUserId: '200',
                providerEventKey: 'telegram-update-201',
                externalMessageId: '201',
                externalUsername: 'telegram_user',
                contactName: 'Тестовый контакт',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 123 45 67',
                sharedContactUserId: '200',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67']]],
                receivedAt: Carbon::parse('2026-03-28 18:00:01'),
            ),
        );

        $this->assertTrue($firstResult->message->is($duplicateResult->message));
        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_DUPLICATE, $duplicateResult->phoneCaptureStatus);
        $this->assertDatabaseCount('contact_phone_numbers', 1);
    }
}
