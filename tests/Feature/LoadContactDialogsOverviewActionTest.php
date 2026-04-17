<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Dialogs\LoadContactDialogsOverviewAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoadContactDialogsOverviewActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_text_uses_plain_fallback_for_html_message(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'preview-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'preview-chat',
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_user_id' => $employee->id,
            'external_chat_id' => 'preview-chat',
            'text' => 'HTML preview',
            'text_format' => Message::TEXT_FORMAT_HTML,
            'source_text' => '<b>HTML preview</b>',
            'received_at' => now(),
        ]);

        $overview = app(LoadContactDialogsOverviewAction::class)->handle($contact);

        $this->assertCount(1, $overview);
        $this->assertSame('HTML preview', $overview[0]['preview_text']);
    }

    public function test_overview_uses_system_preview_sender_for_telegram_unsubscribe_event(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'preview-system-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'preview-system-chat',
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => now(),
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_SYSTEM_EVENT,
            'system_event_code' => Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION,
            'external_chat_id' => 'preview-system-chat',
            'text' => null,
            'received_at' => now(),
        ]);

        $overview = app(LoadContactDialogsOverviewAction::class)->handle($contact);

        $this->assertCount(1, $overview);
        $this->assertSame('Клиент заблокировал бота', $overview[0]['preview_text']);
        $this->assertSame('Система', $overview[0]['preview_sender_label']);
        $this->assertSame('gray', $overview[0]['preview_sender_tone']);
        $this->assertSame('Бот заблокирован', $overview[0]['route_status_label']);
    }

    public function test_overview_preview_ignores_dialog_status_history_note(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'overview-status-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'overview-status-chat',
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Последнее реальное сообщение',
            'received_at' => now()->subMinute(),
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE,
            'text' => 'Оператор изменил статус диалога',
            'received_at' => now(),
        ]);

        $overview = app(LoadContactDialogsOverviewAction::class)->handle($contact);

        $this->assertCount(1, $overview);
        $this->assertSame('Последнее реальное сообщение', $overview[0]['preview_text']);
    }

    public function test_overview_preview_keeps_latest_legacy_message_with_null_kind(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'overview-legacy-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'overview-legacy-chat',
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Старое обычное overview сообщение',
            'received_at' => now()->subMinutes(2),
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => null,
            'text' => 'Последнее legacy overview сообщение',
            'received_at' => now()->subMinute(),
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE,
            'text' => 'Оператор изменил статус диалога',
            'received_at' => now(),
        ]);

        $overview = app(LoadContactDialogsOverviewAction::class)->handle($contact);

        $this->assertCount(1, $overview);
        $this->assertSame('Последнее legacy overview сообщение', $overview[0]['preview_text']);
    }
}
