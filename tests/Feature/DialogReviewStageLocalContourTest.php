<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Dialogs\SyncDialogConfirmedPhoneAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DialogReviewStageLocalContourTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_review_stage_command_moves_existing_dialogs_and_writes_history_from_effective_stage(): void
    {
        $dialog = $this->createRouteCompleteDialog([
            'stage' => null,
            'phone_confirmed_at' => now(),
            'external_chat_id' => 'review-command-chat',
        ]);

        Artisan::call('dialogs:assign-review-stage', ['--apply' => true]);

        $dialog->refresh();

        $this->assertSame(Dialog::STAGE_REQUIRES_REVIEW, $dialog->stage);

        $historyMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE)
            ->where('sent_by_system_code', Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(Dialog::STAGE_PHONE_RECEIVED, $historyMessage->raw_payload['from_stage']);
        $this->assertSame(Dialog::STAGE_REQUIRES_REVIEW, $historyMessage->raw_payload['to_stage']);
        $this->assertSame('system', $historyMessage->raw_payload['source_type']);
    }

    public function test_backfill_stage_command_does_not_escape_review_stage_without_live_event(): void
    {
        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => now(),
        ]);
        $dialog = $this->createRouteCompleteDialog([
            'contact_id' => $contact->id,
            'stage' => Dialog::STAGE_REQUIRES_REVIEW,
        ], $contact);

        Artisan::call('dialogs:backfill-stage', ['--apply' => true]);

        $this->assertSame(Dialog::STAGE_REQUIRES_REVIEW, $dialog->fresh()->stage);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_phone_confirmation_can_escape_review_stage_after_live_event(): void
    {
        $dialog = $this->createRouteCompleteDialog([
            'stage' => Dialog::STAGE_REQUIRES_REVIEW,
            'external_chat_id' => 'review-phone-chat',
        ]);

        $inboundMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'review-phone-chat',
            'received_at' => now(),
        ]);

        $updatedDialog = app(SyncDialogConfirmedPhoneAction::class)->handle(
            $inboundMessage,
            '+7 999 123 45 67',
            '+79991234567',
        );

        $this->assertSame(Dialog::STAGE_PHONE_RECEIVED, $updatedDialog->stage);

        $historyMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE)
            ->where('sent_by_system_code', Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(Dialog::STAGE_REQUIRES_REVIEW, $historyMessage->raw_payload['from_stage']);
        $this->assertSame(Dialog::STAGE_PHONE_RECEIVED, $historyMessage->raw_payload['to_stage']);
    }

    public function test_contact_completion_can_escape_review_stage_after_live_event(): void
    {
        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
        ]);
        $dialog = $this->createRouteCompleteDialog([
            'contact_id' => $contact->id,
            'stage' => Dialog::STAGE_REQUIRES_REVIEW,
            'external_chat_id' => 'review-completion-chat',
        ], $contact);

        $contact->completeDataCollection();

        $this->assertSame(Dialog::STAGE_QUESTIONNAIRE_COMPLETED, $dialog->fresh()->stage);

        $historyMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE)
            ->where('sent_by_system_code', Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(Dialog::STAGE_REQUIRES_REVIEW, $historyMessage->raw_payload['from_stage']);
        $this->assertSame(Dialog::STAGE_QUESTIONNAIRE_COMPLETED, $historyMessage->raw_payload['to_stage']);
    }

    private function createRouteCompleteDialog(array $dialogOverrides = [], ?Contact $contact = null): Dialog
    {
        $contact ??= Contact::factory()->create();
        $channel = Channel::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);

        return Dialog::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'review-stage-chat',
        ], $dialogOverrides));
    }
}
