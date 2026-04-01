<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
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

    public function test_send_manual_dialog_reply_auto_claims_unassigned_contact(): void
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
            'Автозахват перед ответом',
        );

        $dialog->contact->refresh();

        $this->assertSame($employee->id, $dialog->contact->assigned_user_id);
    }

    public function test_send_manual_dialog_reply_blocks_foreign_assignee(): void
    {
        Http::fake();

        $owner = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Другой сотрудник',
        ]);
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createTelegramDialog(assignedUserId: $owner->id, externalChatId: 'chat-foreign');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Контакт уже назначен сотруднику Другой сотрудник.');

        app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            'Попытка чужого ответа',
        );
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
}
