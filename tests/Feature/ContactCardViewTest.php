<?php

namespace Tests\Feature;

use App\Filament\Pages\ContactCardViewSettings;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Models\CardView;
use App\Models\CardViewItem;
use App\Models\CardViewSection;
use App\Models\CardViewTab;
use App\Models\Contact;
use App\Models\FieldDictionaryField;
use App\Models\User;
use App\Services\CardViews\CardViewFieldRendererRegistry;
use App\Services\Contacts\BuildContactCardViewLayoutAction;
use App\Services\Contacts\EnsureEditableContactCardViewAction;
use App\Services\Contacts\ResetEditableContactCardViewAction;
use App\Services\Contacts\SyncSystemContactCardViewAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ContactCardViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_system_contact_card_view_is_seeded(): void
    {
        $view = CardView::query()
            ->where('entity', CardView::ENTITY_CONTACT)
            ->where('context', CardView::CONTEXT_CARD)
            ->where('is_default', true)
            ->with('tabs.sections.items.field')
            ->firstOrFail();

        $this->assertSame('system_contact_card', $view->view_key);
        $this->assertTrue($view->is_system);
        $this->assertSame(1, CardView::query()
            ->where('entity', CardView::ENTITY_CONTACT)
            ->where('context', CardView::CONTEXT_CARD)
            ->where('is_default', true)
            ->count());

        $generalTab = $view->tabs->firstWhere('tab_key', 'general');
        $this->assertNotNull($generalTab);

        $sections = $generalTab->sections->keyBy('section_key');
        $this->assertSame([
            'client_data',
            'location',
            'work',
            'contact_phones',
            'contact_emails',
            'contact_tags',
        ], $sections->keys()->all());

        $workFields = $sections['work']->items
            ->map(fn (CardViewItem $item): ?string => $item->field?->field_key)
            ->all();

        $this->assertSame([
            'assigned_user_id',
            'is_auto_reply_enabled',
            'has_blocked_bot_dialog',
        ], $workFields);

        $contactFieldIds = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->whereIn('field_key', [
                'phones',
                'emails',
                'tags',
                'contact_dialogs',
                'contact_history',
                'contact_dedup',
                'contact_diagnostics',
            ])
            ->pluck('id', 'field_key');

        $generalComplexFields = collect(['contact_phones', 'contact_emails', 'contact_tags'])
            ->mapWithKeys(fn (string $sectionKey): array => [
                $sectionKey => $sections[$sectionKey]->items
                    ->map(fn (CardViewItem $item): array => [$item->item_key, $item->item_type, $item->field_dictionary_field_id])
                    ->all(),
            ])
            ->all();

        $this->assertSame([
            'contact_phones' => [
                ['phones', CardViewItem::TYPE_FIELD, $contactFieldIds['phones']],
            ],
            'contact_emails' => [
                ['emails', CardViewItem::TYPE_FIELD, $contactFieldIds['emails']],
            ],
            'contact_tags' => [
                ['tags', CardViewItem::TYPE_FIELD, $contactFieldIds['tags']],
            ],
        ], $generalComplexFields);

        $dialogsTab = $view->tabs->firstWhere('tab_key', 'dialogs');
        $this->assertNotNull($dialogsTab);

        $dialogSections = $dialogsTab->sections->keyBy('section_key');
        $this->assertSame(['contact_dialogs'], $dialogSections->keys()->all());

        $dialogBlocks = $dialogSections['contact_dialogs']->items
            ->map(fn (CardViewItem $item): array => [$item->item_key, $item->item_type, $item->field_dictionary_field_id])
            ->all();

        $this->assertSame([
            ['contact_dialogs', CardViewItem::TYPE_FIELD, $contactFieldIds['contact_dialogs']],
        ], $dialogBlocks);

        $historyTab = $view->tabs->firstWhere('tab_key', 'history');
        $this->assertNotNull($historyTab);

        $historySections = $historyTab->sections->keyBy('section_key');
        $this->assertSame(['contact_history'], $historySections->keys()->all());

        $historyBlocks = $historySections['contact_history']->items
            ->map(fn (CardViewItem $item): array => [$item->item_key, $item->item_type, $item->field_dictionary_field_id])
            ->all();

        $this->assertSame([
            ['contact_history', CardViewItem::TYPE_FIELD, $contactFieldIds['contact_history']],
        ], $historyBlocks);

        $dedupTab = $view->tabs->firstWhere('tab_key', 'dedup');
        $this->assertNotNull($dedupTab);

        $dedupSections = $dedupTab->sections->keyBy('section_key');
        $this->assertSame(['contact_dedup'], $dedupSections->keys()->all());

        $dedupBlocks = $dedupSections['contact_dedup']->items
            ->map(fn (CardViewItem $item): array => [$item->item_key, $item->item_type, $item->field_dictionary_field_id])
            ->all();

        $this->assertSame([
            ['contact_dedup', CardViewItem::TYPE_FIELD, $contactFieldIds['contact_dedup']],
        ], $dedupBlocks);

        $diagnosticsTab = $view->tabs->firstWhere('tab_key', 'diagnostics');
        $this->assertNotNull($diagnosticsTab);

        $diagnosticsSections = $diagnosticsTab->sections->keyBy('section_key');
        $this->assertSame(['contact_diagnostics'], $diagnosticsSections->keys()->all());

        $diagnosticsBlocks = $diagnosticsSections['contact_diagnostics']->items
            ->map(fn (CardViewItem $item): array => [$item->item_key, $item->item_type, $item->field_dictionary_field_id])
            ->all();

        $this->assertSame([
            ['contact_diagnostics', CardViewItem::TYPE_FIELD, $contactFieldIds['contact_diagnostics']],
        ], $diagnosticsBlocks);

        $bitrixTab = $view->tabs->firstWhere('tab_key', 'bitrix24');
        $this->assertNotNull($bitrixTab);

        $bitrixSections = $bitrixTab->sections->keyBy('section_key');
        $this->assertSame(['bitrix24_contact', 'bitrix24_deal', 'bitrix24_history'], $bitrixSections->keys()->all());

        $bitrixContactFields = $bitrixSections['bitrix24_contact']->items
            ->map(fn (CardViewItem $item): ?string => $item->field?->field_key)
            ->all();

        $this->assertSame([
            'bitrix24_contact_id',
            'bitrix24_sync_status',
            'bitrix24_last_synced_at',
            'bitrix24_linked_at',
            'bitrix24_sync_pending',
            'bitrix24_sync_fingerprint',
        ], $bitrixContactFields);

        $systemTab = $view->tabs->firstWhere('tab_key', 'system_fields');
        $this->assertNotNull($systemTab);

        $systemSections = $systemTab->sections->keyBy('section_key');
        $this->assertSame(['system_identity', 'system_dedup'], $systemSections->keys()->all());

        $systemDedupFields = $systemSections['system_dedup']->items
            ->map(fn (CardViewItem $item): ?string => $item->field?->field_key)
            ->all();

        $this->assertSame([
            'duplicate_review_status',
            'merged_into_contact_id',
            'merged_at',
            'merge_reason',
            'merge_trigger_phone',
        ], $systemDedupFields);
    }

    public function test_system_contact_card_sync_removes_stale_system_items(): void
    {
        $field = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'auto_reply_category')
            ->firstOrFail();

        CardViewItem::query()->create([
            'card_view_section_id' => $this->section('work')->id,
            'item_key' => 'auto_reply_category',
            'item_type' => CardViewItem::TYPE_FIELD,
            'field_dictionary_field_id' => $field->id,
            'sort_order' => 25,
            'is_visible' => true,
            'is_system' => true,
        ]);

        app(SyncSystemContactCardViewAction::class)->handle();

        $this->assertFalse(CardViewItem::query()
            ->whereHas('section', fn ($query) => $query->where('section_key', 'work'))
            ->where('item_key', 'auto_reply_category')
            ->exists());
    }

    public function test_unknown_contact_card_block_cannot_be_saved(): void
    {
        try {
            CardViewItem::query()->create([
                'card_view_section_id' => $this->section('contact_phones')->id,
                'item_key' => 'unknown_contact_block',
                'item_type' => CardViewItem::TYPE_BLOCK,
                'field_dictionary_field_id' => null,
                'sort_order' => 1000,
                'is_visible' => true,
                'is_system' => false,
            ]);
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Неизвестный блок вида карточки.',
                $exception->errors()['item_key'][0] ?? null,
            );

            return;
        }

        $this->fail('Ожидалась ошибка валидации.');
    }

    public function test_editable_contact_card_view_is_copied_from_system_view(): void
    {
        $editableView = app(EnsureEditableContactCardViewAction::class)->handle();
        $systemView = CardView::query()
            ->where('view_key', SyncSystemContactCardViewAction::VIEW_KEY)
            ->with('tabs.sections.items')
            ->firstOrFail();

        $this->assertSame(SyncSystemContactCardViewAction::EDITABLE_VIEW_KEY, $editableView->view_key);
        $this->assertFalse($editableView->is_system);
        $this->assertTrue($editableView->is_default);
        $this->assertFalse($systemView->refresh()->is_default);
        $this->assertSame($systemView->tabs()->count(), $editableView->tabs()->count());
        $this->assertSame(
            $systemView->tabs()->withCount('sections')->pluck('sections_count', 'tab_key')->all(),
            $editableView->tabs()->withCount('sections')->pluck('sections_count', 'tab_key')->all(),
        );
    }

    public function test_system_sync_keeps_editable_contact_card_view_as_default(): void
    {
        $editableView = app(EnsureEditableContactCardViewAction::class)->handle();
        $editableView->tabs()->where('tab_key', SyncSystemContactCardViewAction::TAB_GENERAL)->update([
            'name' => 'Главная карточка',
        ]);

        app(SyncSystemContactCardViewAction::class)->handle();

        $this->assertTrue($editableView->refresh()->is_default);
        $this->assertSame(
            'Главная карточка',
            $editableView->tabs()->where('tab_key', SyncSystemContactCardViewAction::TAB_GENERAL)->value('name'),
        );
        $this->assertFalse(CardView::query()
            ->where('view_key', SyncSystemContactCardViewAction::VIEW_KEY)
            ->value('is_default'));
    }

    public function test_editable_contact_card_view_can_be_reset_to_system_default(): void
    {
        app(EnsureEditableContactCardViewAction::class)->handle();

        $systemView = app(ResetEditableContactCardViewAction::class)->handle();

        $this->assertSame(SyncSystemContactCardViewAction::VIEW_KEY, $systemView->view_key);
        $this->assertTrue($systemView->is_default);
        $this->assertFalse(CardView::query()
            ->where('view_key', SyncSystemContactCardViewAction::EDITABLE_VIEW_KEY)
            ->exists());
    }

    public function test_duplicate_contact_card_view_item_cannot_be_saved_in_same_view(): void
    {
        $field = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'auto_reply_category')
            ->firstOrFail();

        CardViewItem::query()->create([
            'card_view_section_id' => $this->section('work')->id,
            'item_key' => 'auto_reply_category',
            'item_type' => CardViewItem::TYPE_FIELD,
            'field_dictionary_field_id' => $field->id,
            'sort_order' => 1000,
            'is_visible' => true,
            'is_system' => false,
        ]);

        try {
            CardViewItem::query()->create([
                'card_view_section_id' => $this->section('client_data')->id,
                'item_key' => 'auto_reply_category',
                'item_type' => CardViewItem::TYPE_FIELD,
                'field_dictionary_field_id' => $field->id,
                'sort_order' => 1000,
                'is_visible' => true,
                'is_system' => false,
            ]);
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Этот элемент уже есть в виде карточки.',
                $exception->errors()['item_key'][0] ?? null,
            );

            return;
        }

        $this->fail('Ожидалась ошибка валидации.');
    }

    public function test_admin_can_rename_contact_card_tab_from_settings_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();

        Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class)
            ->set('tabRows.general.name', 'Главное')
            ->call('saveTab', 'general');

        $this->assertSame(
            'Главное',
            CardView::query()
                ->where('view_key', SyncSystemContactCardViewAction::EDITABLE_VIEW_KEY)
                ->firstOrFail()
                ->tabs()
                ->where('tab_key', SyncSystemContactCardViewAction::TAB_GENERAL)
                ->value('name'),
        );

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSee('Главное');
    }

    public function test_contact_card_settings_uses_field_only_picker_for_contact(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class)
            ->assertSee('Поле контакта из справочника')
            ->assertDontSee('Системные блоки карточки')
            ->assertDontSee('Блок — готовая область карточки')
            ->assertDontSee('Выберите системный блок')
            ->assertDontSee('Готовый блок карточки');
    }

    public function test_contact_card_settings_normalizes_legacy_contact_blocks_to_fields(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $section = $this->section('contact_phones');
        $field = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'phones')
            ->firstOrFail();

        $section->items()->delete();
        CardViewItem::query()->create([
            'card_view_section_id' => $section->id,
            'item_key' => SyncSystemContactCardViewAction::BLOCK_CONTACT_PHONES,
            'item_type' => CardViewItem::TYPE_BLOCK,
            'field_dictionary_field_id' => null,
            'sort_order' => 77,
            'is_visible' => false,
            'is_system' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class)
            ->assertSee('Телефоны');

        $item = $section->items()->firstOrFail();

        $this->assertSame('phones', $item->item_key);
        $this->assertSame(CardViewItem::TYPE_FIELD, $item->item_type);
        $this->assertSame($field->id, $item->field_dictionary_field_id);
        $this->assertSame(77, $item->sort_order);
        $this->assertFalse($item->is_visible);
        $this->assertTrue($item->is_system);
    }

    public function test_contact_card_field_renderer_registry_resolves_complex_contact_fields(): void
    {
        $registry = app(CardViewFieldRendererRegistry::class);
        $fields = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->whereIn('field_key', [
                'first_name',
                'phones',
                'emails',
                'tags',
                'contact_dialogs',
                'contact_history',
                'contact_dedup',
                'contact_diagnostics',
            ])
            ->get()
            ->keyBy('field_key');

        $this->assertNull($registry->rendererKeyForField($fields['first_name']));
        $this->assertNull($registry->legacyBlockKeyForField($fields['first_name']));

        $this->assertSame(CardViewFieldRendererRegistry::CONTACT_PHONE_LIST, $registry->rendererKeyForField($fields['phones']));
        $this->assertSame(SyncSystemContactCardViewAction::BLOCK_CONTACT_PHONES, $registry->legacyBlockKeyForField($fields['phones']));

        $this->assertSame(CardViewFieldRendererRegistry::CONTACT_EMAIL_LIST, $registry->rendererKeyForField($fields['emails']));
        $this->assertSame(SyncSystemContactCardViewAction::BLOCK_CONTACT_EMAILS, $registry->legacyBlockKeyForField($fields['emails']));

        $this->assertSame(CardViewFieldRendererRegistry::CONTACT_TAG_LIST, $registry->rendererKeyForField($fields['tags']));
        $this->assertSame(SyncSystemContactCardViewAction::BLOCK_CONTACT_TAGS, $registry->legacyBlockKeyForField($fields['tags']));

        $this->assertSame(CardViewFieldRendererRegistry::CONTACT_DIALOGS, $registry->rendererKeyForField($fields['contact_dialogs']));
        $this->assertSame(SyncSystemContactCardViewAction::BLOCK_CONTACT_DIALOGS, $registry->legacyBlockKeyForField($fields['contact_dialogs']));

        $this->assertSame(CardViewFieldRendererRegistry::CONTACT_HISTORY, $registry->rendererKeyForField($fields['contact_history']));
        $this->assertSame(SyncSystemContactCardViewAction::BLOCK_CONTACT_HISTORY, $registry->legacyBlockKeyForField($fields['contact_history']));

        $this->assertSame(CardViewFieldRendererRegistry::CONTACT_DEDUP, $registry->rendererKeyForField($fields['contact_dedup']));
        $this->assertSame(SyncSystemContactCardViewAction::BLOCK_CONTACT_DEDUP, $registry->legacyBlockKeyForField($fields['contact_dedup']));

        $this->assertSame(CardViewFieldRendererRegistry::CONTACT_DIAGNOSTICS, $registry->rendererKeyForField($fields['contact_diagnostics']));
        $this->assertSame(SyncSystemContactCardViewAction::BLOCK_CONTACT_DIAGNOSTICS, $registry->legacyBlockKeyForField($fields['contact_diagnostics']));
    }

    public function test_contact_card_layout_exposes_internal_renderer_for_complex_fields(): void
    {
        $layout = app(BuildContactCardViewLayoutAction::class)->itemsForTab(SyncSystemContactCardViewAction::TAB_GENERAL);
        $this->assertIsArray($layout);

        $itemsByKey = collect($layout['sections'])
            ->flatMap(fn (array $section): array => $section['items'])
            ->keyBy('item_key');

        $this->assertSame(SyncSystemContactCardViewAction::BLOCK_CONTACT_PHONES, $itemsByKey['phones']['renderer_block_key']);
        $this->assertSame(SyncSystemContactCardViewAction::BLOCK_CONTACT_EMAILS, $itemsByKey['emails']['renderer_block_key']);
        $this->assertSame(SyncSystemContactCardViewAction::BLOCK_CONTACT_TAGS, $itemsByKey['tags']['renderer_block_key']);
        $this->assertSame('', $itemsByKey['first_name']['renderer_block_key']);

        $dialogLayout = app(BuildContactCardViewLayoutAction::class)->itemsForTab(SyncSystemContactCardViewAction::TAB_DIALOGS);
        $this->assertIsArray($dialogLayout);
        $dialogItemsByKey = collect($dialogLayout['sections'])
            ->flatMap(fn (array $section): array => $section['items'])
            ->keyBy('item_key');

        $this->assertSame(SyncSystemContactCardViewAction::BLOCK_CONTACT_DIALOGS, $dialogItemsByKey['contact_dialogs']['renderer_block_key']);
    }

    public function test_admin_can_create_and_delete_custom_card_view_tab_and_section(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class)
            ->set('newTab.name', 'Проверка')
            ->set('newTab.tab_key', 'custom_check')
            ->call('addTab')
            ->assertSet('selectedTabKey', 'custom_check')
            ->set('newSection.name', 'Секция проверки')
            ->set('newSection.section_key', 'custom_check_section')
            ->call('addSection')
            ->assertSet('selectedSectionKey', 'custom_check_section');

        $view = CardView::query()
            ->where('view_key', SyncSystemContactCardViewAction::EDITABLE_VIEW_KEY)
            ->firstOrFail();

        $tab = $view->tabs()
            ->where('tab_key', 'custom_check')
            ->firstOrFail();
        $section = $tab->sections()
            ->where('section_key', 'custom_check_section')
            ->firstOrFail();

        $this->assertSame('Проверка', $tab->name);
        $this->assertFalse($tab->is_system);
        $this->assertSame('Секция проверки', $section->name);
        $this->assertFalse($section->is_system);

        Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class)
            ->call('selectTab', 'custom_check')
            ->call('selectSection', 'custom_check_section')
            ->call('deleteSection', 'custom_check_section')
            ->call('deleteTab', 'custom_check');

        $this->assertDatabaseMissing('card_view_sections', [
            'section_key' => 'custom_check_section',
        ]);
        $this->assertDatabaseMissing('card_view_tabs', [
            'tab_key' => 'custom_check',
        ]);
    }

    public function test_admin_can_move_existing_field_to_custom_card_view_tab(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $firstNameField = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'first_name')
            ->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class)
            ->set('newTab.name', 'Проверка полей')
            ->set('newTab.tab_key', 'custom_fields')
            ->call('addTab')
            ->set('newSection.name', 'Профиль клиента')
            ->set('newSection.section_key', 'custom_profile')
            ->call('addSection')
            ->set('newFieldItem.field_id', (string) $firstNameField->id)
            ->call('addFieldItem');

        $view = CardView::query()
            ->where('view_key', SyncSystemContactCardViewAction::EDITABLE_VIEW_KEY)
            ->firstOrFail();
        $section = $view->tabs()
            ->where('tab_key', 'custom_fields')
            ->firstOrFail()
            ->sections()
            ->where('section_key', 'custom_profile')
            ->firstOrFail();

        $this->assertSame(1, CardViewItem::query()
            ->where('item_key', 'first_name')
            ->whereHas('section.tab.view', fn ($query) => $query->whereKey($view->getKey()))
            ->count());
        $this->assertSame($section->id, CardViewItem::query()
            ->where('item_key', 'first_name')
            ->whereHas('section.tab.view', fn ($query) => $query->whereKey($view->getKey()))
            ->value('card_view_section_id'));
        $this->assertTrue((bool) CardViewItem::query()
            ->where('item_key', 'first_name')
            ->whereHas('section.tab.view', fn ($query) => $query->whereKey($view->getKey()))
            ->value('is_system'));
    }

    public function test_admin_can_reorder_contact_card_view_items_from_settings_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class)
            ->set('itemRows.effective_age_years.sort_order', 5)
            ->call('saveSelectedItems');

        $view = CardView::query()
            ->where('view_key', SyncSystemContactCardViewAction::EDITABLE_VIEW_KEY)
            ->firstOrFail();

        $this->assertSame(5, CardViewItem::query()
            ->where('item_key', 'effective_age_years')
            ->whereHas('section.tab.view', fn ($query) => $query->whereKey($view->getKey()))
            ->value('sort_order'));
        $this->assertSame(
            'effective_age_years',
            array_key_first($component->instance()->itemRows),
        );
    }

    public function test_system_contact_field_item_cannot_be_deleted_when_working_copy_flag_is_stale(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $view = app(EnsureEditableContactCardViewAction::class)->handle();
        $item = CardViewItem::query()
            ->where('item_key', 'first_name')
            ->whereHas('section.tab.view', fn ($query) => $query->whereKey($view->getKey()))
            ->firstOrFail();

        $item->forceFill(['is_system' => false])->save();

        $component = Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class);

        $this->assertTrue($component->instance()->itemRows['first_name']['is_system']);

        $component->call('deleteItem', 'first_name');

        $this->assertDatabaseHas('card_view_items', [
            'id' => $item->id,
            'item_key' => 'first_name',
        ]);
    }

    public function test_admin_can_move_contact_card_view_items_with_internal_action(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class)
            ->call('moveItemDown', 'first_name');

        $this->assertSame(
            ['first_name_source', 'first_name', 'last_name'],
            array_slice(array_keys($component->instance()->itemRows), 0, 3),
        );

        $section = CardViewSection::query()
            ->where('section_key', 'client_data')
            ->whereHas('tab.view', fn ($query) => $query->where('view_key', SyncSystemContactCardViewAction::EDITABLE_VIEW_KEY))
            ->firstOrFail();

        $this->assertSame(
            ['first_name_source', 'first_name', 'last_name'],
            $section->items()->orderBy('sort_order')->orderBy('id')->limit(3)->pluck('item_key')->all(),
        );
        $this->assertSame(
            [10, 20, 30],
            $section->items()->orderBy('sort_order')->orderBy('id')->limit(3)->pluck('sort_order')->all(),
        );

        $component->call('moveItemUp', 'first_name');

        $this->assertSame(
            ['first_name', 'first_name_source', 'last_name'],
            array_slice(array_keys($component->instance()->itemRows), 0, 3),
        );
    }

    public function test_contact_card_renders_custom_card_view_tab_fields(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Проверочное имя',
        ]);
        $firstNameField = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'first_name')
            ->firstOrFail();
        $view = app(EnsureEditableContactCardViewAction::class)->handle();
        $tab = CardViewTab::query()->create([
            'card_view_id' => $view->id,
            'tab_key' => 'custom_fields',
            'name' => 'Проверка полей',
            'sort_order' => 900,
            'is_visible' => true,
            'is_system' => false,
        ]);
        $section = CardViewSection::query()->create([
            'card_view_tab_id' => $tab->id,
            'section_key' => 'custom_profile',
            'name' => 'Профиль клиента',
            'sort_order' => 10,
            'is_visible' => true,
            'is_collapsed_by_default' => false,
            'is_system' => false,
        ]);

        CardViewItem::query()
            ->where('item_key', 'first_name')
            ->whereHas('section.tab.view', fn ($query) => $query->whereKey($view->getKey()))
            ->firstOrFail()
            ->fill([
                'card_view_section_id' => $section->id,
                'field_dictionary_field_id' => $firstNameField->id,
                'sort_order' => 10,
                'is_visible' => true,
            ])
            ->save();

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSee('Проверка полей')
            ->call('selectTab', 'custom_fields')
            ->assertSet('activeTab', 'custom_fields')
            ->assertSee('Профиль клиента')
            ->assertSee('Проверочное имя');
    }

    public function test_contact_card_renders_complex_fields_after_moving_to_custom_card_view_tab(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        $view = app(EnsureEditableContactCardViewAction::class)->handle();
        $tab = CardViewTab::query()->create([
            'card_view_id' => $view->id,
            'tab_key' => 'custom_contact_channels',
            'name' => 'Каналы клиента',
            'sort_order' => 910,
            'is_visible' => true,
            'is_system' => false,
        ]);
        $section = CardViewSection::query()->create([
            'card_view_tab_id' => $tab->id,
            'section_key' => 'custom_channel_fields',
            'name' => 'Связь с клиентом',
            'sort_order' => 10,
            'is_visible' => true,
            'is_collapsed_by_default' => false,
            'is_system' => false,
        ]);

        foreach (['phones', 'emails', 'tags'] as $index => $fieldKey) {
            $field = FieldDictionaryField::query()
                ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
                ->where('field_key', $fieldKey)
                ->firstOrFail();

            CardViewItem::query()
                ->where('item_key', $fieldKey)
                ->whereHas('section.tab.view', fn ($query) => $query->whereKey($view->getKey()))
                ->firstOrFail()
                ->fill([
                    'card_view_section_id' => $section->id,
                    'field_dictionary_field_id' => $field->id,
                    'sort_order' => ($index + 1) * 10,
                    'is_visible' => true,
                ])
                ->save();
        }

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSee('Каналы клиента')
            ->assertDontSee('Телефоны не указаны')
            ->assertDontSee('Email не указан')
            ->assertDontSee('Теги не назначены')
            ->call('selectTab', 'custom_contact_channels')
            ->assertSet('activeTab', 'custom_contact_channels')
            ->assertSee('Телефоны не указаны')
            ->assertSee('Email не указан')
            ->assertSee('Теги не назначены');
    }

    public function test_contact_card_view_settings_restore_standard_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ContactCardViewSettings::class)
            ->set('tabRows.general.name', 'Главное')
            ->call('saveTab', 'general')
            ->call('restoreStandardView')
            ->assertSee('Системный эталон');

        $this->assertFalse(CardView::query()
            ->where('view_key', SyncSystemContactCardViewAction::EDITABLE_VIEW_KEY)
            ->exists());
        $this->assertTrue(CardView::query()
            ->where('view_key', SyncSystemContactCardViewAction::VIEW_KEY)
            ->value('is_default'));
    }

    public function test_contact_card_uses_section_title_from_card_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
        ]);

        $this->section('client_data')->update(['name' => 'Клиентские данные']);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSee('Клиентские данные')
            ->assertDontSee('Данные клиента');
    }

    public function test_contact_card_uses_tab_order_and_labels_from_card_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);

        foreach ([
            SyncSystemContactCardViewAction::TAB_HISTORY => ['01 Журнал', 10],
            SyncSystemContactCardViewAction::TAB_GENERAL => ['02 Главное', 20],
            SyncSystemContactCardViewAction::TAB_DIALOGS => ['03 Переписки', 30],
            SyncSystemContactCardViewAction::TAB_BITRIX24 => ['04 CRM', 40],
            SyncSystemContactCardViewAction::TAB_DEDUP => ['05 Склейки', 50],
            SyncSystemContactCardViewAction::TAB_SYSTEM_FIELDS => ['06 Системное', 60],
            SyncSystemContactCardViewAction::TAB_DIAGNOSTICS => ['07 Техника', 70],
        ] as $tabKey => [$label, $sortOrder]) {
            $this->tab($tabKey)->update([
                'name' => $label,
                'sort_order' => $sortOrder,
            ]);
        }

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSeeInOrder([
                '01 Журнал',
                '02 Главное',
                '03 Переписки',
                '04 CRM',
                '05 Склейки',
                '06 Системное',
                '07 Техника',
            ]);
    }

    public function test_contact_card_hides_tab_from_card_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();

        $this->tab(SyncSystemContactCardViewAction::TAB_DIAGNOSTICS)->update([
            'is_visible' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertDontSee('Диагностика')
            ->set('activeTab', ViewContact::TAB_DIAGNOSTICS)
            ->assertSet('activeTab', ViewContact::TAB_GENERAL);
    }

    public function test_contact_general_tab_uses_complex_fields_from_card_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSee('Телефоны не указаны')
            ->assertSee('Email не указан')
            ->assertSee('Теги не назначены');

        foreach (['phones', 'emails', 'tags'] as $fieldKey) {
            CardViewItem::query()
                ->where('item_key', $fieldKey)
                ->update(['is_visible' => false]);
        }

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertDontSee('Телефоны не указаны')
            ->assertDontSee('Email не указан')
            ->assertDontSee('Теги не назначены');
    }

    public function test_contact_bitrix_tab_uses_section_title_from_card_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'bitrix24_contact_id' => 'B24-C-100',
        ]);

        $this->section('bitrix24_contact')->update(['name' => 'CRM контакт']);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_BITRIX24)
            ->assertSee('CRM контакт')
            ->assertDontSee('Контакт в Bitrix24');
    }

    public function test_contact_system_fields_tab_uses_section_title_from_card_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'first_name' => 'Основной',
        ]);
        $contact = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
            'merge_reason' => 'phone_exact_match',
            'merge_trigger_phone' => '+79991234567',
        ]);

        $this->section('system_dedup')->update(['name' => 'Служебная склейка']);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_SYSTEM_FIELDS)
            ->assertSee('Служебная склейка')
            ->assertSee('Нужна проверка')
            ->assertSee('Совпадение телефона')
            ->assertDontSee('Склейки и дубли');
    }

    public function test_contact_dialogs_tab_uses_complex_field_from_card_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_DIALOGS)
            ->assertSee('Диалоги ещё не появились.');

        CardViewItem::query()
            ->whereHas('section', fn ($query) => $query->where('section_key', 'contact_dialogs'))
            ->where('item_key', SyncSystemContactCardViewAction::BLOCK_CONTACT_DIALOGS)
            ->update(['is_visible' => false]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_DIALOGS)
            ->assertDontSee('Диалоги ещё не появились.');
    }

    public function test_contact_history_tab_uses_complex_field_from_card_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertSee('История событий контакта');

        CardViewItem::query()
            ->whereHas('section', fn ($query) => $query->where('section_key', 'contact_history'))
            ->where('item_key', SyncSystemContactCardViewAction::BLOCK_CONTACT_HISTORY)
            ->update(['is_visible' => false]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertDontSee('История событий контакта');
    }

    public function test_contact_dedup_tab_uses_complex_field_from_card_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'first_name' => 'Основной',
        ]);
        $contact = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
            'merge_reason' => 'phone_exact_match',
            'merge_trigger_phone' => '+79991234567',
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_DEDUP)
            ->assertSee('Склейки')
            ->assertSee('Архивный дубль')
            ->assertSee('Совпадение телефона');

        CardViewItem::query()
            ->whereHas('section', fn ($query) => $query->where('section_key', 'contact_dedup'))
            ->where('item_key', SyncSystemContactCardViewAction::BLOCK_CONTACT_DEDUP)
            ->update(['is_visible' => false]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_DEDUP)
            ->assertDontSee('Архивный дубль')
            ->assertDontSee('Совпадение телефона');
    }

    public function test_contact_diagnostics_tab_uses_complex_field_from_card_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_DIAGNOSTICS)
            ->assertSee('Последний inbound webhook')
            ->assertSee('Route context')
            ->assertSee('Identity')
            ->assertSee('Технические поля контакта')
            ->assertSee('Дедупликация');

        CardViewItem::query()
            ->whereHas('section', fn ($query) => $query->where('section_key', 'contact_diagnostics'))
            ->where('item_key', SyncSystemContactCardViewAction::BLOCK_CONTACT_DIAGNOSTICS)
            ->update(['is_visible' => false]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_DIAGNOSTICS)
            ->assertDontSee('Последний inbound webhook')
            ->assertDontSee('Route context')
            ->assertDontSee('Identity')
            ->assertDontSee('Технические поля контакта')
            ->assertDontSee('Дедупликация');
    }

    public function test_contact_card_skips_unknown_view_field_without_breaking_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
        ]);
        $section = $this->section('client_data');

        CardViewItem::query()->create([
            'card_view_section_id' => $section->id,
            'item_key' => 'unknown_card_field',
            'item_type' => CardViewItem::TYPE_FIELD,
            'field_dictionary_field_id' => null,
            'sort_order' => 1,
            'is_visible' => true,
            'is_system' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSee('Общее')
            ->assertSee('Имя')
            ->assertDontSee('unknown_card_field');
    }

    public function test_contact_card_falls_back_to_hardcoded_sections_without_default_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
        ]);

        CardView::query()
            ->where('entity', CardView::ENTITY_CONTACT)
            ->where('context', CardView::CONTEXT_CARD)
            ->update(['is_default' => false]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSee('Данные клиента')
            ->assertSee('Локация')
            ->assertSee('Работа с контактом');
    }

    public function test_field_used_by_card_view_cannot_be_deleted(): void
    {
        $field = FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_CONTACT,
            'field_key' => 'custom_card_field',
            'name' => 'Пользовательское поле карточки',
            'type' => FieldDictionaryField::TYPE_TEXT,
            'sort_order' => 2000,
        ]);

        CardViewItem::query()->create([
            'card_view_section_id' => $this->section('client_data')->id,
            'item_key' => 'custom_card_field',
            'item_type' => CardViewItem::TYPE_FIELD,
            'field_dictionary_field_id' => $field->id,
            'sort_order' => 1000,
            'is_visible' => true,
            'is_system' => false,
        ]);

        try {
            $field->delete();
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Нельзя удалить поле, пока оно используется в виде карточки.',
                $exception->errors()['field'][0] ?? null,
            );

            return;
        }

        $this->fail('Ожидалась ошибка валидации.');
    }

    protected function section(string $sectionKey): CardViewSection
    {
        return CardViewSection::query()
            ->where('section_key', $sectionKey)
            ->whereHas('tab.view', fn ($query) => $query
                ->where('entity', CardView::ENTITY_CONTACT)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('is_default', true))
            ->firstOrFail();
    }

    protected function tab(string $tabKey): CardViewTab
    {
        return CardViewTab::query()
            ->where('tab_key', $tabKey)
            ->whereHas('view', fn ($query) => $query
                ->where('entity', CardView::ENTITY_CONTACT)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('is_default', true))
            ->firstOrFail();
    }
}
