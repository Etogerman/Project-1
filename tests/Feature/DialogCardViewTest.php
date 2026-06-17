<?php

namespace Tests\Feature;

use App\Filament\Pages\ContactCardViewSettings;
use App\Filament\Resources\Dialogs\Pages\ViewDialog;
use App\Models\CardView;
use App\Models\CardViewItem;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\FieldDictionaryField;
use App\Models\Message;
use App\Models\User;
use App\Services\CardViews\CardViewFieldRendererRegistry;
use App\Services\Dialogs\EnsureEditableDialogCardViewAction;
use App\Services\Dialogs\SyncSystemDialogCardViewAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DialogCardViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_system_dialog_card_view_is_seeded(): void
    {
        $view = CardView::query()
            ->where('entity', CardView::ENTITY_DIALOG)
            ->where('context', CardView::CONTEXT_CARD)
            ->where('is_default', true)
            ->with('tabs.sections.items.field')
            ->firstOrFail();

        $this->assertSame(SyncSystemDialogCardViewAction::VIEW_KEY, $view->view_key);
        $this->assertTrue($view->is_system);

        $tabs = $view->tabs->pluck('tab_key')->all();

        $this->assertSame([
            SyncSystemDialogCardViewAction::TAB_GENERAL,
            SyncSystemDialogCardViewAction::TAB_BITRIX24,
            SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS,
            SyncSystemDialogCardViewAction::TAB_DIAGNOSTICS,
        ], $tabs);

        $generalTab = $view->tabs->firstWhere('tab_key', SyncSystemDialogCardViewAction::TAB_GENERAL);
        $generalSections = $generalTab->sections->keyBy('section_key');

        $this->assertSame([
            SyncSystemDialogCardViewAction::SECTION_DIALOG_SIDE_DATA,
        ], $generalSections->keys()->all());

        $this->assertSame('Данные диалога', $generalSections[SyncSystemDialogCardViewAction::SECTION_DIALOG_SIDE_DATA]->name);

        $sideFields = $generalSections[SyncSystemDialogCardViewAction::SECTION_DIALOG_SIDE_DATA]->items
            ->map(fn (CardViewItem $item): array => [$item->item_key, $item->item_type])
            ->all();

        $this->assertSame([
            ['id', CardViewItem::TYPE_FIELD],
            ['contact_id', CardViewItem::TYPE_FIELD],
            ['channel_id', CardViewItem::TYPE_FIELD],
            ['status', CardViewItem::TYPE_FIELD],
            ['assigned_user_id', CardViewItem::TYPE_FIELD],
            ['current_block_id', CardViewItem::TYPE_FIELD],
            ['created_at', CardViewItem::TYPE_FIELD],
            ['updated_at', CardViewItem::TYPE_FIELD],
            ['last_message_at', CardViewItem::TYPE_FIELD],
            ['last_inbound_message_at', CardViewItem::TYPE_FIELD],
            ['last_outbound_message_at', CardViewItem::TYPE_FIELD],
            ['phone', CardViewItem::TYPE_FIELD],
            ['external_username', CardViewItem::TYPE_FIELD],
        ], $sideFields);

        $diagnosticsTab = $view->tabs->firstWhere('tab_key', SyncSystemDialogCardViewAction::TAB_DIAGNOSTICS);
        $diagnosticsSection = $diagnosticsTab->sections->firstWhere('section_key', 'dialog_peer_sync');
        $diagnosticsItems = $diagnosticsSection->items
            ->map(fn (CardViewItem $item): array => [$item->item_key, $item->item_type, $item->field?->card_display_type])
            ->all();

        $this->assertSame([
            ['peer_sync', CardViewItem::TYPE_FIELD, FieldDictionaryField::CARD_DISPLAY_DIALOG_PEER_SYNC],
        ], $diagnosticsItems);
    }

    public function test_settings_page_can_switch_to_dialog_card_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class)
            ->call('selectEntity', CardView::ENTITY_DIALOG)
            ->assertSet('entity', CardView::ENTITY_DIALOG)
            ->assertSet('selectedTabKey', SyncSystemDialogCardViewAction::TAB_GENERAL)
            ->assertSet('selectedSectionKey', SyncSystemDialogCardViewAction::SECTION_DIALOG_SIDE_DATA)
            ->assertSee('Диалог')
            ->assertSee('Данные диалога')
            ->assertSee('ID')
            ->assertSee('Поле диалога из справочника')
            ->assertDontSee('Найти поле по названию или ключу')
            ->assertSee('data-field-picker', false)
            ->assertSee('data-field-picker-search', false)
            ->assertSee('Поиск по названию или ключу')
            ->assertSee('title="Последнее сообщение"', false)
            ->assertSee('title="last_message_at"', false)
            ->assertSee('data-tooltip="Последнее сообщение"', false)
            ->assertSee('data-tooltip="last_message_at"', false)
            ->assertDontSee('<div>Тип</div>', false)
            ->assertDontSee('>Поле</span>', false)
            ->assertDontSee('Основные данные диалога')
            ->assertDontSee('Готовый блок карточки')
            ->call('selectTab', SyncSystemDialogCardViewAction::TAB_DIAGNOSTICS)
            ->assertSet('selectedTabKey', SyncSystemDialogCardViewAction::TAB_DIAGNOSTICS)
            ->assertSee('Загрузка истории')
            ->assertSee('peer_sync')
            ->assertDontSee('Готовый блок карточки');
    }

    public function test_dialog_peer_sync_field_uses_internal_renderer(): void
    {
        $field = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'peer_sync')
            ->firstOrFail();

        $this->assertSame(
            SyncSystemDialogCardViewAction::BLOCK_DIALOG_PEER_SYNC,
            app(CardViewFieldRendererRegistry::class)->legacyBlockKeyForField($field),
        );
    }

    public function test_dialog_card_field_picker_filters_options_by_name_or_key(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class)
            ->call('selectEntity', CardView::ENTITY_DIALOG);

        $component->set('fieldItemSearch', 'юзер');

        $optionsByName = $component->instance()->filteredFieldOptions();

        $this->assertContains('Юзернейм · external_username', $optionsByName);
        $this->assertNotContains('Контакт · contact_id', $optionsByName);

        $component->set('fieldItemSearch', 'external');

        $optionsByKey = $component->instance()->filteredFieldOptions();

        $this->assertContains('Юзернейм · external_username', $optionsByKey);
        $this->assertNotContains('Статус · status', $optionsByKey);
    }

    public function test_settings_page_can_move_dialog_field_to_another_section(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $targetSection = CardView::query()
            ->where('view_key', SyncSystemDialogCardViewAction::VIEW_KEY)
            ->firstOrFail()
            ->tabs()
            ->where('tab_key', SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS)
            ->firstOrFail()
            ->sections()
            ->orderBy('sort_order')
            ->firstOrFail();
        $targetPath = sprintf(
            '%s|%s',
            SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS,
            $targetSection->section_key,
        );

        Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class)
            ->call('selectEntity', CardView::ENTITY_DIALOG)
            ->assertSee('Переместить')
            ->call('startMoveItem', 'status')
            ->assertSet('movingItemKey', 'status')
            ->set('moveTargetSectionPath', $targetPath)
            ->call('moveItemToTarget', 'status')
            ->assertSet('selectedTabKey', SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS)
            ->assertSet('selectedSectionKey', (string) $targetSection->section_key)
            ->assertSet('movingItemKey', null);

        $editableView = CardView::query()
            ->where('view_key', SyncSystemDialogCardViewAction::EDITABLE_VIEW_KEY)
            ->firstOrFail();
        $movedItem = CardViewItem::query()
            ->where('item_key', 'status')
            ->whereHas('section.tab', fn ($query) => $query->where('card_view_id', $editableView->id))
            ->with('section.tab')
            ->firstOrFail();

        $this->assertSame((string) $targetSection->section_key, $movedItem->section->section_key);
        $this->assertSame(SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS, $movedItem->section->tab->tab_key);
    }

    public function test_dialog_card_uses_moved_dialog_field_from_editable_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessage();
        $targetSection = CardView::query()
            ->where('view_key', SyncSystemDialogCardViewAction::VIEW_KEY)
            ->firstOrFail()
            ->tabs()
            ->where('tab_key', SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS)
            ->firstOrFail()
            ->sections()
            ->orderBy('sort_order')
            ->firstOrFail();
        $targetPath = sprintf(
            '%s|%s',
            SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS,
            $targetSection->section_key,
        );

        Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class)
            ->call('selectEntity', CardView::ENTITY_DIALOG)
            ->call('startMoveItem', 'status')
            ->set('moveTargetSectionPath', $targetPath)
            ->call('moveItemToTarget', 'status');

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSet('activeTab', SyncSystemDialogCardViewAction::TAB_GENERAL)
            ->assertSee('data-role="dialog-general-tab"', false)
            ->assertDontSee('data-field-key="status"', false)
            ->call('selectTab', SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS)
            ->assertSet('activeTab', SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS)
            ->assertSee('data-role="dialog-system-fields-tab"', false)
            ->assertSee('data-field-key="status"', false)
            ->assertSee('data-role="dialog-inbox-status-toggle"', false);
    }

    public function test_editable_dialog_card_view_is_copied_from_system_view(): void
    {
        $editableView = app(EnsureEditableDialogCardViewAction::class)->handle();
        $systemView = CardView::query()
            ->where('view_key', SyncSystemDialogCardViewAction::VIEW_KEY)
            ->with('tabs.sections.items')
            ->firstOrFail();

        $this->assertSame(SyncSystemDialogCardViewAction::EDITABLE_VIEW_KEY, $editableView->view_key);
        $this->assertFalse($editableView->is_system);
        $this->assertTrue($editableView->is_default);
        $this->assertFalse($systemView->refresh()->is_default);
        $this->assertSame($systemView->tabs()->count(), $editableView->tabs()->count());
    }

    public function test_dialog_card_renders_tabs_from_card_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessage();

        $view = app(EnsureEditableDialogCardViewAction::class)->handle();
        $view->tabs()->where('tab_key', SyncSystemDialogCardViewAction::TAB_BITRIX24)->update([
            'name' => 'CRM',
            'sort_order' => 15,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-role="dialog-card-tabs"', false)
            ->assertSee('CRM')
            ->assertSee('Сообщения диалога')
            ->assertSee('data-role="dialog-general-tab"', false)
            ->assertSee('Данные диалога')
            ->assertSee('data-role="dialog-inbox-status-toggle"', false)
            ->assertSee('Юзернейм')
            ->assertSee('@german')
            ->assertSee('data-copy-value="@german"', false)
            ->call('selectTab', SyncSystemDialogCardViewAction::TAB_BITRIX24)
            ->assertSet('activeTab', SyncSystemDialogCardViewAction::TAB_BITRIX24)
            ->assertSee('data-role="dialog-history"', false)
            ->assertSee('Сообщения диалога')
            ->assertSee('data-role="dialog-side-panel"', false)
            ->assertSee('data-role="dialog-bitrix24-tab"', false)
            ->assertSee('data-role="dialog-side-field-row"', false)
            ->assertSee('Открытые линии Bitrix24');
    }

    private function createDialogWithMessage(): Dialog
    {
        $channel = Channel::factory()->create([
            'name' => 'Локальный бот',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'first_name_source' => 'manual',
        ]);
        $identity = ContactIdentity::query()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'user-1',
            'external_username' => 'german',
            'display_name' => 'Герман',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'chat-1',
        ]);

        Message::query()->create([
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_message_id' => 'msg-1',
            'external_chat_id' => 'chat-1',
            'text' => 'Здравствуйте',
            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
            'received_at' => now(),
            'raw_payload' => [],
        ]);

        return $dialog->refresh();
    }
}
