<?php

namespace Tests\Feature;

use App\Filament\Resources\Dialogs\DialogResource;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Dialogs\BuildDialogNotificationStateAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminDialogNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_admin_topbar_renders_dialog_notifications_bootstrap(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $response = $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'));

        $response
            ->assertOk()
            ->assertSee('data-ac-dialog-notifications', false)
            ->assertSee(route('admin.dialog-notifications.show'), false)
            ->assertSee('Включить звук')
            ->assertSee('data-ac-notifications-sound-choice', false)
            ->assertSee('01 Клик')
            ->assertSee('07_digital_blip', false)
            ->assertSee('30 Ясное уведомление')
            ->assertSee('30_clear_notify.wav', false)
            ->assertSee('Тихий')
            ->assertSee('Средний')
            ->assertSee('Громкий')
            ->assertSee('const pollIntervalMs = 3000', false)
            ->assertSee('const pollLeaseMs = 5000', false)
            ->assertSee('ab.dialogNotifications.lastSoundMessage.v1', false)
            ->assertSee('window.AudioContext || window.webkitAudioContext', false)
            ->assertSee('latestNotificationMessageId > lastSoundMessageId', false)
            ->assertSee('const claimSoundAttemptForMessage', false)
            ->assertSee('claimedAt: Date.now()', false)
            ->assertSee('await playSoundForMessage(messageId)', false)
            ->assertSee('Звук заблокирован. Нажмите', false)
            ->assertSee('renderCachedState({ allowSound: true })', false);

        $this->assertSame(30, substr_count($response->getContent(), 'data-ac-notifications-sound-option'));
    }

    public function test_initial_state_can_set_baseline_without_returning_old_messages(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithInboundMessage('Старое сообщение');

        $this->actingAs($admin)
            ->getJson(route('admin.dialog-notifications.show', ['initialize' => 1]))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('last_read_message_id', $dialog->last_inbound_message_id);

        $newMessage = $this->addInboundMessage($dialog, 'Новое сообщение');

        $this->actingAs($admin)
            ->getJson(route('admin.dialog-notifications.show'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.message_id', $newMessage->id)
            ->assertJsonPath('items.0.text', 'Новое сообщение');
    }

    public function test_dialog_notifications_filter_by_user_scope(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $otherEmployee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->createDialogWithInboundMessage('Мой диалог', assignedUserId: $employee->id);
        $this->createDialogWithInboundMessage('Свободный диалог');
        $this->createDialogWithInboundMessage('Чужой диалог', assignedUserId: $otherEmployee->id);

        $this->actingAs($employee)
            ->getJson(route('admin.dialog-notifications.show'))
            ->assertOk()
            ->assertJsonPath('scope', BuildDialogNotificationStateAction::SCOPE_MINE_UNASSIGNED)
            ->assertJsonPath('count', 2);

        $this->actingAs($employee)
            ->patchJson(route('admin.dialog-notifications.preferences.update'), [
                'scope' => BuildDialogNotificationStateAction::SCOPE_MINE,
            ])
            ->assertOk()
            ->assertJsonPath('scope', BuildDialogNotificationStateAction::SCOPE_MINE)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.text', 'Мой диалог');

        $this->actingAs($employee)
            ->patchJson(route('admin.dialog-notifications.preferences.update'), [
                'scope' => BuildDialogNotificationStateAction::SCOPE_ALL,
            ])
            ->assertOk()
            ->assertJsonPath('scope', BuildDialogNotificationStateAction::SCOPE_ALL)
            ->assertJsonPath('count', 3);
    }

    public function test_dialog_notifications_ignore_outbound_and_system_messages(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialog();

        $this->addMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'text' => 'Исходящее',
        ]);
        $this->addMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_SYSTEM_EVENT,
            'text' => 'Системное',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dialog-notifications.show'))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('items', []);
    }

    public function test_dialog_notifications_mark_read_updates_user_boundary(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $firstDialog = $this->createDialogWithInboundMessage('Первый диалог');
        $secondDialog = $this->createDialogWithInboundMessage('Второй диалог');

        $this->actingAs($admin)
            ->getJson(route('admin.dialog-notifications.show'))
            ->assertOk()
            ->assertJsonPath('count', 2);

        $this->actingAs($admin)
            ->postJson(route('admin.dialog-notifications.mark-read'))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('last_read_message_id', $secondDialog->last_inbound_message_id);

        $thirdMessage = $this->addInboundMessage($firstDialog, 'Новый входящий после прочтения');

        $this->actingAs($admin)
            ->getJson(route('admin.dialog-notifications.show'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.message_id', $thirdMessage->id);
    }

    public function test_dialog_notifications_apply_scope_before_limiting_candidates(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $otherEmployee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $ownDialog = $this->createDialogWithInboundMessage('Моё старое важное сообщение', assignedUserId: $employee->id);

        for ($i = 1; $i <= 251; $i++) {
            $this->createDialogWithInboundMessage('Чужое сообщение '.$i, assignedUserId: $otherEmployee->id);
        }

        $this->actingAs($employee)
            ->getJson(route('admin.dialog-notifications.show'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.dialog_id', $ownDialog->id)
            ->assertJsonPath('items.0.text', 'Моё старое важное сообщение');
    }

    public function test_dialog_notifications_count_all_relevant_dialogs_while_limiting_items(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        for ($i = 1; $i <= 12; $i++) {
            $this->createDialogWithInboundMessage('Диалог '.$i);
        }

        $this->actingAs($admin)
            ->getJson(route('admin.dialog-notifications.show'))
            ->assertOk()
            ->assertJsonPath('count', 12)
            ->assertJsonCount(10, 'items');
    }

    public function test_dialog_notifications_reject_arbitrary_mark_read_message_id(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $message = $this->addInboundMessage(
            $this->createDialog(),
            'Реальное уведомление',
        );
        $answeredDialog = $this->createDialog();
        $answeredMessage = $this->addInboundMessage($answeredDialog, 'Уже отвеченное сообщение');

        $this->addMessage($answeredDialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'text' => 'Ответ оператора',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.dialog-notifications.mark-read'), [
                'message_id' => $message->id + 100000,
            ])
            ->assertOk()
            ->assertJsonPath('last_read_message_id', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.message_id', $message->id);

        $this->actingAs($admin)
            ->postJson(route('admin.dialog-notifications.mark-read'), [
                'message_id' => $answeredMessage->id,
            ])
            ->assertOk()
            ->assertJsonPath('last_read_message_id', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.message_id', $message->id);
    }

    public function test_dialog_notifications_require_dialog_view_permission(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'dialogs.view')
            ->update(['granted' => false]);

        $this->actingAs($employee)
            ->getJson(route('admin.dialog-notifications.show'))
            ->assertForbidden();
    }

    private function createDialogWithInboundMessage(string $text, ?int $assignedUserId = null): Dialog
    {
        $dialog = $this->createDialog($assignedUserId);
        $message = $this->addInboundMessage($dialog, $text);

        $dialog = $dialog->fresh(['contact', 'channel']);
        $dialog->forceFill([
            'last_message_id' => $message->id,
            'last_inbound_message_id' => $message->id,
            'last_message_at' => $message->received_at,
            'last_inbound_at' => $message->received_at,
            'last_message_preview' => $text,
            'last_inbound_message_preview' => $text,
        ])->save();

        return $dialog->fresh(['contact', 'channel']);
    }

    private function createDialog(?int $assignedUserId = null): Dialog
    {
        $channel = Channel::factory()->create([
            'name' => 'Telegram',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Тестовый контакт',
            'assigned_user_id' => $assignedUserId,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
        ]);

        return Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
        ]);
    }

    private function addInboundMessage(Dialog $dialog, string $text): Message
    {
        return $this->addMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => $text,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function addMessage(Dialog $dialog, array $overrides): Message
    {
        $identity = $dialog->currentContactIdentity;

        return Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'channel_id' => $dialog->channel_id,
            'contact_identity_id' => $identity?->id,
            'external_chat_id' => $dialog->external_chat_id,
            'received_at' => now(),
            ...$overrides,
        ]);
    }
}
