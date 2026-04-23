<?php

namespace Tests\Feature;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Filament\Resources\Dialogs\Pages\DialogKanban;
use App\Filament\Resources\Dialogs\Pages\ListDialogs;
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
            ->assertSee('Диалоги')
            ->assertSee('Канбан')
            ->assertSee('Таблица')
            ->assertSee('Требует проверки')
            ->assertSee($dialog->contact->display_name)
            ->assertSee('Открыть диалог');
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

    public function test_kanban_page_restores_filters_from_query_string(): void
    {
        $admin = $this->createAdmin();
        $channel = Channel::factory()->create();
        $assignee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $assignee->givePermissionTo('contacts.assign');

        Livewire::withQueryParams([
            'channel' => (string) $channel->id,
            'assignee' => (string) $assignee->id,
            'route' => 'ready',
            'inbox' => DialogInboxStatusData::CODE_NO_NEW,
        ])
            ->actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSet('selectedChannelId', (string) $channel->id)
            ->assertSet('selectedAssignedUserId', (string) $assignee->id)
            ->assertSet('selectedRouteStatus', 'ready')
            ->assertSet('selectedInboxStatus', DialogInboxStatusData::CODE_NO_NEW);
    }

    public function test_kanban_card_view_link_contains_back_to_filtered_slice(): void
    {
        $admin = $this->createAdmin();
        $channel = Channel::factory()->create();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Срез канбана',
            'stage' => Dialog::STAGE_REQUIRES_REVIEW,
        ]);

        $response = $this->actingAs($admin)->get(
            DialogResource::getUrl('kanban').'?'.http_build_query([
                'channel' => (string) $channel->id,
                'route' => 'ready',
            ]),
        );

        $expectedBackTo = DialogResource::getUrl('kanban').'?'.http_build_query([
            'channel' => (string) $channel->id,
            'route' => 'ready',
        ]);
        $expectedViewUrl = DialogResource::getUrl('view', ['record' => $dialog]).'?'.http_build_query([
            'back_to' => $expectedBackTo,
        ]);

        $response
            ->assertOk()
            ->assertSee($expectedViewUrl, false);
    }

    public function test_dialog_view_renders_back_to_kanban_link_when_opened_from_board(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Возврат в канбан',
            'stage' => Dialog::STAGE_REQUIRES_REVIEW,
        ]);
        $backTo = DialogResource::getUrl('kanban').'?'.http_build_query([
            'route' => 'ready',
            'inbox' => DialogInboxStatusData::CODE_NO_NEW,
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]).'?'.http_build_query([
                'back_to' => $backTo,
            ]))
            ->assertOk()
            ->assertSee('Вернуться в канбан')
            ->assertSee($backTo, false);
    }

    public function test_dialog_resource_navigation_url_remembers_last_kanban_slice(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Запомненный канбан',
            'stage' => Dialog::STAGE_REQUIRES_REVIEW,
        ]);

        $expectedUrl = DialogResource::getUrl('kanban').'?'.http_build_query([
            'channel' => (string) $dialog->channel_id,
            'route' => 'ready',
        ]);

        $this->actingAs($admin)
            ->get($expectedUrl)
            ->assertOk();

        $this->assertSame($expectedUrl, DialogResource::getNavigationUrl());
    }

    public function test_dialog_resource_navigation_url_returns_to_current_table_slice_after_opening_index(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Срез таблицы',
            'stage' => Dialog::STAGE_REQUIRES_REVIEW,
        ]);
        $expectedUrl = DialogResource::getUrl('index').'?'.http_build_query([
            'search' => 'Срез',
            'sort' => 'last_message_at:desc',
            'filters' => [
                'inbox_status' => [
                    'value' => DialogInboxStatusData::CODE_REQUIRES_REPLY,
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('kanban').'?'.http_build_query([
                'channel' => (string) $dialog->channel_id,
            ]))
            ->assertOk();

        $this->actingAs($admin)
            ->get($expectedUrl)
            ->assertOk();

        $this->assertSame($expectedUrl, DialogResource::getNavigationUrl());
    }

    public function test_dialog_resource_navigation_url_updates_after_table_filter_is_removed(): void
    {
        $admin = $this->createAdmin();

        $component = Livewire::actingAs($admin)->test(ListDialogs::class);

        $component->filterTable('inbox_status', DialogInboxStatusData::CODE_REQUIRES_REPLY);

        $this->assertStringContainsString(
            'filters%5Binbox_status%5D%5Bvalue%5D='.DialogInboxStatusData::CODE_REQUIRES_REPLY,
            DialogResource::getNavigationUrl(),
        );

        $component->removeTableFilter('inbox_status');

        $this->assertStringNotContainsString('inbox_status', DialogResource::getNavigationUrl());
    }

    public function test_dialog_resource_navigation_url_updates_after_all_table_filters_are_removed(): void
    {
        $admin = $this->createAdmin();

        $component = Livewire::actingAs($admin)->test(ListDialogs::class);

        $component
            ->filterTable('inbox_status', DialogInboxStatusData::CODE_REQUIRES_REPLY)
            ->set('tableSearch', 'abc');

        $this->assertStringContainsString(
            'filters%5Binbox_status%5D%5Bvalue%5D='.DialogInboxStatusData::CODE_REQUIRES_REPLY,
            DialogResource::getNavigationUrl(),
        );
        $this->assertStringContainsString('search=abc', DialogResource::getNavigationUrl());

        $component->removeTableFilters();

        $this->assertStringNotContainsString('inbox_status', DialogResource::getNavigationUrl());
        $this->assertStringNotContainsString('search=abc', DialogResource::getNavigationUrl());
    }

    public function test_dialog_resource_navigation_url_updates_after_table_search_is_reset(): void
    {
        $admin = $this->createAdmin();

        $component = Livewire::actingAs($admin)->test(ListDialogs::class);

        $component->set('tableSearch', 'abc');

        $this->assertStringContainsString('search=abc', DialogResource::getNavigationUrl());

        $component->call('resetTableSearch');

        $this->assertStringNotContainsString('search=abc', DialogResource::getNavigationUrl());
    }

    public function test_dialog_resource_navigation_url_updates_after_table_sort_changes(): void
    {
        $admin = $this->createAdmin();

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->call('sortTable', 'id', 'asc');

        $this->assertStringContainsString('sort=id%3Aasc', DialogResource::getNavigationUrl());
    }

    public function test_dialog_resource_navigation_url_updates_after_table_page_changes(): void
    {
        $admin = $this->createAdmin();

        $component = Livewire::actingAs($admin)->test(ListDialogs::class);

        $component->call('setPage', 2);

        $this->assertStringContainsString('page=2', DialogResource::getNavigationUrl());

        $component->call('resetPage');

        $this->assertStringNotContainsString('page=2', DialogResource::getNavigationUrl());
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
