<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Dialogs\ConsolidateDialogsForRootContactAction;
use App\Services\Dialogs\ResolveOrCreateDialogAction;
use App\Services\Dialogs\SyncDialogConfirmedPhoneAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DialogStageStepATest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_or_create_dialog_action_seeds_new_dialog_stage_without_history(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create();

        $dialog = app(ResolveOrCreateDialogAction::class)->handle($contact, $channel);

        $this->assertSame(Dialog::STAGE_NEW_DIALOG, $dialog->stage);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_resolve_or_create_dialog_action_seeds_completed_stage_for_completed_contact(): void
    {
        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => now(),
        ]);
        $channel = Channel::factory()->create();

        $dialog = app(ResolveOrCreateDialogAction::class)->handle($contact, $channel);

        $this->assertSame(Dialog::STAGE_QUESTIONNAIRE_COMPLETED, $dialog->stage);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_sync_dialog_confirmed_phone_action_promotes_stage_without_history(): void
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);

        $inboundMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'received_at' => now(),
        ]);

        $dialog = app(SyncDialogConfirmedPhoneAction::class)->handle(
            $inboundMessage,
            '+7 999 123 45 67',
            '+79991234567',
        );

        $this->assertSame(Dialog::STAGE_PHONE_RECEIVED, $dialog->stage);
        $this->assertSame('+7 999 123 45 67', $dialog->confirmed_phone_raw);
        $this->assertSame('+79991234567', $dialog->confirmed_phone_normalized);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_contact_completion_syncs_all_root_dialogs_including_route_incomplete_without_history(): void
    {
        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
        ]);
        $mergedContact = Contact::factory()->create([
            'merged_into_contact_id' => $contact->id,
            'merged_at' => now(),
        ]);
        $telegram = Channel::factory()->create(['platform' => Channel::PLATFORM_TELEGRAM]);
        $max = Channel::factory()->create(['platform' => Channel::PLATFORM_MAX]);
        $telegramIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegram->id,
            'platform' => $telegram->platform,
        ]);

        $routeCompleteDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegram->id,
            'current_contact_identity_id' => $telegramIdentity->id,
            'stage' => Dialog::STAGE_NEW_DIALOG,
        ]);
        $routeIncompleteDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $max->id,
            'current_contact_identity_id' => null,
            'external_chat_id' => null,
            'stage' => null,
        ]);
        $mergedDialog = Dialog::factory()->create([
            'contact_id' => $mergedContact->id,
            'channel_id' => Channel::factory()->create()->id,
            'current_contact_identity_id' => null,
            'external_chat_id' => null,
            'stage' => null,
        ]);

        $contact->completeDataCollection();

        $this->assertSame(Dialog::STAGE_QUESTIONNAIRE_COMPLETED, $routeCompleteDialog->fresh()->stage);
        $this->assertSame(Dialog::STAGE_QUESTIONNAIRE_COMPLETED, $routeIncompleteDialog->fresh()->stage);
        $this->assertSame(Dialog::STAGE_QUESTIONNAIRE_COMPLETED, $mergedDialog->fresh()->stage);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_contact_completion_does_not_override_manual_stage(): void
    {
        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
        ]);
        $channel = Channel::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);

        $dialog = Dialog::factory()->withoutCurrentIdentity()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'stage' => Dialog::STAGE_TRANSFERRED_TO_MPL,
        ]);

        $contact->completeDataCollection();

        $this->assertSame(Dialog::STAGE_TRANSFERRED_TO_MPL, $dialog->fresh()->stage);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_consolidation_recomputes_stage_from_confirmed_phone_without_history(): void
    {
        $rootContact = Contact::factory()->create();
        $mergedContact = Contact::factory()->create();
        $channel = Channel::factory()->create();
        $rootIdentity = ContactIdentity::factory()->create([
            'contact_id' => $rootContact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $mergedIdentity = ContactIdentity::factory()->create([
            'contact_id' => $mergedContact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);

        $survivingDialog = Dialog::factory()->create([
            'contact_id' => $rootContact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $rootIdentity->id,
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'external_chat_id' => 'chat-root',
        ]);
        $redundantDialog = Dialog::factory()->create([
            'contact_id' => $mergedContact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $mergedIdentity->id,
            'external_chat_id' => 'chat-merged',
            'stage' => null,
            'confirmed_phone_raw' => '+7 999 555 44 33',
            'confirmed_phone_normalized' => '+79995554433',
            'phone_confirmed_at' => now(),
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
        ]);

        app(ConsolidateDialogsForRootContactAction::class)->handle(
            $rootContact,
            [$rootContact->id, $mergedContact->id],
            true,
            false,
        );

        $this->assertSame(Dialog::STAGE_PHONE_RECEIVED, $survivingDialog->fresh()->stage);
        $this->assertSame('+7 999 555 44 33', $survivingDialog->fresh()->confirmed_phone_raw);
        $this->assertDatabaseMissing('dialogs', [
            'id' => $redundantDialog->id,
        ]);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_dialog_stage_backfill_command_supports_dry_run_and_apply(): void
    {
        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => now(),
        ]);
        $channel = Channel::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'stage' => null,
        ]);

        Artisan::call('dialogs:backfill-stage', ['--dry-run' => true]);
        $this->assertNull($dialog->fresh()->stage);

        Artisan::call('dialogs:backfill-stage', ['--apply' => true]);
        $this->assertSame(Dialog::STAGE_QUESTIONNAIRE_COMPLETED, $dialog->fresh()->stage);
        $this->assertDatabaseCount('messages', 0);
    }
}
