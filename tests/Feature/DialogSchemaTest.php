<?php

namespace Tests\Feature;

use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DialogSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dialogs_table_and_message_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('dialogs'));
        $this->assertTrue(Schema::hasColumns('dialogs', [
            'contact_id',
            'channel_id',
            'stage',
            'current_contact_identity_id',
            'manual_reply_dismissed_source_message_id',
            'external_chat_id',
            'confirmed_phone_raw',
            'confirmed_phone_normalized',
            'phone_confirmed_at',
            'phone_confirmed_via',
            'fields_payload',
            'last_message_at',
            'last_inbound_at',
            'last_outbound_at',
        ]));

        $this->assertTrue(Schema::hasColumns('messages', [
            'dialog_id',
            'sent_by_type',
            'sent_by_user_id',
            'sent_by_system_code',
        ]));
    }

    public function test_dialog_is_unique_per_contact_and_channel(): void
    {
        $identity = ContactIdentity::factory()->create();

        Dialog::factory()->create([
            'contact_id' => $identity->contact_id,
            'channel_id' => $identity->channel_id,
            'current_contact_identity_id' => $identity->id,
        ]);

        $this->expectException(QueryException::class);

        Dialog::factory()->create([
            'contact_id' => $identity->contact_id,
            'channel_id' => $identity->channel_id,
            'current_contact_identity_id' => $identity->id,
        ]);
    }

    public function test_dialog_relations_are_wired(): void
    {
        $identity = ContactIdentity::factory()->create();

        $dialog = Dialog::factory()->create([
            'contact_id' => $identity->contact_id,
            'channel_id' => $identity->channel_id,
            'current_contact_identity_id' => $identity->id,
        ]);

        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
        ]);

        $this->assertTrue($dialog->contact->is($identity->contact));
        $this->assertTrue($dialog->channel->is($identity->channel));
        $this->assertTrue($dialog->currentContactIdentity->is($identity));
        $this->assertTrue($dialog->messages->contains($message));

        $this->assertTrue($identity->contact->dialogs->contains($dialog));
        $this->assertTrue($identity->channel->dialogs->contains($dialog));
        $this->assertTrue($identity->currentDialogs->contains($dialog));
        $this->assertTrue($message->dialog->is($dialog));
    }

    public function test_message_can_still_be_created_without_dialog_or_sender_metadata(): void
    {
        $message = Message::factory()->create();

        $this->assertNull($message->dialog_id);
        $this->assertNull($message->sent_by_type);
        $this->assertNull($message->sent_by_user_id);
        $this->assertNull($message->sent_by_system_code);

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'dialog_id' => null,
            'sent_by_type' => null,
            'sent_by_user_id' => null,
            'sent_by_system_code' => null,
        ]);
    }

    public function test_message_sent_by_type_constants_are_exposed(): void
    {
        $this->assertSame('contact', Message::SENT_BY_TYPE_CONTACT);
        $this->assertSame('operator', Message::SENT_BY_TYPE_OPERATOR);
        $this->assertSame('auto_reply', Message::SENT_BY_TYPE_AUTO_REPLY);
        $this->assertSame('collector', Message::SENT_BY_TYPE_COLLECTOR);
        $this->assertSame('system', Message::SENT_BY_TYPE_SYSTEM);
    }
}
