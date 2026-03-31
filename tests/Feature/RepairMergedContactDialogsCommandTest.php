<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RepairMergedContactDialogsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_dialog_repairs(): void
    {
        $channel = Channel::factory()->create();
        $root = Contact::factory()->create();
        $secondary = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        $rootIdentity = ContactIdentity::factory()->create([
            'contact_id' => $root->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'root-user',
        ]);
        $secondaryIdentity = ContactIdentity::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'secondary-user',
        ]);

        $rootDialog = Dialog::factory()->create([
            'contact_id' => $root->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $rootIdentity->id,
            'external_chat_id' => 'root-chat',
        ]);
        $secondaryDialog = Dialog::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $secondaryIdentity->id,
            'external_chat_id' => 'secondary-chat',
        ]);

        $secondaryMessage = Message::factory()->create([
            'dialog_id' => $secondaryDialog->id,
            'contact_id' => $secondary->id,
            'contact_identity_id' => $secondaryIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'secondary-chat',
        ]);

        Artisan::call('dialogs:repair-merged-contacts', ['--dry-run' => true]);

        $this->assertDatabaseHas('dialogs', [
            'id' => $rootDialog->id,
            'contact_id' => $root->id,
        ]);
        $this->assertDatabaseHas('dialogs', [
            'id' => $secondaryDialog->id,
            'contact_id' => $secondary->id,
        ]);
        $this->assertSame($secondary->id, $secondaryMessage->fresh()->contact_id);
        $this->assertSame($secondaryDialog->id, $secondaryMessage->fresh()->dialog_id);
        $this->assertSame(2, Dialog::query()->count());
    }

    public function test_apply_repairs_historical_merged_dialogs(): void
    {
        $channel = Channel::factory()->create();
        $root = Contact::factory()->create();
        $secondary = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        $rootIdentity = ContactIdentity::factory()->create([
            'contact_id' => $root->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'root-user',
        ]);
        $secondaryIdentity = ContactIdentity::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'secondary-user',
        ]);

        $rootDialog = Dialog::factory()->create([
            'contact_id' => $root->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $rootIdentity->id,
            'external_chat_id' => 'root-chat',
            'confirmed_phone_raw' => '+7 999 111 11 11',
            'confirmed_phone_normalized' => '+79991111111',
            'phone_confirmed_at' => now()->subDay(),
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
            'last_message_at' => now()->subDay(),
            'last_inbound_at' => now()->subDay(),
        ]);
        $secondaryDialog = Dialog::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $secondaryIdentity->id,
            'external_chat_id' => 'secondary-chat',
            'confirmed_phone_raw' => '+7 999 222 22 22',
            'confirmed_phone_normalized' => '+79992222222',
            'phone_confirmed_at' => now()->subHour(),
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
            'last_message_at' => now()->subHour(),
            'last_inbound_at' => now()->subHour(),
        ]);

        Message::factory()->create([
            'dialog_id' => $rootDialog->id,
            'contact_id' => $root->id,
            'contact_identity_id' => $rootIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'root-chat',
            'received_at' => now()->subDay(),
        ]);
        $secondaryInbound = Message::factory()->create([
            'dialog_id' => $secondaryDialog->id,
            'contact_id' => $secondary->id,
            'contact_identity_id' => $secondaryIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'secondary-chat',
            'received_at' => now()->subHour(),
        ]);
        $secondaryMessageWithoutDialog = Message::factory()->create([
            'dialog_id' => null,
            'contact_id' => $secondary->id,
            'contact_identity_id' => $secondaryIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'received_at' => now()->subMinutes(30),
        ]);

        Artisan::call('dialogs:repair-merged-contacts', ['--apply' => true]);

        $rootDialog->refresh();
        $secondaryInbound->refresh();
        $secondaryMessageWithoutDialog->refresh();

        $this->assertDatabaseMissing('dialogs', [
            'id' => $secondaryDialog->id,
        ]);
        $this->assertSame(1, Dialog::query()->where('contact_id', $root->id)->count());
        $this->assertSame(0, Dialog::query()->where('contact_id', $secondary->id)->count());
        $this->assertSame($root->id, $secondaryInbound->contact_id);
        $this->assertSame($rootDialog->id, $secondaryInbound->dialog_id);
        $this->assertSame($root->id, $secondaryMessageWithoutDialog->contact_id);
        $this->assertSame($rootDialog->id, $secondaryMessageWithoutDialog->dialog_id);
        $this->assertSame($secondaryIdentity->id, $rootDialog->current_contact_identity_id);
        $this->assertSame('secondary-chat', $rootDialog->external_chat_id);
        $this->assertSame('+7 999 222 22 22', $rootDialog->confirmed_phone_raw);
        $this->assertSame('+79992222222', $rootDialog->confirmed_phone_normalized);
    }

    public function test_apply_clears_stale_max_chat_id_when_freshest_merged_route_source_is_user_based(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $root = Contact::factory()->create();
        $secondary = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        $rootIdentity = ContactIdentity::factory()->create([
            'contact_id' => $root->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'root-user',
        ]);
        $secondaryIdentity = ContactIdentity::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'fresh-user',
        ]);

        $rootDialog = Dialog::factory()->create([
            'contact_id' => $root->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $rootIdentity->id,
            'external_chat_id' => 'stale-root-chat',
            'last_message_at' => now()->subDay(),
            'last_inbound_at' => now()->subDay(),
        ]);
        $secondaryDialog = Dialog::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $secondaryIdentity->id,
            'external_chat_id' => 'stale-secondary-chat',
            'last_message_at' => now()->subHour(),
            'last_inbound_at' => now()->subHour(),
        ]);

        Message::factory()->create([
            'dialog_id' => $rootDialog->id,
            'contact_id' => $root->id,
            'contact_identity_id' => $rootIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'old-chat',
            'received_at' => now()->subDay(),
        ]);
        Message::factory()->create([
            'dialog_id' => $secondaryDialog->id,
            'contact_id' => $secondary->id,
            'contact_identity_id' => $secondaryIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => '',
            'received_at' => now()->subMinutes(5),
        ]);

        Artisan::call('dialogs:repair-merged-contacts', ['--apply' => true]);

        $rootDialog->refresh();

        $this->assertDatabaseMissing('dialogs', [
            'id' => $secondaryDialog->id,
        ]);
        $this->assertSame($secondaryIdentity->id, $rootDialog->current_contact_identity_id);
        $this->assertNull($rootDialog->external_chat_id);
    }
}
