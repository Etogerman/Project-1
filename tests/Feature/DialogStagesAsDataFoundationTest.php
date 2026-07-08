<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\DialogStage;
use App\Models\FieldDictionaryField;
use App\Models\Message;
use App\Models\User;
use App\Services\Dialogs\ResolveOrCreateDialogAction;
use App\Services\Dialogs\UpdateDialogStageAction;
use App\Services\Scenarios\ScenarioEdgeExpressionCondition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DialogStagesAsDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_dialog_stages_drive_dialog_static_metadata(): void
    {
        $this->assertDatabaseCount('dialog_stages', 5);

        $this->assertSame([
            Dialog::STAGE_NEW_DIALOG,
            Dialog::STAGE_PHONE_RECEIVED,
            Dialog::STAGE_QUESTIONNAIRE_COMPLETED,
            Dialog::STAGE_TRANSFERRED_TO_MPL,
            Dialog::STAGE_TRANSFERRED_TO_MPP,
        ], Dialog::kanbanStages());

        DialogStage::factory()->create([
            'key' => 'operator_follow_up',
            'name' => 'Дожим оператором',
            'color' => 'warning',
            'sort_order' => 60,
            'system_role' => null,
            'is_seeded' => false,
        ]);

        $this->assertContains('operator_follow_up', Dialog::kanbanStages());
        $this->assertContains('operator_follow_up', Dialog::manualStages());
        $this->assertSame('Дожим оператором', Dialog::stageLabel('operator_follow_up'));
        $this->assertSame('warning', Dialog::stageTone('operator_follow_up'));
    }

    public function test_resolve_or_create_dialog_writes_stage_id_for_derived_stage(): void
    {
        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => now(),
        ]);
        $channel = Channel::factory()->create();

        $dialog = app(ResolveOrCreateDialogAction::class)->handle($contact, $channel)->refresh();
        $stage = DialogStage::query()->where('key', Dialog::STAGE_QUESTIONNAIRE_COMPLETED)->firstOrFail();

        $this->assertSame(Dialog::STAGE_QUESTIONNAIRE_COMPLETED, $dialog->stage);
        $this->assertSame($stage->id, $dialog->stage_id);
        $this->assertTrue($dialog->dialogStage()->is($stage));
    }

    public function test_manual_stage_update_writes_custom_stage_id_and_legacy_stage_key(): void
    {
        $customStage = DialogStage::factory()->create([
            'key' => 'operator_follow_up',
            'name' => 'Дожим оператором',
            'sort_order' => 60,
            'system_role' => null,
        ]);
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create();
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
            'external_chat_id' => 'custom-stage-chat',
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'stage_id' => DialogStage::query()->where('key', Dialog::STAGE_NEW_DIALOG)->value('id'),
        ]);

        app(UpdateDialogStageAction::class)->handle($dialog, $admin, 'operator_follow_up');

        $dialog->refresh();

        $this->assertSame('operator_follow_up', $dialog->stage);
        $this->assertSame($customStage->id, $dialog->stage_id);
        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
        ]);
    }

    public function test_dialog_stage_field_options_come_from_dialog_stages(): void
    {
        DialogStage::factory()->create([
            'key' => 'operator_follow_up',
            'name' => 'Дожим оператором',
            'sort_order' => 60,
            'system_role' => null,
        ]);

        FieldDictionaryField::syncSystemDefinitions();

        $this->assertSame(
            'Дожим оператором',
            FieldDictionaryField::optionLabelsFor(FieldDictionaryField::ENTITY_DIALOG, 'stage')['operator_follow_up'] ?? null,
        );

        $stageField = collect(FieldDictionaryField::constructorCatalog()['dialog'])
            ->first(fn (array $field): bool => $field['key'] === 'stage');

        $this->assertIsArray($stageField);
        $this->assertContains('operator_follow_up', collect($stageField['options'])->pluck('value')->all());
    }

    public function test_dialog_stage_expression_reads_effective_stage_from_stage_id(): void
    {
        $stage = DialogStage::query()->where('key', Dialog::STAGE_TRANSFERRED_TO_MPL)->firstOrFail();
        $channel = Channel::factory()->create();
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
            'stage' => null,
            'stage_id' => $stage->id,
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
        ]);

        $this->assertTrue(
            app(ScenarioEdgeExpressionCondition::class)->evaluate(
                '{{dialog.stage}} == "'.Dialog::STAGE_TRANSFERRED_TO_MPL.'"',
                $message->fresh(['dialog']),
            ),
        );
    }
}
