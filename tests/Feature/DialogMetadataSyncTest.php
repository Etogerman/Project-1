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
