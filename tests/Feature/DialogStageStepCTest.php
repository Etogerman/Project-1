<?php

namespace Tests\Feature;

use App\Filament\Resources\Dialogs\DialogResource;
use App\Filament\Resources\Dialogs\Pages\ListDialogs;
use App\Filament\Resources\Dialogs\Pages\ViewDialog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Dialogs\UpdateDialogStageAction;
use App\Services\Dialogs\LoadContactDialogsOverviewAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DialogStageStepCTest extends TestCase
{
    use RefreshDatabase;

    public function test_dialog_view_can_update_stage_and_write_history_note_without_touching_dialog_metadata(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Оператор Этапа',
        ]);
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $lastMessageAt = now()->subMinutes(10);
        $lastOutboundAt = now()->subMinutes(5);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'stage-chat-1',
            'stage' => Dialog::STAGE_PHONE_RECEIVED,
            'phone_confirmed_at' => $lastMessageAt,
            'last_message_at' => $lastMessageAt,
            'last_outbound_at' => $lastOutboundAt,
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Последнее реальное сообщение',
            'external_chat_id' => 'stage-chat-1',
            'received_at' => $lastMessageAt,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSet('dialogStageSelection', Dialog::STAGE_PHONE_RECEIVED)
            ->set('dialogStageSelection', Dialog::STAGE_TRANSFERRED_TO_MPL)
            ->call('updateDialogStage')
            ->assertNotified()
            ->assertSet('dialogStageSelection', Dialog::STAGE_TRANSFERRED_TO_MPL)
            ->assertSee('Оператор Оператор Этапа изменил этап диалога: Телефон получен -> МПЛ взял в работу');

        $historyMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE)
            ->where('sent_by_system_code', Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(Message::SENT_BY_TYPE_SYSTEM, $historyMessage->sent_by_type);
        $this->assertSame($admin->id, $historyMessage->sent_by_user_id);
        $this->assertSame(Message::DIRECTION_OUTBOUND, $historyMessage->direction);
        $this->assertSame($contact->id, $historyMessage->contact_id);
        $this->assertSame($channel->id, $historyMessage->channel_id);
        $this->assertSame($identity->id, $historyMessage->contact_identity_id);
        $this->assertSame('stage-chat-1', $historyMessage->external_chat_id);
        $this->assertSame('operator', $historyMessage->raw_payload['source_type']);
        $this->assertSame($admin->id, $historyMessage->raw_payload['changed_by_user_id']);
        $this->assertSame(Dialog::STAGE_PHONE_RECEIVED, $historyMessage->raw_payload['from_stage']);
        $this->assertSame(Dialog::STAGE_TRANSFERRED_TO_MPL, $historyMessage->raw_payload['to_stage']);
        $this->assertSame(
            $historyMessage->received_at?->toIso8601String(),
            $historyMessage->raw_payload['occurred_at'],
        );

        $dialog->refresh();

        $this->assertSame(Dialog::STAGE_TRANSFERRED_TO_MPL, $dialog->stage);
        $this->assertSame($lastMessageAt->format('Y-m-d H:i:s'), $dialog->last_message_at?->format('Y-m-d H:i:s'));
        $this->assertSame($lastOutboundAt->format('Y-m-d H:i:s'), $dialog->last_outbound_at?->format('Y-m-d H:i:s'));
    }

    public function test_dialog_view_uses_derived_automatic_stage_when_persisted_stage_is_null(): void
    {
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
            'external_chat_id' => 'derived-stage-chat',
            'stage' => null,
            'phone_confirmed_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSet('dialogStageSelection', Dialog::STAGE_PHONE_RECEIVED)
            ->assertSee('Телефон получен');
    }

    public function test_route_incomplete_dialog_blocks_manual_stage_change(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = Dialog::factory()->withoutCurrentIdentity()->create([
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'external_chat_id' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('Ручная смена этапа недоступна, пока не заполнен полный route context канала.')
            ->set('dialogStageSelection', Dialog::STAGE_TRANSFERRED_TO_MPL)
            ->call('updateDialogStage')
            ->assertNotified();

        $this->assertSame(Dialog::STAGE_NEW_DIALOG, $dialog->fresh()->stage);
        $this->assertDatabaseMissing('messages', [
            'dialog_id' => $dialog->id,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
        ]);
    }

    public function test_dialog_view_can_switch_between_manual_stages_and_write_operator_history(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Оператор Перевода',
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
            'external_chat_id' => 'stage-chat-manual-switch',
            'stage' => Dialog::STAGE_TRANSFERRED_TO_MPL,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSet('dialogStageSelection', Dialog::STAGE_TRANSFERRED_TO_MPL)
            ->set('dialogStageSelection', Dialog::STAGE_TRANSFERRED_TO_MPP)
            ->call('updateDialogStage')
            ->assertNotified()
            ->assertSet('dialogStageSelection', Dialog::STAGE_TRANSFERRED_TO_MPP)
            ->assertSee('Оператор Оператор Перевода изменил этап диалога: МПЛ взял в работу -> Передан в МПП');

        $historyMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE)
            ->where('sent_by_system_code', Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($contact->id, $historyMessage->contact_id);
        $this->assertSame($channel->id, $historyMessage->channel_id);
        $this->assertSame($identity->id, $historyMessage->contact_identity_id);
        $this->assertSame('stage-chat-manual-switch', $historyMessage->external_chat_id);
        $this->assertSame($admin->id, $historyMessage->sent_by_user_id);
        $this->assertSame('operator', $historyMessage->raw_payload['source_type']);
        $this->assertSame($admin->id, $historyMessage->raw_payload['changed_by_user_id']);
        $this->assertSame(Dialog::STAGE_TRANSFERRED_TO_MPL, $historyMessage->raw_payload['from_stage']);
        $this->assertSame(Dialog::STAGE_TRANSFERRED_TO_MPP, $historyMessage->raw_payload['to_stage']);
        $this->assertSame(Dialog::STAGE_TRANSFERRED_TO_MPP, $dialog->fresh()->stage);
    }

    public function test_update_dialog_stage_action_rejects_manual_transition_to_automatic_stage(): void
    {
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
            'external_chat_id' => 'stage-chat-invalid-transition',
            'stage' => Dialog::STAGE_TRANSFERRED_TO_MPL,
        ]);

        try {
            app(UpdateDialogStageAction::class)->handle(
                $dialog,
                $admin,
                Dialog::STAGE_PHONE_RECEIVED,
            );

            $this->fail('Expected manual to automatic transition to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Недопустимый ручной переход этапа.',
                $exception->errors()['dialogStageSelection'][0] ?? null,
            );
        }

        $this->assertSame(Dialog::STAGE_TRANSFERRED_TO_MPL, $dialog->fresh()->stage);
        $this->assertDatabaseMissing('messages', [
            'dialog_id' => $dialog->id,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
        ]);
    }

    public function test_update_dialog_stage_action_requires_dialog_edit_permission(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'dialogs.edit')
            ->update(['granted' => false]);

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
            'external_chat_id' => 'stage-chat-no-permission',
            'stage' => Dialog::STAGE_PHONE_RECEIVED,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Недостаточно прав для смены этапа диалога.');

        try {
            app(UpdateDialogStageAction::class)->handle(
                $dialog,
                $employee,
                Dialog::STAGE_TRANSFERRED_TO_MPL,
            );
        } finally {
            $this->assertSame(Dialog::STAGE_PHONE_RECEIVED, $dialog->fresh()->stage);
            $this->assertDatabaseMissing('messages', [
                'dialog_id' => $dialog->id,
                'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
            ]);
        }
    }

    public function test_dialogs_inbox_preview_ignores_dialog_stage_history_note(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialogWithStage(Dialog::STAGE_TRANSFERRED_TO_MPL, 'Последнее реальное сообщение');

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_user_id' => null,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Система изменила этап диалога: Телефон получен -> МПЛ взял в работу',
            'received_at' => now(),
            'raw_payload' => [
                'event' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
                'dialog_id' => $dialog->id,
                'from_stage' => Dialog::STAGE_PHONE_RECEIVED,
                'to_stage' => Dialog::STAGE_TRANSFERRED_TO_MPL,
                'source_type' => 'system',
                'changed_by_user_id' => null,
                'occurred_at' => now()->toIso8601String(),
            ],
        ]);

        $response = $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'));

        $response->assertOk()
            ->assertSee('Последнее реальное сообщение')
            ->assertDontSee('Система изменила этап диалога: Телефон получен -> МПЛ взял в работу');
    }

    public function test_contact_overview_preview_ignores_dialog_stage_history_note(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'overview-stage-chat',
            'stage' => Dialog::STAGE_TRANSFERRED_TO_MPP,
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Настоящее сообщение overview',
            'received_at' => now()->subMinute(),
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_user_id' => null,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
            'external_chat_id' => 'overview-stage-chat',
            'text' => 'Система изменила этап диалога',
            'received_at' => now(),
            'raw_payload' => [
                'event' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
                'dialog_id' => $dialog->id,
                'from_stage' => Dialog::STAGE_PHONE_RECEIVED,
                'to_stage' => Dialog::STAGE_TRANSFERRED_TO_MPP,
                'source_type' => 'system',
                'changed_by_user_id' => null,
                'occurred_at' => now()->toIso8601String(),
            ],
        ]);

        $overview = app(LoadContactDialogsOverviewAction::class)->handle($contact);

        $this->assertCount(1, $overview);
        $this->assertSame('Настоящее сообщение overview', $overview[0]['preview_text']);
    }

    public function test_dialogs_list_can_filter_by_stage(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $targetDialog = $this->createInboxDialogWithStage(Dialog::STAGE_TRANSFERRED_TO_MPL, 'Диалог МПЛ');
        $otherDialog = $this->createInboxDialogWithStage(Dialog::STAGE_TRANSFERRED_TO_MPP, 'Диалог МПП');

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->filterTable('stage', Dialog::STAGE_TRANSFERRED_TO_MPL)
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);
    }

    public function test_dialogs_list_can_filter_by_derived_automatic_stage_when_persisted_stage_is_null(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $completedDialog = $this->createInboxDialogWithStage(Dialog::STAGE_NEW_DIALOG, 'Диалог completed');
        $completedDialog->contact->forceFill([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => now(),
        ])->save();
        $completedDialog->forceFill([
            'stage' => null,
        ])->save();

        $otherDialog = $this->createInboxDialogWithStage(Dialog::STAGE_NEW_DIALOG, 'Диалог new');
        $otherDialog->forceFill([
            'stage' => null,
            'phone_confirmed_at' => null,
        ])->save();

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->filterTable('stage', Dialog::STAGE_QUESTIONNAIRE_COMPLETED)
            ->assertCanSeeTableRecords([$completedDialog->fresh()])
            ->assertCanNotSeeTableRecords([$otherDialog->fresh()]);
    }

    private function createInboxDialogWithStage(string $stage, string $messageText): Dialog
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $externalChatId = fake()->numerify('########');
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => $externalChatId,
            'stage' => $stage,
            'last_message_at' => now()->subMinute(),
            'last_inbound_at' => now()->subMinute(),
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => $messageText,
            'external_chat_id' => $externalChatId,
            'received_at' => now()->subMinute(),
        ]);

        return $dialog;
    }
}
