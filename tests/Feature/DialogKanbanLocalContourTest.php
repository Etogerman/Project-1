<?php

namespace Tests\Feature;

use App\Filament\Resources\Dialogs\DialogResource;
use App\Filament\Resources\Dialogs\Pages\DialogKanban;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DialogKanbanLocalContourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_active_admin_can_open_dialog_kanban_page(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Канбан Клиент',
            'stage' => Dialog::STAGE_REQUIRES_REVIEW,
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('kanban'))
            ->assertOk()
            ->assertSee('Канбан диалогов')
            ->assertSee('Требует проверки')
            ->assertSee($dialog->contact->display_name);
    }

    public function test_kanban_page_does_not_apply_requires_reply_filter_by_default(): void
    {
        $admin = $this->createAdmin();
        $requiresReplyDialog = $this->createKanbanDialog([
            'contactName' => 'Требует ответа',
            'stage' => Dialog::STAGE_REQUIRES_REVIEW,
            'withInboundUserMessage' => true,
        ]);
        $noNewDialog = $this->createKanbanDialog([
            'contactName' => 'Нет новых',
            'stage' => Dialog::STAGE_REQUIRES_REVIEW,
            'withInboundUserMessage' => false,
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('kanban'))
            ->assertOk()
            ->assertSee($requiresReplyDialog->contact->display_name)
            ->assertSee($noNewDialog->contact->display_name);
    }

    public function test_kanban_page_can_move_review_card_to_working_stage_and_write_history(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Переход из проверки',
            'stage' => Dialog::STAGE_REQUIRES_REVIEW,
        ]);

        Livewire::actingAs($admin)
            ->test(DialogKanban::class)
            ->call('moveDialogCard', $dialog->id, Dialog::STAGE_PHONE_RECEIVED);

        $this->assertSame(Dialog::STAGE_PHONE_RECEIVED, $dialog->fresh()->stage);
        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
        ]);
    }

    public function test_kanban_page_blocks_invalid_move(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Невалидный переход',
            'stage' => Dialog::STAGE_TRANSFERRED_TO_MPL,
        ]);

        Livewire::actingAs($admin)
            ->test(DialogKanban::class)
            ->call('moveDialogCard', $dialog->id, Dialog::STAGE_PHONE_RECEIVED);

        $this->assertSame(Dialog::STAGE_TRANSFERRED_TO_MPL, $dialog->fresh()->stage);
        $this->assertDatabaseMissing('messages', [
            'dialog_id' => $dialog->id,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
        ]);
    }

    public function test_kanban_page_blocks_route_incomplete_move_to_manual_stage(): void
    {
        $admin = $this->createAdmin();
        $dialog = Dialog::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'channel_id' => Channel::factory()->create()->id,
            'current_contact_identity_id' => null,
            'external_chat_id' => null,
            'stage' => Dialog::STAGE_REQUIRES_REVIEW,
        ]);

        Livewire::actingAs($admin)
            ->test(DialogKanban::class)
            ->call('moveDialogCard', $dialog->id, Dialog::STAGE_TRANSFERRED_TO_MPL);

        $this->assertSame(Dialog::STAGE_REQUIRES_REVIEW, $dialog->fresh()->stage);
    }

    public function test_kanban_page_loads_more_cards_per_column(): void
    {
        $admin = $this->createAdmin();

        foreach (range(1, 31) as $number) {
            $this->createKanbanDialog([
                'contactName' => 'Карточка '.$number,
                'stage' => Dialog::STAGE_REQUIRES_REVIEW,
                'lastMessageAt' => now()->subMinutes($number),
            ]);
        }

        Livewire::actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSee('Карточка 1')
            ->assertDontSee('Карточка 31')
            ->call('loadMoreCards', Dialog::STAGE_REQUIRES_REVIEW)
            ->assertSee('Карточка 31');
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
    }

    /**
     * @param  array{
     *     contactName?:string,
     *     stage?:string|null,
     *     withInboundUserMessage?:bool,
     *     lastMessageAt?:\Illuminate\Support\Carbon|null,
     * }  $overrides
     */
    private function createKanbanDialog(array $overrides = []): Dialog
    {
        $contact = Contact::factory()->create([
            'name' => $overrides['contactName'] ?? 'Контакт канбана',
        ]);
        $channel = Channel::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_username' => 'kanban_user_'.$contact->id,
        ]);
        $lastMessageAt = $overrides['lastMessageAt'] ?? now()->subMinute();

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'kanban-chat-'.$contact->id,
            'stage' => $overrides['stage'] ?? Dialog::STAGE_REQUIRES_REVIEW,
            'last_message_at' => $lastMessageAt,
        ]);

        if (($overrides['withInboundUserMessage'] ?? false) === true) {
            Message::factory()->create([
                'dialog_id' => $dialog->id,
                'contact_id' => $contact->id,
                'contact_identity_id' => $identity->id,
                'channel_id' => $channel->id,
                'direction' => Message::DIRECTION_INBOUND,
                'message_kind' => Message::KIND_INBOUND_USER,
                'text' => 'Входящее сообщение',
                'external_chat_id' => 'kanban-chat-'.$contact->id,
                'received_at' => $lastMessageAt,
            ]);
        }

        return $dialog->fresh([
            'channel',
            'contact.assignedUser',
            'currentContactIdentity',
            'previewMessage.channel',
            'previewMessage.sentByUser',
        ]);
    }
}
