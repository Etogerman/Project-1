<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Dialogs\SyncDialogConfirmedPhoneAction;
use App\Services\Dialogs\SyncMessageDialogMetadataAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DialogMetadataSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_older_inbound_sync_does_not_downgrade_last_inbound_or_route_source(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $olderIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'older-user',
        ]);
        $currentIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'current-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $currentIdentity->id,
            'external_chat_id' => '500',
            'last_message_at' => Carbon::parse('2026-03-31 12:10:00'),
            'last_inbound_at' => Carbon::parse('2026-03-31 12:10:00'),
        ]);
        $message = Message::factory()->create([
            'dialog_id' => null,
            'contact_id' => $contact->id,
            'contact_identity_id' => $olderIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '300',
            'received_at' => Carbon::parse('2026-03-31 12:05:00'),
            'sent_by_type' => null,
        ]);

        DB::transaction(function () use ($message, $contact, $channel, $olderIdentity): void {
            app(SyncMessageDialogMetadataAction::class)->handle(
                $message,
                $contact,
                $channel,
                $olderIdentity,
                '300',
                Message::SENT_BY_TYPE_CONTACT,
            );
        });

        $dialog->refresh();
        $message->refresh();

        $this->assertSame($dialog->id, $message->dialog_id);
        $this->assertSame(Message::SENT_BY_TYPE_CONTACT, $message->sent_by_type);
        $this->assertSame($currentIdentity->id, $dialog->current_contact_identity_id);
        $this->assertSame('500', $dialog->external_chat_id);
        $this->assertSame('2026-03-31 12:10:00', $dialog->last_inbound_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 12:10:00', $dialog->last_message_at?->format('Y-m-d H:i:s'));
    }

    public function test_older_outbound_sync_does_not_downgrade_last_outbound_or_last_message(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'operator-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '500',
            'last_message_at' => Carbon::parse('2026-03-31 12:20:00'),
            'last_outbound_at' => Carbon::parse('2026-03-31 12:20:00'),
        ]);
        $employee = User::factory()->create();
        $message = Message::factory()->create([
            'dialog_id' => null,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'external_chat_id' => '500',
            'received_at' => Carbon::parse('2026-03-31 12:15:00'),
            'sent_by_type' => null,
        ]);

        DB::transaction(function () use ($message, $contact, $channel, $identity, $employee): void {
            app(SyncMessageDialogMetadataAction::class)->handle(
                $message,
                $contact,
                $channel,
                $identity,
                '500',
                Message::SENT_BY_TYPE_OPERATOR,
                $employee->id,
            );
        });

        $dialog->refresh();
        $message->refresh();

        $this->assertSame($dialog->id, $message->dialog_id);
        $this->assertSame(Message::SENT_BY_TYPE_OPERATOR, $message->sent_by_type);
        $this->assertSame($employee->id, $message->sent_by_user_id);
        $this->assertSame('2026-03-31 12:20:00', $dialog->last_outbound_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 12:20:00', $dialog->last_message_at?->format('Y-m-d H:i:s'));
    }

    public function test_equal_timestamp_inbound_keeps_current_greater_or_equal_refresh_rule(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $olderIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'older-user',
        ]);
        $newerIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'newer-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $olderIdentity->id,
            'external_chat_id' => '300',
            'last_message_at' => Carbon::parse('2026-03-31 12:30:00'),
            'last_inbound_at' => Carbon::parse('2026-03-31 12:30:00'),
        ]);
        $message = Message::factory()->create([
            'dialog_id' => null,
            'contact_id' => $contact->id,
            'contact_identity_id' => $newerIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '399',
            'received_at' => Carbon::parse('2026-03-31 12:30:00'),
            'sent_by_type' => null,
        ]);

        DB::transaction(function () use ($message, $contact, $channel, $newerIdentity): void {
            app(SyncMessageDialogMetadataAction::class)->handle(
                $message,
                $contact,
                $channel,
                $newerIdentity,
                '399',
                Message::SENT_BY_TYPE_CONTACT,
            );
        });

        $dialog->refresh();

        $this->assertSame($newerIdentity->id, $dialog->current_contact_identity_id);
        $this->assertSame('399', $dialog->external_chat_id);
        $this->assertSame('2026-03-31 12:30:00', $dialog->last_inbound_at?->format('Y-m-d H:i:s'));
    }

    public function test_inbound_sync_uses_received_at_for_message_chronology(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'created-at-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'last_message_at' => Carbon::parse('2026-03-31 12:00:00'),
            'last_inbound_at' => Carbon::parse('2026-03-31 12:00:00'),
        ]);
        $message = Message::factory()->create([
            'dialog_id' => null,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'created-chat',
            'text' => 'Новое входящее',
            'received_at' => Carbon::parse('2026-03-31 12:35:00'),
            'created_at' => Carbon::parse('2026-03-31 12:35:00'),
            'sent_by_type' => null,
        ]);

        DB::transaction(function () use ($message, $contact, $channel, $identity): void {
            app(SyncMessageDialogMetadataAction::class)->handle(
                $message,
                $contact,
                $channel,
                $identity,
                'created-chat',
                Message::SENT_BY_TYPE_CONTACT,
            );
        });

        $dialog->refresh();

        $this->assertSame('2026-03-31 12:35:00', $dialog->last_inbound_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 12:35:00', $dialog->last_message_at?->format('Y-m-d H:i:s'));
        $this->assertSame($message->id, $dialog->last_message_id);
        $this->assertSame($message->id, $dialog->last_inbound_message_id);
        $this->assertSame('Новое входящее', $dialog->last_message_preview);
        $this->assertSame('Новое входящее', $dialog->last_inbound_message_preview);
        $this->assertSame($identity->id, $dialog->current_contact_identity_id);
        $this->assertSame('created-chat', $dialog->external_chat_id);
    }

    public function test_outbound_sync_uses_received_at_for_message_chronology(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'operator-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'last_message_at' => Carbon::parse('2026-03-31 12:20:00'),
            'last_outbound_at' => Carbon::parse('2026-03-31 12:20:00'),
        ]);
        $employee = User::factory()->create();
        $message = Message::factory()->create([
            'dialog_id' => null,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'external_chat_id' => '500',
            'text' => 'Новый ответ',
            'received_at' => Carbon::parse('2026-03-31 12:25:00'),
            'created_at' => Carbon::parse('2026-03-31 12:25:00'),
            'sent_by_type' => null,
        ]);

        DB::transaction(function () use ($message, $contact, $channel, $identity, $employee): void {
            app(SyncMessageDialogMetadataAction::class)->handle(
                $message,
                $contact,
                $channel,
                $identity,
                '500',
                Message::SENT_BY_TYPE_OPERATOR,
                $employee->id,
            );
        });

        $dialog->refresh();

        $this->assertSame('2026-03-31 12:25:00', $dialog->last_outbound_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 12:25:00', $dialog->last_message_at?->format('Y-m-d H:i:s'));
        $this->assertSame($message->id, $dialog->last_message_id);
        $this->assertSame($message->id, $dialog->last_outbound_message_id);
        $this->assertSame('Новый ответ', $dialog->last_message_preview);
        $this->assertSame('Новый ответ', $dialog->last_outbound_message_preview);
    }

    public function test_older_message_sync_does_not_replace_last_message_snapshots(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $currentMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Свежий текст',
            'received_at' => Carbon::parse('2026-03-31 12:50:00'),
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'last_message_id' => $currentMessage->id,
            'last_inbound_message_id' => $currentMessage->id,
            'last_message_preview' => 'Свежий текст',
            'last_inbound_message_preview' => 'Свежий текст',
            'last_message_at' => Carbon::parse('2026-03-31 12:50:00'),
            'last_inbound_at' => Carbon::parse('2026-03-31 12:50:00'),
        ]);
        $olderMessage = Message::factory()->create([
            'dialog_id' => null,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Старый текст',
            'received_at' => Carbon::parse('2026-03-31 12:45:00'),
            'sent_by_type' => null,
        ]);

        DB::transaction(function () use ($olderMessage, $contact, $channel, $identity): void {
            app(SyncMessageDialogMetadataAction::class)->handle(
                $olderMessage,
                $contact,
                $channel,
                $identity,
                '500',
                Message::SENT_BY_TYPE_CONTACT,
            );
        });

        $dialog->refresh();

        $this->assertSame($currentMessage->id, $dialog->last_message_id);
        $this->assertSame($currentMessage->id, $dialog->last_inbound_message_id);
        $this->assertSame('Свежий текст', $dialog->last_message_preview);
        $this->assertSame('Свежий текст', $dialog->last_inbound_message_preview);
    }

    public function test_outbound_status_change_does_not_replace_visible_last_message_snapshot(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $visibleMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'text' => 'Ответ клиенту',
            'received_at' => Carbon::parse('2026-03-31 13:00:00'),
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'last_message_id' => $visibleMessage->id,
            'last_outbound_message_id' => $visibleMessage->id,
            'last_message_preview' => 'Ответ клиенту',
            'last_outbound_message_preview' => 'Ответ клиенту',
            'last_message_at' => Carbon::parse('2026-03-31 13:00:00'),
            'last_outbound_at' => Carbon::parse('2026-03-31 13:00:00'),
        ]);
        $statusMessage = Message::factory()->create([
            'dialog_id' => null,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE,
            'text' => 'Оператор изменил статус',
            'received_at' => Carbon::parse('2026-03-31 13:05:00'),
        ]);

        DB::transaction(function () use ($statusMessage, $contact, $channel, $identity): void {
            app(SyncMessageDialogMetadataAction::class)->handle(
                $statusMessage,
                $contact,
                $channel,
                $identity,
                '500',
                Message::SENT_BY_TYPE_SYSTEM,
                null,
                Message::SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE,
            );
        });

        $dialog->refresh();

        $this->assertSame($visibleMessage->id, $dialog->last_message_id);
        $this->assertSame($visibleMessage->id, $dialog->last_outbound_message_id);
        $this->assertSame('Ответ клиенту', $dialog->last_message_preview);
        $this->assertSame('Ответ клиенту', $dialog->last_outbound_message_preview);
        $this->assertSame('2026-03-31 13:05:00', $dialog->last_message_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 13:05:00', $dialog->last_outbound_at?->format('Y-m-d H:i:s'));
    }

    public function test_older_phone_capture_does_not_downgrade_confirmed_phone(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'phone-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
            'confirmed_phone_raw' => '+7 999 111 11 11',
            'confirmed_phone_normalized' => '+79991111111',
            'phone_confirmed_at' => Carbon::parse('2026-03-31 12:40:00'),
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => '300',
            'received_at' => Carbon::parse('2026-03-31 12:35:00'),
        ]);

        DB::transaction(function () use ($message): void {
            app(SyncDialogConfirmedPhoneAction::class)->handle(
                $message,
                '+7 999 222 22 22',
                '+79992222222',
            );
        });

        $dialog->refresh();

        $this->assertSame('+7 999 111 11 11', $dialog->confirmed_phone_raw);
        $this->assertSame('+79991111111', $dialog->confirmed_phone_normalized);
        $this->assertSame('2026-03-31 12:40:00', $dialog->phone_confirmed_at?->format('Y-m-d H:i:s'));
        $this->assertSame(Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE, $dialog->phone_confirmed_via);
    }
}
