<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackfillDialogsCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dry_run_does_not_write_dialogs_or_message_fields(): void
    {
        $identity = ContactIdentity::factory()->create();

        $message = Message::factory()->create([
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'sent_by_type' => null,
            'dialog_id' => null,
        ]);

        Artisan::call('dialogs:backfill', ['--dry-run' => true]);

        $this->assertDatabaseCount('dialogs', 0);
        $this->assertNull($message->fresh()->dialog_id);
        $this->assertNull($message->fresh()->sent_by_type);
    }

    public function test_apply_creates_one_dialog_per_root_contact_and_channel(): void
    {
        $identity = ContactIdentity::factory()->create();

        Message::factory()->create([
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
            'external_chat_id' => 'chat-old',
            'received_at' => now()->subMinutes(10),
        ]);

        $latestInbound = Message::factory()->create([
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
            'external_chat_id' => 'chat-new',
            'text' => 'Последнее входящее',
            'received_at' => now(),
        ]);

        Artisan::call('dialogs:backfill', ['--apply' => true]);

        $dialog = Dialog::query()->where('contact_id', $identity->contact_id)->where('channel_id', $identity->channel_id)->first();

        $this->assertNotNull($dialog);
        $this->assertSame($identity->id, $dialog->current_contact_identity_id);
        $this->assertSame('chat-new', $dialog->external_chat_id);
        $this->assertSame($latestInbound->received_at?->format('Y-m-d H:i:s'), $dialog->last_message_at?->format('Y-m-d H:i:s'));
        $this->assertSame($latestInbound->received_at?->format('Y-m-d H:i:s'), $dialog->last_inbound_at?->format('Y-m-d H:i:s'));
        $this->assertSame($latestInbound->id, $dialog->last_message_id);
        $this->assertSame($latestInbound->id, $dialog->last_inbound_message_id);
        $this->assertSame('Последнее входящее', $dialog->last_message_preview);
        $this->assertSame('Последнее входящее', $dialog->last_inbound_message_preview);

        $this->assertSame(1, Dialog::query()
            ->where('contact_id', $identity->contact_id)
            ->where('channel_id', $identity->channel_id)
            ->count());
    }

    public function test_apply_uses_received_at_for_dialog_chronology_and_route_source(): void
    {
        $identity = ContactIdentity::factory()->create();

        Message::factory()->create([
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
            'external_chat_id' => 'chat-old',
            'received_at' => now()->subHour(),
            'created_at' => now()->subHours(2),
        ]);

        $fallbackChronologyMessage = Message::factory()->create([
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
            'external_chat_id' => 'chat-created',
            'received_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
        ]);

        Artisan::call('dialogs:backfill', ['--apply' => true]);

        $dialog = Dialog::query()->where('contact_id', $identity->contact_id)->where('channel_id', $identity->channel_id)->firstOrFail();

        $this->assertSame('chat-created', $dialog->external_chat_id);
        $this->assertSame(
            $fallbackChronologyMessage->received_at?->format('Y-m-d H:i:s'),
            $dialog->last_message_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            $fallbackChronologyMessage->received_at?->format('Y-m-d H:i:s'),
            $dialog->last_inbound_at?->format('Y-m-d H:i:s'),
        );
    }

    public function test_apply_creates_separate_dialogs_for_different_channels(): void
    {
        $contact = Contact::factory()->create();
        $telegram = Channel::factory()->create(['platform' => Channel::PLATFORM_TELEGRAM]);
        $max = Channel::factory()->create(['platform' => Channel::PLATFORM_MAX]);

        $telegramIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegram->id,
            'platform' => $telegram->platform,
        ]);
        $maxIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $max->id,
            'platform' => $max->platform,
        ]);

        Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $telegramIdentity->id,
            'channel_id' => $telegram->id,
        ]);

        Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $maxIdentity->id,
            'channel_id' => $max->id,
        ]);

        Artisan::call('dialogs:backfill', ['--apply' => true]);

        $this->assertSame(2, Dialog::query()->where('contact_id', $contact->id)->count());
    }

    public function test_apply_uses_root_contact_for_merged_data(): void
    {
        $root = Contact::factory()->create();
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $channel = Channel::factory()->create();

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $merged->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);

        $message = Message::factory()->create([
            'contact_id' => $merged->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
        ]);

        Artisan::call('dialogs:backfill', ['--apply' => true]);

        $dialog = Dialog::query()->where('contact_id', $root->id)->where('channel_id', $channel->id)->first();

        $this->assertNotNull($dialog);
        $this->assertSame($dialog->id, $message->fresh()->dialog_id);
    }

    public function test_apply_falls_back_to_identity_when_no_inbound_exists(): void
    {
        $identity = ContactIdentity::factory()->create();

        Artisan::call('dialogs:backfill', ['--apply' => true]);

        $dialog = Dialog::query()->where('contact_id', $identity->contact_id)->where('channel_id', $identity->channel_id)->first();

        $this->assertNotNull($dialog);
        $this->assertSame($identity->id, $dialog->current_contact_identity_id);
        $this->assertNull($dialog->last_message_at);
        $this->assertNull($dialog->last_inbound_at);
        $this->assertNull($dialog->last_outbound_at);
    }

    public function test_apply_clears_stale_max_chat_id_when_freshest_inbound_is_user_based(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-user',
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'stale-max-chat',
        ]);

        Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'old-chat',
            'received_at' => now()->subMinutes(10),
        ]);

        $freshestInbound = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => '',
            'received_at' => now(),
        ]);

        Artisan::call('dialogs:backfill', ['--apply' => true]);

        $dialog->refresh();

        $this->assertSame($identity->id, $dialog->current_contact_identity_id);
        $this->assertNull($dialog->external_chat_id);
        $this->assertSame(
            $freshestInbound->received_at?->format('Y-m-d H:i:s'),
            $dialog->last_inbound_at?->format('Y-m-d H:i:s'),
        );
    }

    public function test_apply_identity_only_dialog_does_not_keep_stale_max_chat_id(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'identity-only-user',
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'legacy-stale-chat',
        ]);

        Artisan::call('dialogs:backfill', ['--apply' => true]);

        $dialog->refresh();

        $this->assertSame($identity->id, $dialog->current_contact_identity_id);
        $this->assertNull($dialog->external_chat_id);
        $this->assertNull($dialog->last_message_at);
        $this->assertNull($dialog->last_inbound_at);
        $this->assertNull($dialog->last_outbound_at);
    }

    public function test_apply_backfills_dialog_id_and_sender_type(): void
    {
        $identity = ContactIdentity::factory()->create();

        $inbound = Message::factory()->create([
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => null,
        ]);

        $manual = Message::factory()->create([
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => null,
        ]);

        $autoReply = Message::factory()->create([
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'sent_by_type' => null,
        ]);

        Artisan::call('dialogs:backfill', ['--apply' => true]);

        $dialog = Dialog::query()->where('contact_id', $identity->contact_id)->where('channel_id', $identity->channel_id)->firstOrFail();

        $this->assertSame($dialog->id, $inbound->fresh()->dialog_id);
        $this->assertSame(Message::SENT_BY_TYPE_CONTACT, $inbound->fresh()->sent_by_type);
        $this->assertSame(Message::SENT_BY_TYPE_OPERATOR, $manual->fresh()->sent_by_type);
        $this->assertNull($manual->fresh()->sent_by_user_id);
        $this->assertSame(Message::SENT_BY_TYPE_AUTO_REPLY, $autoReply->fresh()->sent_by_type);
        $this->assertSame('auto_reply_legacy', $autoReply->fresh()->sent_by_system_code);
    }

    public function test_apply_relinks_message_from_unexpected_dialog(): void
    {
        $identity = ContactIdentity::factory()->create();
        $otherIdentity = ContactIdentity::factory()->create();

        $unexpectedDialog = Dialog::factory()->create([
            'contact_id' => $otherIdentity->contact_id,
            'channel_id' => $otherIdentity->channel_id,
            'current_contact_identity_id' => $otherIdentity->id,
        ]);

        $message = Message::factory()->create([
            'dialog_id' => $unexpectedDialog->id,
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
        ]);

        Artisan::call('dialogs:backfill', ['--apply' => true]);

        $expectedDialog = Dialog::query()
            ->where('contact_id', $identity->contact_id)
            ->where('channel_id', $identity->channel_id)
            ->firstOrFail();

        $this->assertSame($expectedDialog->id, $message->fresh()->dialog_id);
        $this->assertNotSame($unexpectedDialog->id, $message->fresh()->dialog_id);
    }

    public function test_apply_maps_unknown_outbound_kind_to_system_sender(): void
    {
        $identity = ContactIdentity::factory()->create();

        $message = Message::factory()->create([
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => 'legacy_unknown_outbound',
            'sent_by_type' => null,
            'sent_by_system_code' => null,
        ]);

        Artisan::call('dialogs:backfill', ['--apply' => true]);

        $this->assertSame(Message::SENT_BY_TYPE_SYSTEM, $message->fresh()->sent_by_type);
        $this->assertSame('legacy_unknown_kind', $message->fresh()->sent_by_system_code);
    }

    public function test_rerun_is_idempotent_and_does_not_fill_confirmed_phone_fields(): void
    {
        $identity = ContactIdentity::factory()->create();

        Message::factory()->create([
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
        ]);

        Artisan::call('dialogs:backfill', ['--apply' => true]);
        Artisan::call('dialogs:backfill', ['--apply' => true]);

        $dialog = Dialog::query()->where('contact_id', $identity->contact_id)->where('channel_id', $identity->channel_id)->firstOrFail();

        $this->assertSame(1, Dialog::query()
            ->where('contact_id', $identity->contact_id)
            ->where('channel_id', $identity->channel_id)
            ->count());
        $this->assertNull($dialog->confirmed_phone_raw);
        $this->assertNull($dialog->confirmed_phone_normalized);
        $this->assertNull($dialog->phone_confirmed_at);
        $this->assertNull($dialog->phone_confirmed_via);
    }
}
