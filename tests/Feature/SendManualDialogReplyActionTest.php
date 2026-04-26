<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelRuntimeState;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\TelegramAccountOutgoingMessage;
use App\Models\User;
use App\Services\Bots\SendManualDialogReplyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class SendManualDialogReplyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_manual_dialog_reply_sends_through_exact_dialog(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 99101,
                ],
            ]),
        ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'assigned_user_id' => $employee->id,
        ]);
        $targetChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-target-token'],
        ]);
        $otherChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-other-token'],
        ]);
        $targetIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $targetChannel->id,
            'platform' => $targetChannel->platform,
            'external_user_id' => 'target-user',
        ]);
        $otherIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $otherChannel->id,
            'platform' => $otherChannel->platform,
            'external_user_id' => 'other-user',
        ]);
        $targetDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $targetChannel->id,
            'current_contact_identity_id' => $targetIdentity->id,
            'external_chat_id' => 'chat-target',
            'last_message_at' => now()->subMinutes(10),
            'last_inbound_at' => now()->subMinutes(10),
        ]);
        $otherDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $otherChannel->id,
            'current_contact_identity_id' => $otherIdentity->id,
            'external_chat_id' => 'chat-other',
            'last_message_at' => now()->subMinute(),
            'last_inbound_at' => now()->subMinute(),
        ]);

        $replyTarget = Message::factory()->create([
            'dialog_id' => $targetDialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $targetIdentity->id,
            'channel_id' => $targetChannel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-target',
            'external_message_id' => 'target-inbound',
            'received_at' => now()->subMinutes(10),
        ]);
        Message::factory()->create([
            'dialog_id' => $otherDialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $otherIdentity->id,
            'channel_id' => $otherChannel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-other',
            'external_message_id' => 'other-inbound',
            'received_at' => now()->subMinute(),
        ]);

        $outboundMessage = app(SendManualDialogReplyAction::class)->handle(
            $targetDialog,
            $employee,
            'Отвечаю в точный диалог',
        );

        $this->assertSame($targetDialog->id, $outboundMessage->dialog_id);
        $this->assertSame($targetChannel->id, $outboundMessage->channel_id);
        $this->assertSame($replyTarget->id, $outboundMessage->reply_to_message_id);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bottelegram-target-token/sendMessage'
            && $request['chat_id'] === 'chat-target'
            && $request['text'] === 'Отвечаю в точный диалог');
    }

    public function test_send_manual_dialog_reply_uses_message_chronology_for_reply_target(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 99111,
                ],
            ]),
        ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createTelegramDialog(assignedUserId: $employee->id, externalChatId: 'chat-chronology');
        $contact = $dialog->contact;
        $identity = $dialog->currentContactIdentity;
        $channel = $dialog->channel;

        $receivedAtTarget = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-chronology',
            'external_message_id' => 'telegram-inbound-received',
            'received_at' => now()->subHour(),
            'created_at' => now()->subHours(2),
        ]);

        $createdAtTarget = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-chronology',
            'external_message_id' => 'telegram-inbound-created',
            'received_at' => null,
            'created_at' => now()->subSeconds(10),
        ]);

        $outboundMessage = app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            'Хронологический ответ',
        );

        $this->assertNotSame($receivedAtTarget->id, $outboundMessage->reply_to_message_id);
        $this->assertSame($createdAtTarget->id, $outboundMessage->reply_to_message_id);
    }

    public function test_send_manual_dialog_reply_uses_created_at_fallback_for_reply_to_when_received_at_is_null(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 99112,
                ],
            ]),
        ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'assigned_user_id' => $employee->id,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-fallback-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'fallback-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'chat-fallback',
        ]);

        $olderInbound = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-fallback',
            'external_message_id' => 'telegram-inbound-older',
            'received_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(2),
        ]);

        $fallbackReplyTarget = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-fallback',
            'external_message_id' => 'telegram-inbound-fallback',
            'received_at' => null,
            'created_at' => now()->subSeconds(5),
        ]);

        $outboundMessage = app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            'Проверка reply-to fallback',
        );

        $this->assertSame($fallbackReplyTarget->id, $outboundMessage->reply_to_message_id);
        $this->assertNotSame($olderInbound->id, $outboundMessage->reply_to_message_id);
    }

    public function test_send_manual_dialog_reply_sends_html_and_stores_plain_text_fallback(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 99113,
                ],
            ]),
        ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createTelegramDialog(assignedUserId: $employee->id, externalChatId: 'chat-html');

        $outboundMessage = app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            "<b>HTML ответ</b>\n<pre>строка 1\nстрока 2</pre>",
            Message::TEXT_FORMAT_HTML,
        );

        $this->assertSame(Message::TEXT_FORMAT_HTML, $outboundMessage->text_format);
        $this->assertSame("HTML ответ\nстрока 1\nстрока 2", $outboundMessage->text);
        $this->assertSame("<b>HTML ответ</b>\n<pre>строка 1\nстрока 2</pre>", $outboundMessage->source_text);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === 'chat-html'
            && $request['text'] === $outboundMessage->source_text
            && $request['parse_mode'] === 'HTML');
    }

    public function test_send_manual_dialog_reply_unwraps_links_without_valid_href_before_transport(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 99114,
                ],
            ]),
        ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createTelegramDialog(assignedUserId: $employee->id, externalChatId: 'chat-html-links');

        $outboundMessage = app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            '<a href="javascript:alert(1)">плохая ссылка</a> <a href="https://example.com">хорошая ссылка</a>',
            Message::TEXT_FORMAT_HTML,
        );

        $this->assertSame(
            'плохая ссылка <a href="https://example.com">хорошая ссылка</a>',
            $outboundMessage->source_text,
        );

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === 'chat-html-links'
            && $request['text'] === 'плохая ссылка <a href="https://example.com">хорошая ссылка</a>'
            && $request['parse_mode'] === 'HTML');
    }

    public function test_send_manual_dialog_reply_keeps_unassigned_contact_without_auto_claim(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 99102,
                ],
            ]),
        ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createTelegramDialog(assignedUserId: null, externalChatId: 'chat-auto-claim');

        app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            'Ответ без автозахвата',
        );

        $dialog->contact->refresh();

        $this->assertNull($dialog->contact->assigned_user_id);
    }

    public function test_send_manual_dialog_reply_allows_employee_for_foreign_assignee_without_reassigning_contact(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 99103,
                ],
            ]),
        ]);

        $owner = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Другой сотрудник',
        ]);
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $dialog = $this->createTelegramDialog(assignedUserId: $owner->id, externalChatId: 'chat-foreign');

        $outboundMessage = app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            'Ответ по чужому контакту',
        );

        $this->assertSame($dialog->id, $outboundMessage->dialog_id);
        $this->assertSame($owner->id, $dialog->contact->fresh()->assigned_user_id);
    }

    public function test_send_manual_dialog_reply_supports_max_user_route_without_chat_id(): void
    {
        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'message' => [
                    'message_id' => 'max-dialog-001',
                ],
            ]),
        ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'assigned_user_id' => $employee->id,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
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
            'external_chat_id' => null,
        ]);
        $replyTarget = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '',
            'external_message_id' => 'max-inbound-user-route',
            'received_at' => now()->subMinute(),
        ]);

        $outboundMessage = app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            'MAX user route через dialog',
        );

        $this->assertSame($dialog->id, $outboundMessage->dialog_id);
        $this->assertSame($replyTarget->id, $outboundMessage->reply_to_message_id);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'user_id=228532008')
            && ! str_contains($request->url(), 'chat_id=')
            && $request['text'] === 'MAX user route через dialog');
    }

    public function test_send_manual_dialog_reply_queues_telegram_account_reply_for_gateway(): void
    {
        Http::fake();

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createTelegramAccountDialog(assignedUserId: $employee->id, externalChatId: 'account-chat-100');

        $outboundMessage = app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            'Ответ через Telegram account',
        );

        $outgoing = TelegramAccountOutgoingMessage::query()->firstOrFail();

        $this->assertSame($dialog->id, $outboundMessage->dialog_id);
        $this->assertSame(Message::DIRECTION_OUTBOUND, $outboundMessage->direction);
        $this->assertSame(Message::KIND_OUTBOUND_MANUAL_REPLY, $outboundMessage->message_kind);
        $this->assertNull($outboundMessage->external_message_id);
        $this->assertSame('telegram_account_gateway', data_get($outboundMessage->raw_payload, 'provider'));
        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_PENDING, data_get($outboundMessage->raw_payload, 'delivery_status'));
        $this->assertSame($outgoing->id, data_get($outboundMessage->raw_payload, 'outgoing_message_id'));
        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_PENDING, $outgoing->status);
        $this->assertSame('account-chat-100', $outgoing->external_chat_id);
        $this->assertSame('Ответ через Telegram account', $outgoing->text);
        $this->assertNull($dialog->channel->fresh()->last_reply_sent_at);

        Http::assertNothingSent();
    }

    public function test_send_manual_dialog_reply_rejects_telegram_account_html_until_gateway_supports_it(): void
    {
        Http::fake();

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createTelegramAccountDialog(assignedUserId: $employee->id, externalChatId: 'account-chat-html');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Для Telegram account пока доступен только простой текст.');

        app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            '<b>HTML пока нельзя</b>',
            Message::TEXT_FORMAT_HTML,
        );
    }

    public function test_send_manual_dialog_reply_fails_when_exact_dialog_has_no_sendable_route(): void
    {
        Http::fake();

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createTelegramDialog(assignedUserId: $employee->id, externalChatId: null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('У этого диалога сейчас нет рабочего маршрута для отправки ответа.');

        app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            'Неотправляемый route',
        );
    }

    public function test_send_manual_dialog_reply_fails_when_exact_dialog_is_missing_token(): void
    {
        Http::fake();

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createTelegramDialog(assignedUserId: $employee->id, externalChatId: 'chat-no-token');
        $dialog->channel->update([
            'credentials' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('У этого диалога сейчас нет рабочего маршрута для отправки ответа.');

        app(SendManualDialogReplyAction::class)->handle(
            $dialog->fresh(['channel', 'currentContactIdentity', 'contact.assignedUser']),
            $employee,
            'Нет токена',
        );
    }

    protected function createTelegramDialog(?int $assignedUserId, ?string $externalChatId): Dialog
    {
        $contact = Contact::factory()->create([
            'assigned_user_id' => $assignedUserId,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => $externalChatId,
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $externalChatId ?? '',
            'external_message_id' => 'telegram-inbound',
            'received_at' => now()->subMinute(),
        ]);

        return $dialog->fresh(['contact.assignedUser', 'channel', 'currentContactIdentity']);
    }

    protected function createTelegramAccountDialog(?int $assignedUserId, string $externalChatId): Dialog
    {
        $contact = Contact::factory()->create([
            'assigned_user_id' => $assignedUserId,
        ]);
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-account-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => $externalChatId,
        ]);

        ChannelRuntimeState::query()->create([
            'channel_id' => $channel->id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
            'last_gateway_heartbeat_at' => now(),
            'runtime_payload' => [
                'gateway_capabilities' => [
                    'outgoing_replies' => true,
                ],
            ],
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $externalChatId,
            'external_message_id' => 'telegram-account-inbound',
            'received_at' => now()->subMinute(),
        ]);

        return $dialog->fresh(['contact.assignedUser', 'channel.runtimeState', 'currentContactIdentity']);
    }
}
