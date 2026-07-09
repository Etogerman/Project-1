<?php

namespace Tests\Feature;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Filament\Resources\Dialogs\Pages\DialogKanban;
use App\Filament\Resources\Dialogs\Pages\ListDialogs;
use App\Filament\Resources\Dialogs\Pages\ViewDialog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\DialogStage;
use App\Models\Message;
use App\Models\User;
use App\Services\Dialogs\BuildDialogMessageSnapshotPayloadAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $assignee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'name' => 'Оператор Канбана',
            'last_name' => 'Петров',
        ]);
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Канбан Клиент',
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'assignedUserId' => $assignee->id,
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('kanban'))
            ->assertOk()
            ->assertSee('Диалоги')
            ->assertSee('Канбан')
            ->assertSee('Фильтр')
            ->assertSee('Таблица')
            ->assertSeeInOrder(['Фильтр', 'Таблица'])
            ->assertDontSee('ac-kanban-hero__title', false)
            ->assertDontSee('Сортировка')
            ->assertDontSee('Сортировка появится следующим срезом')
            ->assertDontSee('Требует проверки')
            ->assertSee($dialog->contact->display_name)
            ->assertSee('Оператор Канбана Петров')
            ->assertSee('Открыть диалог');
    }

    public function test_kanban_page_does_not_apply_requires_reply_filter_by_default(): void
    {
        $admin = $this->createAdmin();
        $requiresReplyDialog = $this->createKanbanDialog([
            'contactName' => 'Требует ответа',
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'withInboundUserMessage' => true,
        ]);
        $noNewDialog = $this->createKanbanDialog([
            'contactName' => 'Нет новых',
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'withInboundUserMessage' => false,
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('kanban'))
            ->assertOk()
            ->assertSee($requiresReplyDialog->contact->display_name)
            ->assertSee($noNewDialog->contact->display_name);
    }

    public function test_kanban_resolves_inbox_status_without_per_card_latest_message_queries(): void
    {
        $admin = $this->createAdmin();
        $dialogs = collect(range(1, 6))
            ->map(fn (int $index): Dialog => $this->createKanbanDialog([
                'contactName' => 'Клиент канбана '.$index,
                'stage' => Dialog::STAGE_NEW_DIALOG,
                'withInboundUserMessage' => true,
                'lastMessageAt' => now()->subMinutes($index),
            ]));

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSee($dialogs->firstOrFail()->contact->display_name)
            ->assertSee($dialogs->last()->contact->display_name)
            ->assertSee('Требует ответа');

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $latestMessageLookups = $queries
            ->filter(function (string $query): bool {
                $query = strtolower($query);

                return str_starts_with($query, 'select * from "messages"')
                    && str_contains($query, '"messages"."dialog_id"')
                    && str_contains($query, 'limit 1');
            })
            ->count();

        $this->assertSame(0, $latestMessageLookups, $queries->implode(PHP_EOL));
    }

    public function test_kanban_resolves_route_status_without_per_card_connection_type_queries(): void
    {
        $admin = $this->createAdmin();
        $dialogs = collect(range(1, 6))
            ->map(fn (int $index): Dialog => $this->createKanbanDialog([
                'contactName' => 'Аккаунт канал '.$index,
                'stage' => Dialog::STAGE_NEW_DIALOG,
                'channelAttributes' => [
                    'platform' => Channel::PLATFORM_TELEGRAM,
                    'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
                    'bot_token_present' => false,
                ],
                'lastMessageAt' => now()->subMinutes($index),
            ]));

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSee($dialogs->firstOrFail()->contact->display_name)
            ->assertSee($dialogs->last()->contact->display_name);

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $connectionTypeLookups = $queries
            ->filter(function (string $query): bool {
                $query = strtolower($query);

                return str_starts_with($query, 'select * from "channel_connection_types"')
                    && str_contains($query, 'limit 1');
            })
            ->count();

        $this->assertSame(0, $connectionTypeLookups, $queries->implode(PHP_EOL));
    }

    public function test_kanban_reuses_dialog_stage_catalog_during_render(): void
    {
        $admin = $this->createAdmin();
        $dialogs = collect(range(1, 6))
            ->map(fn (int $index): Dialog => $this->createKanbanDialog([
                'contactName' => 'Клиент стадий '.$index,
                'stage' => Dialog::STAGE_NEW_DIALOG,
                'lastMessageAt' => now()->subMinutes($index),
            ]));

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSee($dialogs->firstOrFail()->contact->display_name)
            ->assertSee($dialogs->last()->contact->display_name);

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $dialogStageCatalogLoads = $queries
            ->filter(function (string $query): bool {
                $query = strtolower($query);

                return str_starts_with($query, 'select')
                    && (str_contains($query, 'from "dialog_stages"') || str_contains($query, 'from `dialog_stages`'));
            })
            ->count();

        $this->assertLessThanOrEqual(2, $dialogStageCatalogLoads, $queries->implode(PHP_EOL));
    }

    public function test_kanban_inbox_status_filter_keeps_only_matching_cards(): void
    {
        $admin = $this->createAdmin();
        $requiresReplyDialog = $this->createKanbanDialog([
            'contactName' => 'Канбан нужен ответ',
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'withInboundUserMessage' => true,
            'lastMessageAt' => now()->subMinutes(5),
        ]);
        $noNewDialog = $this->createKanbanDialog([
            'contactName' => 'Канбан ответ уже есть',
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'withInboundUserMessage' => true,
            'withOutboundManualReply' => true,
            'lastMessageAt' => now()->subMinutes(4),
        ]);

        Livewire::withQueryParams([
            'inbox' => DialogInboxStatusData::CODE_REQUIRES_REPLY,
        ])
            ->actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSee($requiresReplyDialog->contact->display_name)
            ->assertDontSee($noNewDialog->contact->display_name);
    }

    public function test_kanban_route_status_filter_keeps_only_matching_cards(): void
    {
        $admin = $this->createAdmin();
        $readyDialog = $this->createKanbanDialog([
            'contactName' => 'Канбан маршрут готов',
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'channelAttributes' => [
                'platform' => Channel::PLATFORM_MAX,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'bot_token_present' => true,
                'is_active' => true,
            ],
            'externalChatId' => 'ready-route-chat',
        ]);
        $problemDialog = $this->createKanbanDialog([
            'contactName' => 'Канбан маршрут проблема',
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'channelAttributes' => [
                'platform' => Channel::PLATFORM_MAX,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'bot_token_present' => true,
                'is_active' => false,
            ],
            'externalChatId' => 'problem-route-chat',
        ]);

        Livewire::withQueryParams([
            'route' => 'ready',
        ])
            ->actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSee($readyDialog->contact->display_name)
            ->assertDontSee($problemDialog->contact->display_name);

        Livewire::withQueryParams([
            'route' => 'problem',
        ])
            ->actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSee($problemDialog->contact->display_name)
            ->assertDontSee($readyDialog->contact->display_name);
    }

    public function test_kanban_query_sort_sorts_cards_by_oldest_activity(): void
    {
        $admin = $this->createAdmin();
        $olderDialog = $this->createKanbanDialog([
            'contactName' => 'Старый диалог',
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'lastMessageAt' => Carbon::parse('2026-05-26 10:00:00'),
        ]);
        $newerDialog = $this->createKanbanDialog([
            'contactName' => 'Новый диалог',
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'lastMessageAt' => Carbon::parse('2026-05-26 12:00:00'),
        ]);

        Livewire::actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSeeInOrder([
                $newerDialog->contact->display_name,
                $olderDialog->contact->display_name,
            ]);

        Livewire::withQueryParams([
            'sort' => 'activity_asc',
        ])
            ->actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSet('selectedSort', 'activity_asc')
            ->assertSeeInOrder([
                $olderDialog->contact->display_name,
                $newerDialog->contact->display_name,
            ]);
    }

    public function test_kanban_query_sort_can_put_dialogs_requiring_reply_first(): void
    {
        $admin = $this->createAdmin();
        $answeredDialog = $this->createKanbanDialog([
            'contactName' => 'Ответ уже есть',
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'withInboundUserMessage' => true,
            'withOutboundManualReply' => true,
            'lastMessageAt' => Carbon::parse('2026-05-26 12:00:00'),
        ]);
        $requiresReplyDialog = $this->createKanbanDialog([
            'contactName' => 'Нужен ответ',
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'withInboundUserMessage' => true,
            'lastMessageAt' => Carbon::parse('2026-05-26 10:00:00'),
        ]);

        Livewire::withQueryParams([
            'sort' => 'requires_reply_first',
        ])
            ->actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSet('selectedSort', 'requires_reply_first')
            ->assertSeeInOrder([
                $requiresReplyDialog->contact->display_name,
                $answeredDialog->contact->display_name,
            ]);
    }

    public function test_kanban_page_marks_empty_columns_for_compact_layout(): void
    {
        $admin = $this->createAdmin();
        $this->createKanbanDialog([
            'contactName' => 'Только одна карточка',
            'stage' => Dialog::STAGE_NEW_DIALOG,
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('kanban'))
            ->assertOk()
            ->assertSee('ac-kanban-column--empty', false)
            ->assertSee('--ac-kanban-column-width: min(12rem, calc(100vw - 2rem));', false)
            ->assertDontSee('.ac-kanban-column--empty.ac-kanban-column--drop-target', false)
            ->assertDontSee('flex-basis 180ms ease', false);
    }

    public function test_kanban_page_renders_optimistic_drag_drop_contract(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Оптимистичный перенос',
            'stage' => Dialog::STAGE_NEW_DIALOG,
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('kanban'))
            ->assertOk()
            ->assertSee('data-role="dialog-kanban-column-cards"', false)
            ->assertSee('data-role="dialog-kanban-column-count"', false)
            ->assertSee('data-dialog-id="'.$dialog->id.'"', false)
            ->assertSee('data-current-stage="'.Dialog::STAGE_NEW_DIALOG.'"', false)
            ->assertSee('dropCard($el', false)
            ->assertSee('optimisticMove(targetColumn, dialogId, targetStage)', false)
            ->assertSee('ac-kanban-card--optimistic-move', false)
            ->assertSee('ac-kanban-column--optimistic-target', false);
    }

    public function test_kanban_page_restores_filters_from_query_string(): void
    {
        $admin = $this->createAdmin();
        $channel = Channel::factory()->create();
        $assignee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'name' => 'German',
            'last_name' => 'Abrikosov',
        ]);

        Livewire::withQueryParams([
            'channel' => (string) $channel->id,
            'assignee' => (string) $assignee->id,
            'route' => 'ready',
            'inbox' => DialogInboxStatusData::CODE_NO_NEW,
            'search' => '@german_abrikosov',
        ])
            ->actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSet('selectedChannelId', (string) $channel->id)
            ->assertSet('selectedAssignedUserId', (string) $assignee->id)
            ->assertSet('selectedRouteStatus', 'ready')
            ->assertSet('selectedInboxStatus', DialogInboxStatusData::CODE_NO_NEW)
            ->assertSet('search', '@german_abrikosov')
            ->assertSet('filtersPanelOpen', true)
            ->assertSee('class="ac-button ac-button--warning-soft"', false)
            ->assertDontSee('hasActiveFilters', false)
            ->assertSee('German Abrikosov')
            ->assertSeeInOrder(['Поиск', 'Канал', 'Ответственный', 'Маршрут', 'Статус диалога']);
    }

    public function test_kanban_card_view_link_contains_back_to_filtered_slice(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Срез канбана',
            'stage' => Dialog::STAGE_NEW_DIALOG,
        ]);

        $response = $this->actingAs($admin)->get(
            DialogResource::getUrl('kanban').'?'.http_build_query([
                'search' => 'Срез',
                'channel' => (string) $dialog->channel_id,
                'route' => 'ready',
            ]),
        );

        $expectedBackTo = DialogResource::getUrl('kanban').'?'.http_build_query([
            'search' => 'Срез',
            'channel' => (string) $dialog->channel_id,
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
            'stage' => Dialog::STAGE_NEW_DIALOG,
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
            ->assertSee('Вернуться в диалоги')
            ->assertSee($backTo);
    }

    public function test_dialog_view_uses_dialog_breadcrumbs_when_opened_from_dialogs_list(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Возврат в диалоги',
            'stage' => Dialog::STAGE_NEW_DIALOG,
        ]);
        $backTo = DialogResource::getUrl('index');

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]).'?'.http_build_query([
                'back_to' => $backTo,
            ]))
            ->assertOk()
            ->assertSee('data-entry-point="dialogs"', false)
            ->assertSee('Вернуться в диалоги')
            ->assertSee('Диалог #'.$dialog->id);
    }

    public function test_dialog_breadcrumbs_keep_dialog_entry_point_after_live_refresh(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Не перепрыгивает в контакт',
            'stage' => Dialog::STAGE_NEW_DIALOG,
        ]);
        $backTo = DialogResource::getUrl('kanban');

        Livewire::withQueryParams([
            'back_to' => $backTo,
        ])
            ->actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-entry-point="dialogs"', false)
            ->assertSee('Вернуться в диалоги')
            ->call('refreshDialogViewData')
            ->assertSee('data-entry-point="dialogs"', false)
            ->assertSee('Вернуться в диалоги')
            ->assertSee('Диалог #'.$dialog->id)
            ->assertDontSee('Вернуться к контакту')
            ->assertDontSee('data-entry-point="contact"', false);
    }

    public function test_dialog_resource_navigation_url_remembers_last_kanban_slice(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Запомненный канбан',
            'stage' => Dialog::STAGE_NEW_DIALOG,
        ]);

        $expectedUrl = DialogResource::getUrl('kanban').'?'.http_build_query([
            'channel' => (string) $dialog->channel_id,
            'route' => 'ready',
        ]);

        $this->actingAs($admin)
            ->get($expectedUrl)
            ->assertOk();

        $this->assertSameUrlIgnoringQueryOrder($expectedUrl, DialogResource::getNavigationUrl());
    }

    public function test_dialog_resource_navigation_url_is_remembered_on_initial_table_get(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Начальный срез таблицы',
            'stage' => Dialog::STAGE_NEW_DIALOG,
        ]);
        $expectedUrl = DialogResource::getUrl('index').'?'.http_build_query([
            'search' => 'Начальный',
            'filters' => [
                'channel_id' => [
                    'value' => (string) $dialog->channel_id,
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->get($expectedUrl)
            ->assertOk();

        $this->assertSameUrlIgnoringQueryOrder($expectedUrl, DialogResource::getNavigationUrl());
    }

    public function test_kanban_filters_can_be_reset_to_base_slice(): void
    {
        $admin = $this->createAdmin();

        Livewire::withQueryParams([
            'channel' => '12',
            'assignee' => '7',
            'route' => 'ready',
            'inbox' => DialogInboxStatusData::CODE_REQUIRES_REPLY,
            'search' => '@german_abrikosov',
        ])
            ->actingAs($admin)
            ->test(DialogKanban::class)
            ->call('resetKanbanFilters')
            ->assertSet('selectedChannelId', '')
            ->assertSet('selectedAssignedUserId', '')
            ->assertSet('selectedRouteStatus', '')
            ->assertSet('selectedInboxStatus', '')
            ->assertSet('search', '')
            ->assertSet('filtersPanelOpen', false)
            ->assertSee('class="ac-button ac-button--secondary"', false)
            ->assertDontSee('class="ac-button ac-button--warning-soft"', false)
            ->assertDontSee('hasActiveFilters', false);

        $this->assertSame(DialogResource::getUrl('kanban'), DialogResource::getNavigationUrl());
    }

    public function test_kanban_page_searches_dialog_cards_by_dialog_search_fields(): void
    {
        $admin = $this->createAdmin();
        $targetDialog = $this->createKanbanDialog([
            'contactName' => 'Мария Поиск',
            'externalUsername' => 'german_abrikosov',
            'externalChatId' => 'kanban-target-chat',
            'confirmedPhoneRaw' => '+55 (11) 91234-5678',
            'confirmedPhoneNormalized' => '+5511912345678',
        ]);
        $otherDialog = $this->createKanbanDialog([
            'contactName' => 'Посторонний клиент',
            'externalUsername' => 'other_user',
            'externalChatId' => 'kanban-other-chat',
        ]);

        Livewire::withQueryParams([
            'search' => '@german_abrikosov',
        ])
            ->actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSet('search', '@german_abrikosov')
            ->assertSee($targetDialog->contact->display_name)
            ->assertDontSee($otherDialog->contact->display_name)
            ->set('search', '551191234')
            ->assertSee($targetDialog->contact->display_name)
            ->assertDontSee($otherDialog->contact->display_name)
            ->set('search', 'kanban-target-chat')
            ->assertSee($targetDialog->contact->display_name)
            ->assertDontSee($otherDialog->contact->display_name);
    }

    public function test_kanban_filters_open_as_client_side_dropdown_without_livewire_toggle_delay(): void
    {
        $admin = $this->createAdmin();

        Livewire::actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSet('filtersPanelOpen', false)
            ->assertSee('ac-kanban-filter-wrap', false)
            ->assertSee('class="ac-button ac-button--secondary"', false)
            ->assertDontSee('hasActiveFilters', false)
            ->assertDontSee("'ac-button--warning-soft': hasActiveFilters", false)
            ->assertDontSee('open || hasActiveFilters', false)
            ->assertSee('x-on:click.prevent="open = ! open"', false)
            ->assertSee('x-show="open"', false)
            ->assertSee('ac-kanban-filters-popover', false)
            ->assertDontSee('wire:click="toggleFiltersPanel"', false)
            ->assertSeeInOrder(['Поиск', 'Фильтр', 'Канал', 'Ответственный', 'Маршрут', 'Статус диалога']);
    }

    public function test_dialog_resource_navigation_url_returns_to_current_table_slice_after_opening_index(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Срез таблицы',
            'stage' => Dialog::STAGE_NEW_DIALOG,
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

        $this->assertSameUrlIgnoringQueryOrder($expectedUrl, DialogResource::getNavigationUrl());
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

    public function test_kanban_page_can_move_automatic_card_to_manual_stage_and_write_history(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Переход в ручной этап',
            'stage' => Dialog::STAGE_NEW_DIALOG,
        ]);

        Livewire::actingAs($admin)
            ->test(DialogKanban::class)
            ->call('moveDialogCard', $dialog->id, Dialog::STAGE_TRANSFERRED_TO_MPL);

        $this->assertSame(Dialog::STAGE_TRANSFERRED_TO_MPL, $dialog->fresh()->stage);
        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
        ]);
    }

    public function test_kanban_page_can_move_card_to_any_working_stage(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Переход в любую рабочую стадию',
            'stage' => Dialog::STAGE_TRANSFERRED_TO_MPL,
        ]);

        Livewire::actingAs($admin)
            ->test(DialogKanban::class)
            ->call('moveDialogCard', $dialog->id, Dialog::STAGE_PHONE_RECEIVED);

        $this->assertSame(Dialog::STAGE_PHONE_RECEIVED, $dialog->fresh()->stage);
        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
        ]);
    }

    public function test_kanban_page_allows_route_incomplete_move_without_history(): void
    {
        $admin = $this->createAdmin();
        $dialog = Dialog::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'channel_id' => Channel::factory()->create()->id,
            'current_contact_identity_id' => null,
            'external_chat_id' => null,
            'stage' => Dialog::STAGE_NEW_DIALOG,
        ]);

        Livewire::actingAs($admin)
            ->test(DialogKanban::class)
            ->call('moveDialogCard', $dialog->id, Dialog::STAGE_TRANSFERRED_TO_MPL);

        $this->assertSame(Dialog::STAGE_TRANSFERRED_TO_MPL, $dialog->fresh()->stage);
        $this->assertDatabaseMissing('messages', [
            'dialog_id' => $dialog->id,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
        ]);
    }

    public function test_kanban_page_loads_more_cards_per_column(): void
    {
        $admin = $this->createAdmin();

        foreach (range(1, 16) as $number) {
            $this->createKanbanDialog([
                'contactName' => 'Карточка '.$number,
                'stage' => Dialog::STAGE_NEW_DIALOG,
                'lastMessageAt' => now()->subMinutes($number),
            ]);
        }

        Livewire::actingAs($admin)
            ->test(DialogKanban::class)
            ->assertSee('Карточка 1')
            ->assertDontSee('Карточка 16')
            ->call('loadMoreCards', Dialog::STAGE_NEW_DIALOG)
            ->assertSee('Карточка 16');
    }

    public function test_kanban_page_renders_legacy_review_dialog_in_effective_stage_column(): void
    {
        $admin = $this->createAdmin();
        $dialog = $this->createKanbanDialog([
            'contactName' => 'Legacy review диалог',
            'stage' => 'requires_review',
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('kanban'))
            ->assertOk()
            ->assertDontSee('Требует проверки')
            ->assertSee($dialog->contact->display_name);
    }

    public function test_kanban_column_headers_use_stage_colors_with_contrast_text(): void
    {
        $admin = $this->createAdmin();

        DialogStage::query()
            ->where('key', Dialog::STAGE_NEW_DIALOG)
            ->firstOrFail()
            ->update(['color' => 'ab_navy']);

        DialogStage::query()
            ->where('key', Dialog::STAGE_PHONE_RECEIVED)
            ->firstOrFail()
            ->update(['color' => 'ab_yellow']);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('kanban'))
            ->assertOk()
            ->assertSeeHtml('data-stage-color="#003399"')
            ->assertSeeHtml('--ac-kanban-stage-bg: #003399')
            ->assertSeeHtml('--ac-kanban-stage-text: #FFFFFF')
            ->assertSeeHtml('data-stage-color="#FFCC00"')
            ->assertSeeHtml('--ac-kanban-stage-bg: #FFCC00')
            ->assertSeeHtml('--ac-kanban-stage-text: #111827');
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
    }

    private function assertSameUrlIgnoringQueryOrder(string $expectedUrl, string $actualUrl): void
    {
        $this->assertSame(parse_url($expectedUrl, PHP_URL_SCHEME), parse_url($actualUrl, PHP_URL_SCHEME));
        $this->assertSame(parse_url($expectedUrl, PHP_URL_HOST), parse_url($actualUrl, PHP_URL_HOST));
        $this->assertSame(parse_url($expectedUrl, PHP_URL_PORT), parse_url($actualUrl, PHP_URL_PORT));
        $this->assertSame(parse_url($expectedUrl, PHP_URL_PATH), parse_url($actualUrl, PHP_URL_PATH));

        parse_str((string) parse_url($expectedUrl, PHP_URL_QUERY), $expectedQuery);
        parse_str((string) parse_url($actualUrl, PHP_URL_QUERY), $actualQuery);
        $this->sortQueryParameters($expectedQuery);
        $this->sortQueryParameters($actualQuery);

        $this->assertSame($expectedQuery, $actualQuery);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function sortQueryParameters(array &$query): void
    {
        ksort($query);

        foreach ($query as &$value) {
            if (is_array($value)) {
                $this->sortQueryParameters($value);
            }
        }
    }

    /**
     * @param  array{
     *     contactName?:string,
     *     stage?:string|null,
     *     withInboundUserMessage?:bool,
     *     withOutboundManualReply?:bool,
     *     lastMessageAt?:Carbon|null,
     *     channelAttributes?:array<string, mixed>,
     *     externalUsername?:string|null,
     *     externalChatId?:string|null,
     *     confirmedPhoneRaw?:string|null,
     *     confirmedPhoneNormalized?:string|null,
     * }  $overrides
     */
    private function createKanbanDialog(array $overrides = []): Dialog
    {
        $contact = Contact::factory()->create([
            'name' => $overrides['contactName'] ?? 'Контакт канбана',
            'assigned_user_id' => $overrides['assignedUserId'] ?? null,
        ]);
        $channel = Channel::factory()->create($overrides['channelAttributes'] ?? []);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'display_name' => $overrides['contactName'] ?? 'Контакт канбана',
            'external_username' => $overrides['externalUsername'] ?? 'kanban_user_'.$contact->id,
        ]);
        $lastMessageAt = $overrides['lastMessageAt'] ?? now()->subMinute();

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => $overrides['externalChatId'] ?? 'kanban-chat-'.$contact->id,
            'stage' => $overrides['stage'] ?? Dialog::STAGE_NEW_DIALOG,
            'last_message_at' => $lastMessageAt,
            'confirmed_phone_raw' => $overrides['confirmedPhoneRaw'] ?? null,
            'confirmed_phone_normalized' => $overrides['confirmedPhoneNormalized'] ?? null,
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

            if (($overrides['withOutboundManualReply'] ?? false) === true) {
                Message::factory()->create([
                    'dialog_id' => $dialog->id,
                    'contact_id' => $contact->id,
                    'contact_identity_id' => $identity->id,
                    'channel_id' => $channel->id,
                    'direction' => Message::DIRECTION_OUTBOUND,
                    'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
                    'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
                    'text' => 'Ручной ответ оператора',
                    'external_chat_id' => 'kanban-chat-'.$contact->id,
                    'received_at' => $lastMessageAt->copy()->addMinute(),
                ]);
            }

            $dialog->forceFill(app(BuildDialogMessageSnapshotPayloadAction::class)->fromMessages(
                Message::query()
                    ->where('dialog_id', $dialog->id)
                    ->get(),
            ))->save();
        }

        return $dialog->fresh([
            'channel',
            'contact.assignedUser',
            'currentContactIdentity',
            'lastMessage.channel',
            'lastMessage.sentByUser',
        ]);
    }
}
