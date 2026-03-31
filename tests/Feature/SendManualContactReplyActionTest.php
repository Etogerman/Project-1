<?php

namespace Tests\Feature;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Bots\SendManualContactReplyAction;
use App\Services\Bots\StoreInboundMessageAction;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendManualContactReplyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_reply_creates_dialog_and_operator_sender_metadata(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9901,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $contact = Contact::factory()->create([
            'assigned_user_id' => null,
        ]);

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $inboundMessage = Message::factory()->create([
            'dialog_id' => null,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '300',
            'external_message_id' => '10',
            'text' => 'Привет',
            'received_at' => now()->subMinute(),
        ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $outboundMessage = app(SendManualContactReplyAction::class)->handle(
            $contact,
            $employee,
            'Здравствуйте',
        );

        $this->assertNotNull($outboundMessage->dialog_id);
        $this->assertSame(Message::SENT_BY_TYPE_OPERATOR, $outboundMessage->sent_by_type);
        $this->assertSame($employee->id, $outboundMessage->sent_by_user_id);
        $this->assertNull($outboundMessage->sent_by_system_code);
        $this->assertDatabaseHas('dialogs', [
            'id' => $outboundMessage->dialog_id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);
        $this->assertSame($inboundMessage->id, $outboundMessage->reply_to_message_id);
        $this->assertDatabaseHas('messages', [
            'id' => $outboundMessage->id,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'text' => 'Здравствуйте',
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_user_id' => $employee->id,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.dialog_route_fallback_used',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '300'
            && $request['text'] === 'Здравствуйте');
    }

    public function test_manual_reply_uses_latest_routeable_dialog_and_latest_inbound_inside_that_dialog(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9902,
                ],
            ]),
        ]);

        $olderChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token-old',
            ],
        ]);
        $newerChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token-new',
            ],
        ]);

        $contact = Contact::factory()->create();

        $olderIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $olderChannel->id,
            'platform' => $olderChannel->platform,
            'external_user_id' => 'old-user',
        ]);
        $newerIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $newerChannel->id,
            'platform' => $newerChannel->platform,
            'external_user_id' => 'new-user',
        ]);

        $olderDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $olderChannel->id,
            'current_contact_identity_id' => $olderIdentity->id,
            'external_chat_id' => '100',
            'last_message_at' => now()->subMinutes(20),
            'last_inbound_at' => now()->subMinutes(20),
        ]);
        $newerDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $newerChannel->id,
            'current_contact_identity_id' => $newerIdentity->id,
            'external_chat_id' => '200',
            'last_message_at' => now()->subMinutes(10),
            'last_inbound_at' => now()->subMinutes(10),
        ]);

        Message::factory()->create([
            'dialog_id' => $olderDialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $olderIdentity->id,
            'channel_id' => $olderChannel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '100',
            'external_message_id' => 'older-dialog-message',
            'received_at' => now()->subMinutes(20),
        ]);
        $replyTarget = Message::factory()->create([
            'dialog_id' => $newerDialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $newerIdentity->id,
            'channel_id' => $newerChannel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '200',
            'external_message_id' => 'newer-dialog-message',
            'received_at' => now()->subMinutes(10),
        ]);
        Message::factory()->create([
            'dialog_id' => null,
            'contact_id' => $contact->id,
            'contact_identity_id' => $olderIdentity->id,
            'channel_id' => $olderChannel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '999',
            'external_message_id' => 'legacy-latest-message',
            'received_at' => now()->subMinute(),
        ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $outboundMessage = app(SendManualContactReplyAction::class)->handle(
            $contact,
            $employee,
            'Иду в актуальный диалог',
        );

        $this->assertSame($newerDialog->id, $outboundMessage->dialog_id);
        $this->assertSame($newerChannel->id, $outboundMessage->channel_id);
        $this->assertSame($newerIdentity->id, $outboundMessage->contact_identity_id);
        $this->assertSame('200', $outboundMessage->external_chat_id);
        $this->assertSame($replyTarget->id, $outboundMessage->reply_to_message_id);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token-new/sendMessage'
            && $request['chat_id'] === '200'
            && $request['text'] === 'Иду в актуальный диалог');
    }

    public function test_manual_reply_can_store_empty_external_chat_id_for_max_user_route(): void
    {
        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'message' => [
                    'message_id' => 'max-manual-002',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        $contact = Contact::factory()->create([
            'assigned_user_id' => null,
        ]);

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        $inboundMessage = Message::factory()->create([
            'dialog_id' => null,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '',
            'external_message_id' => 'max-inbound-legacy',
            'received_at' => now()->subMinute(),
        ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $outboundMessage = app(SendManualContactReplyAction::class)->handle(
            $contact,
            $employee,
            'MAX user route',
        );

        $dialog = Dialog::query()->findOrFail($outboundMessage->dialog_id);

        $this->assertNotNull($outboundMessage->dialog_id);
        $this->assertNull($dialog->external_chat_id);
        $this->assertSame($identity->id, $dialog->current_contact_identity_id);
        $this->assertSame($identity->id, $outboundMessage->contact_identity_id);
        $this->assertSame('', $outboundMessage->external_chat_id);
        $this->assertSame($inboundMessage->id, $outboundMessage->reply_to_message_id);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'user_id=228532008')
            && $request['text'] === 'MAX user route');
    }

    public function test_manual_reply_uses_user_route_after_newer_max_inbound_clears_stale_dialog_chat_id(): void
    {
        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'message' => [
                    'message_id' => 'max-manual-fresh-user-route',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);
        $contact = Contact::factory()->create([
            'assigned_user_id' => null,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '700',
            'last_message_at' => Carbon::parse('2026-03-28 18:00:00'),
            'last_inbound_at' => Carbon::parse('2026-03-28 18:00:00'),
        ]);

        app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '',
                externalUserId: '228532008',
                providerEventKey: 'max-manual-user-route-fresh',
                externalMessageId: 'max-manual-user-route-fresh',
                externalUsername: 'max_user',
                contactName: 'MAX contact',
                text: 'Свежий MAX контекст',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['body' => ['text' => 'Свежий MAX контекст']]],
                receivedAt: Carbon::parse('2026-03-28 18:05:00'),
            ),
        );

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $outboundMessage = app(SendManualContactReplyAction::class)->handle(
            $contact,
            $employee,
            'Отвечаю по user route',
        );

        $dialog->refresh();

        $this->assertNull($dialog->external_chat_id);
        $this->assertSame($dialog->id, $outboundMessage->dialog_id);
        $this->assertSame($identity->id, $outboundMessage->contact_identity_id);
        $this->assertSame('', $outboundMessage->external_chat_id);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'user_id=228532008')
            && ! str_contains($request->url(), 'chat_id=')
            && $request['text'] === 'Отвечаю по user route');
    }
}
