<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Contacts\AddContactPhoneAction;
use App\Services\Dialogs\ResolveOrCreateDialogAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DialogStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_or_create_dialog_sets_phone_received_stage_for_contact_with_existing_phone(): void
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 900 111 22 33',
            'phone_normalized' => '79001112233',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
            'is_primary' => true,
        ]);

        $dialog = app(ResolveOrCreateDialogAction::class)->handle($contact, $channel);

        $this->assertSame(Dialog::STAGE_PHONE_RECEIVED, $dialog->fresh()->stage_code);
    }

    public function test_resolve_or_create_dialog_sets_questionnaire_completed_stage_for_completed_contact(): void
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 900 111 22 33',
            'phone_normalized' => '79001112233',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
            'is_primary' => true,
        ]);

        $contact->completeDataCollection();

        $dialog = app(ResolveOrCreateDialogAction::class)->handle($contact, $channel);

        $this->assertSame(Dialog::STAGE_QUESTIONNAIRE_COMPLETED, $dialog->fresh()->stage_code);
    }

    public function test_add_contact_phone_promotes_existing_dialog_and_writes_stage_history_note(): void
    {
        [$contact, $dialog] = $this->createDialogWithoutPhone();

        app(AddContactPhoneAction::class)->handle(
            $contact,
            '+7 999 123 45 67',
            ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
        );

        $this->assertSame(Dialog::STAGE_PHONE_RECEIVED, $dialog->fresh()->stage_code);

        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STAGE_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_user_id' => null,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
            'text' => 'Система изменила стадию диалога: Новый диалог -> Телефон получен',
        ]);
    }

    public function test_contact_complete_data_collection_promotes_existing_dialog_and_writes_stage_history_note(): void
    {
        [$contact, $dialog] = $this->createDialogWithoutPhone();

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '79991234567',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
            'is_primary' => true,
        ]);

        app(AddContactPhoneAction::class)->handle(
            $contact,
            '+7 999 123 45 67',
            ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
        );

        $contact->fresh()->completeDataCollection();

        $this->assertSame(Dialog::STAGE_QUESTIONNAIRE_COMPLETED, $dialog->fresh()->stage_code);

        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STAGE_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_user_id' => null,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
            'text' => 'Система изменила стадию диалога: Телефон получен -> Анкета заполнена',
        ]);
    }

    public function test_start_data_collection_demotes_completed_stage_back_to_phone_received(): void
    {
        [$contact, $dialog] = $this->createDialogWithoutPhone();

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '79991234567',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
            'is_primary' => true,
        ]);

        app(AddContactPhoneAction::class)->handle(
            $contact,
            '+7 999 123 45 67',
            ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
        );

        $contact->fresh()->completeDataCollection();
        $contact->fresh()->startDataCollection(Contact::DATA_COLLECTION_FIELD_AGE_RANGE);

        $this->assertSame(Dialog::STAGE_PHONE_RECEIVED, $dialog->fresh()->stage_code);

        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STAGE_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_user_id' => null,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
            'text' => 'Система изменила стадию диалога: Анкета заполнена -> Телефон получен',
        ]);
    }

    /**
     * @return array{Contact, Dialog}
     */
    protected function createDialogWithoutPhone(): array
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
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'stage_code' => Dialog::STAGE_NEW_DIALOG,
            'external_chat_id' => 'dialog-stage-chat-001',
        ]);

        return [$contact, $dialog];
    }
}
