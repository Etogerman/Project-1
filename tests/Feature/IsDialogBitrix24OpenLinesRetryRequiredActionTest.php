<?php

namespace Tests\Feature;

use App\Models\Bitrix24MessageExport;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bitrix24\IsDialogBitrix24OpenLinesRetryRequiredAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IsDialogBitrix24OpenLinesRetryRequiredActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.features.openlines_enabled', true);
    }

    public function test_it_returns_true_when_dialog_has_ready_unresolved_missed_inbound(): void
    {
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncedContact(channel: $channel);
        $dialog = $this->makeDialog($contact, $channel);

        $this->makeMessage($dialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Экспортируемое missed inbound',
        ]);

        $required = app(IsDialogBitrix24OpenLinesRetryRequiredAction::class)->handle($dialog);

        $this->assertTrue($required);
    }

    public function test_it_returns_true_when_relevant_retry_is_already_pending_for_dialog(): void
    {
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncedContact(channel: $channel);
        $dialog = $this->makeDialog($contact, $channel);
        $message = $this->makeMessage($dialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Уже queued missed inbound',
        ]);

        Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $contact->id,
            'bitrix24_contact_id' => $contact->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
        ]);

        $required = app(IsDialogBitrix24OpenLinesRetryRequiredAction::class)->handle($dialog);

        $this->assertTrue($required);
    }

    public function test_it_returns_false_when_only_another_dialog_has_unresolved_retry(): void
    {
        $primaryChannel = $this->makeTelegramChannel();
        $contact = $this->createSyncedContact(channel: $primaryChannel);
        $targetDialog = $this->makeDialog($contact, $primaryChannel);

        $secondaryChannel = Channel::factory()->create([
            'name' => 'MAX Sales',
            'platform' => Channel::PLATFORM_MAX,
            'bot_username' => 'abrikosoff_max',
            'bot_name' => 'Abrikosoff MAX',
        ]);
        $this->attachChannelIdentity($contact, $secondaryChannel);

        $otherDialog = $this->makeDialog($contact, $secondaryChannel);
        $this->makeMessage($otherDialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Missed inbound в другом диалоге',
        ]);

        $required = app(IsDialogBitrix24OpenLinesRetryRequiredAction::class)->handle($targetDialog);

        $this->assertFalse($required);
    }

    public function test_it_returns_false_for_account_connection_type_even_when_contact_is_synced(): void
    {
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = $this->createSyncedContact(channel: $channel);
        $dialog = $this->makeDialog($contact, $channel);

        $this->makeMessage($dialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Account inbound should not be exported to Bitrix live bridge',
        ]);

        $required = app(IsDialogBitrix24OpenLinesRetryRequiredAction::class)->handle($dialog);

        $this->assertFalse($required);
    }

    public function test_it_skips_latest_non_exportable_message_and_detects_older_candidate_in_same_dialog(): void
    {
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncedContact(channel: $channel);
        $dialog = $this->makeDialog($contact, $channel);

        $this->makeMessage($dialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Старый валидный missed inbound',
            'received_at' => now()->subMinutes(2),
        ]);
        $this->makeMessage($dialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => '',
            'received_at' => now()->subMinute(),
        ]);

        $required = app(IsDialogBitrix24OpenLinesRetryRequiredAction::class)->handle($dialog);

        $this->assertTrue($required);
    }

    private function createSyncedContact(array $overrides = [], ?Channel $channel = null): Contact
    {
        $contact = Contact::factory()->create(array_merge([
            'first_name' => 'Герман',
            'last_name' => 'Абрикосов',
            'age_range' => '24_29',
            'country' => 'Россия',
            'city' => 'Москва',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
            'bitrix24_contact_id' => '501',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
            'bitrix24_linked_at' => now()->subDay(),
            'bitrix24_last_synced_at' => now()->subMinute(),
        ], $overrides));

        $channel ??= $this->makeTelegramChannel();

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => $channel->platform.'-user-'.$contact->id,
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        return $contact->fresh();
    }

    private function makeTelegramChannel(array $overrides = []): Channel
    {
        return Channel::factory()->create(array_merge([
            'name' => 'Telegram Sales',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'bot_username' => 'abrikosoff_tg',
            'bot_name' => 'Abrikosoff TG',
        ], $overrides));
    }

    private function makeDialog(Contact $contact, Channel $channel, array $overrides = []): Dialog
    {
        $identity = $contact->identities()
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        return Dialog::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => $channel->platform.'-chat-'.$contact->id,
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
        ], $overrides));
    }

    private function attachChannelIdentity(Contact $contact, Channel $channel): ContactIdentity
    {
        return ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => $channel->platform.'-user-'.$contact->id.'-'.$channel->id,
        ]);
    }

    private function makeMessage(Dialog $dialog, array $overrides = []): Message
    {
        $dialog->loadMissing(['contact', 'currentContactIdentity']);

        return Message::factory()->create(array_merge([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'external_chat_id' => $dialog->external_chat_id,
            'direction' => Message::DIRECTION_INBOUND,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_message_id' => (string) fake()->numerify('######'),
            'received_at' => now(),
        ], $overrides));
    }
}
