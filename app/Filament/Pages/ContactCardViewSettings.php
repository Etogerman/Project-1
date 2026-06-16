<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Models\CardView;
use App\Models\CardViewItem;
use App\Models\CardViewSection;
use App\Models\CardViewTab;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\FieldDictionaryField;
use App\Models\User;
use App\Services\Contacts\ContactCardViewBlockRegistry;
use App\Services\Contacts\EnsureEditableContactCardViewAction;
use App\Services\Contacts\ResetEditableContactCardViewAction;
use App\Services\Contacts\SyncSystemContactCardViewAction;
use App\Services\Dialogs\DialogCardViewBlockRegistry;
use App\Services\Dialogs\EnsureEditableDialogCardViewAction;
use App\Services\Dialogs\ResetEditableDialogCardViewAction;
use App\Services\Dialogs\SyncSystemDialogCardViewAction;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

class ContactCardViewSettings extends Page
{
    private const CONTACT_LEGACY_BLOCK_FIELD_MAP = [
        SyncSystemContactCardViewAction::BLOCK_CONTACT_PHONES => 'phones',
        SyncSystemContactCardViewAction::BLOCK_CONTACT_EMAILS => 'emails',
        SyncSystemContactCardViewAction::BLOCK_CONTACT_TAGS => 'tags',
    ];

    private const DIALOG_LEGACY_BLOCK_FIELD_MAP = [
        SyncSystemDialogCardViewAction::BLOCK_DIALOG_PEER_SYNC => 'peer_sync',
    ];

    protected static ?string $slug = 'contact-card-view-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $navigationLabel = 'Виды карточек';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 24;

    protected string $view = 'filament.pages.contact-card-view-settings';

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $tabRows = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $sectionRows = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $itemRows = [];

    public ?string $selectedTabKey = null;

    public ?string $selectedSectionKey = null;

    #[Url(as: 'entity', history: true, except: CardView::ENTITY_CONTACT)]
    public string $entity = CardView::ENTITY_CONTACT;

    /**
     * @var array<string, mixed>
     */
    public array $newTab = [
        'tab_key' => '',
        'name' => '',
        'sort_order' => '',
    ];

    /**
     * @var array<string, mixed>
     */
    public array $newSection = [
        'section_key' => '',
        'name' => '',
        'sort_order' => '',
        'is_collapsed_by_default' => false,
    ];

    /**
     * @var array<string, mixed>
     */
    public array $newFieldItem = [
        'field_id' => '',
    ];

    public string $fieldItemSearch = '';

    /**
     * @var array<string, mixed>
     */
    public array $newBlockItem = [
        'block_key' => '',
    ];

    public ?string $movingItemKey = null;

    public string $moveTargetSectionPath = '';

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->entity = $this->normalizeEntity($this->entity);
        $this->reloadRows();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageSystem();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Виды карточек';
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function selectTab(string $tabKey): void
    {
        if (! array_key_exists($tabKey, $this->tabRows)) {
            return;
        }

        $this->selectedTabKey = $tabKey;
        $this->selectedSectionKey = null;
        $this->fieldItemSearch = '';
        $this->resetMoveState();
        $this->reloadRows();
    }

    public function selectSection(string $sectionKey): void
    {
        if (! array_key_exists($sectionKey, $this->sectionRows)) {
            return;
        }

        $this->selectedSectionKey = $sectionKey;
        $this->fieldItemSearch = '';
        $this->resetMoveState();
        $this->reloadRows();
    }

    public function selectEntity(string $entity): void
    {
        $this->entity = $this->normalizeEntity($entity);
        $this->selectedTabKey = null;
        $this->selectedSectionKey = null;
        $this->newFieldItem = ['field_id' => ''];
        $this->fieldItemSearch = '';
        $this->newBlockItem = ['block_key' => ''];
        $this->resetMoveState();
        $this->reloadRows();
    }

    public function saveTab(string $tabKey): void
    {
        $row = $this->tabRows[$tabKey] ?? null;

        if (! is_array($row)) {
            return;
        }

        try {
            $view = $this->editableView();
            $tab = $view->tabs()->where('tab_key', $tabKey)->firstOrFail();
            $isVisible = (bool) ($row['is_visible'] ?? false);

            if (! $isVisible && $this->visibleTabCount($view, $tabKey) < 1) {
                throw ValidationException::withMessages([
                    'tab' => 'В карточке должна остаться хотя бы одна видимая вкладка.',
                ]);
            }

            $tab->fill([
                'name' => $this->requiredText($row['name'] ?? '', 'Название вкладки обязательно.'),
                'sort_order' => (int) ($row['sort_order'] ?? 100),
                'is_visible' => $isVisible,
            ])->save();

            $this->reloadRows();
            $this->notifySuccess('Вкладка сохранена');
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось сохранить вкладку', $throwable);
        }
    }

    public function saveSection(string $sectionKey): void
    {
        $row = $this->sectionRows[$sectionKey] ?? null;

        if (! is_array($row) || ! filled($this->selectedTabKey)) {
            return;
        }

        try {
            $section = $this->editableSection((string) $this->selectedTabKey, $sectionKey);
            $section->fill([
                'name' => $this->requiredText($row['name'] ?? '', 'Название секции обязательно.'),
                'sort_order' => (int) ($row['sort_order'] ?? 100),
                'is_visible' => (bool) ($row['is_visible'] ?? false),
                'is_collapsed_by_default' => (bool) ($row['is_collapsed_by_default'] ?? false),
            ])->save();

            $this->reloadRows();
            $this->notifySuccess('Секция сохранена');
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось сохранить секцию', $throwable);
        }
    }

    public function addTab(): void
    {
        try {
            $name = $this->requiredText($this->newTab['name'] ?? '', 'Название вкладки обязательно.');
            $tabKey = $this->normalizeKey($this->newTab['tab_key'] ?? '', $name, 'Ключ вкладки должен быть латиницей, цифрами и подчёркиваниями.');
            $view = $this->editableView();

            if ($view->tabs()->where('tab_key', $tabKey)->exists()) {
                throw ValidationException::withMessages([
                    'tab_key' => 'Вкладка с таким ключом уже есть.',
                ]);
            }

            CardViewTab::query()->create([
                'card_view_id' => $view->id,
                'tab_key' => $tabKey,
                'name' => $name,
                'sort_order' => $this->optionalSortOrder($this->newTab['sort_order'] ?? null, $this->nextTabSortOrder($view)),
                'is_visible' => true,
                'is_system' => false,
            ]);

            $this->newTab = $this->blankNewTabState();
            $this->selectedTabKey = $tabKey;
            $this->selectedSectionKey = null;
            $this->reloadRows();
            $this->notifySuccess('Вкладка добавлена');
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось добавить вкладку', $throwable);
        }
    }

    public function deleteTab(string $tabKey): void
    {
        try {
            $view = $this->editableView();
            $tab = $view->tabs()->where('tab_key', $tabKey)->firstOrFail();

            if ($tab->is_system) {
                throw ValidationException::withMessages([
                    'tab' => 'Системную вкладку можно скрыть, но нельзя удалить.',
                ]);
            }

            $tab->delete();

            if ($this->selectedTabKey === $tabKey) {
                $this->selectedTabKey = null;
                $this->selectedSectionKey = null;
            }

            $this->reloadRows();
            $this->notifySuccess('Вкладка удалена');
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось удалить вкладку', $throwable);
        }
    }

    public function addSection(): void
    {
        if (! filled($this->selectedTabKey)) {
            return;
        }

        try {
            $name = $this->requiredText($this->newSection['name'] ?? '', 'Название секции обязательно.');
            $sectionKey = $this->normalizeKey($this->newSection['section_key'] ?? '', $name, 'Ключ секции должен быть латиницей, цифрами и подчёркиваниями.');
            $view = $this->editableView();
            $tab = $view->tabs()->where('tab_key', (string) $this->selectedTabKey)->firstOrFail();

            if ($tab->sections()->where('section_key', $sectionKey)->exists()) {
                throw ValidationException::withMessages([
                    'section_key' => 'Секция с таким ключом уже есть в этой вкладке.',
                ]);
            }

            CardViewSection::query()->create([
                'card_view_tab_id' => $tab->id,
                'section_key' => $sectionKey,
                'name' => $name,
                'sort_order' => $this->optionalSortOrder($this->newSection['sort_order'] ?? null, $this->nextSectionSortOrder($tab)),
                'is_visible' => true,
                'is_collapsed_by_default' => (bool) ($this->newSection['is_collapsed_by_default'] ?? false),
                'is_system' => false,
            ]);

            $this->newSection = $this->blankNewSectionState();
            $this->selectedSectionKey = $sectionKey;
            $this->reloadRows();
            $this->notifySuccess('Секция добавлена');
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось добавить секцию', $throwable);
        }
    }

    public function deleteSection(string $sectionKey): void
    {
        if (! filled($this->selectedTabKey)) {
            return;
        }

        try {
            $section = $this->editableSection((string) $this->selectedTabKey, $sectionKey);

            if ($section->is_system) {
                throw ValidationException::withMessages([
                    'section' => 'Системную секцию можно скрыть, но нельзя удалить.',
                ]);
            }

            $section->delete();

            if ($this->selectedSectionKey === $sectionKey) {
                $this->selectedSectionKey = null;
            }

            $this->reloadRows();
            $this->notifySuccess('Секция удалена');
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось удалить секцию', $throwable);
        }
    }

    public function saveItem(string $itemKey): void
    {
        $row = $this->itemRows[$itemKey] ?? null;

        if (! is_array($row) || ! filled($this->selectedTabKey) || ! filled($this->selectedSectionKey)) {
            return;
        }

        try {
            $item = $this->editableItem((string) $this->selectedTabKey, (string) $this->selectedSectionKey, $itemKey);
            $item->fill([
                'sort_order' => (int) ($row['sort_order'] ?? 100),
                'is_visible' => (bool) ($row['is_visible'] ?? false),
            ])->save();

            $this->reloadRows();
            $this->notifySuccess('Элемент сохранён');
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось сохранить элемент', $throwable);
        }
    }

    public function saveSelectedItems(): void
    {
        if (! filled($this->selectedTabKey) || ! filled($this->selectedSectionKey)) {
            return;
        }

        try {
            $section = $this->editableSection((string) $this->selectedTabKey, (string) $this->selectedSectionKey);

            foreach ($this->itemRows as $itemKey => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $item = $section->items()
                    ->where('item_key', $itemKey)
                    ->first();

                if (! $item instanceof CardViewItem) {
                    continue;
                }

                $item->fill([
                    'sort_order' => (int) ($row['sort_order'] ?? 100),
                    'is_visible' => (bool) ($row['is_visible'] ?? false),
                ])->save();
            }

            $this->reloadRows();
            $this->notifySuccess('Элементы сохранены');
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось сохранить элементы', $throwable);
        }
    }

    public function moveItemUp(string $itemKey): void
    {
        $this->moveSelectedItem($itemKey, -1);
    }

    public function moveItemDown(string $itemKey): void
    {
        $this->moveSelectedItem($itemKey, 1);
    }

    public function deleteItem(string $itemKey): void
    {
        if (! filled($this->selectedTabKey) || ! filled($this->selectedSectionKey)) {
            return;
        }

        try {
            $item = $this->editableItem((string) $this->selectedTabKey, (string) $this->selectedSectionKey, $itemKey);

            if ($this->isProtectedItem($item)) {
                throw ValidationException::withMessages([
                    'item' => 'Системный элемент можно скрыть, но нельзя удалить.',
                ]);
            }

            $item->delete();
            $this->reloadRows();
            $this->notifySuccess('Элемент удалён');
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось удалить элемент', $throwable);
        }
    }

    public function startMoveItem(string $itemKey): void
    {
        if (! array_key_exists($itemKey, $this->itemRows)) {
            return;
        }

        $this->movingItemKey = $itemKey;
        $this->moveTargetSectionPath = $this->firstMoveTargetSectionPath();
    }

    public function cancelMoveItem(): void
    {
        $this->resetMoveState();
    }

    public function moveItemToTarget(string $itemKey): void
    {
        if (! filled($this->moveTargetSectionPath)) {
            return;
        }

        try {
            [$targetTabKey, $targetSectionKey] = $this->parseSectionPath($this->moveTargetSectionPath);

            if (
                $targetTabKey === (string) $this->selectedTabKey
                && $targetSectionKey === (string) $this->selectedSectionKey
            ) {
                throw ValidationException::withMessages([
                    'section' => 'Элемент уже находится в выбранной секции.',
                ]);
            }

            $targetSection = $this->editableSection($targetTabKey, $targetSectionKey);
            $item = $this->findItemInView($targetSection, $itemKey);

            if (! $item instanceof CardViewItem) {
                throw ValidationException::withMessages([
                    'item' => 'Элемент не найден в текущем виде карточки.',
                ]);
            }

            $item->fill([
                'card_view_section_id' => $targetSection->id,
                'sort_order' => $this->nextItemSortOrder($targetSection),
                'is_visible' => true,
            ])->save();

            $this->selectedTabKey = $targetTabKey;
            $this->selectedSectionKey = $targetSectionKey;
            $this->resetMoveState();
            $this->reloadRows();
            $this->notifySuccess('Элемент перенесён');
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось перенести элемент', $throwable);
        }
    }

    public function addFieldItem(): void
    {
        if (! filled($this->selectedTabKey) || ! filled($this->selectedSectionKey)) {
            return;
        }

        try {
            $fieldId = (int) ($this->newFieldItem['field_id'] ?? 0);
            $field = FieldDictionaryField::query()
                ->where('entity', $this->dictionaryEntity())
                ->findOrFail($fieldId);
            $section = $this->editableSection((string) $this->selectedTabKey, (string) $this->selectedSectionKey);
            $existingItem = $this->findItemInView($section, (string) $field->field_key);

            if ($existingItem instanceof CardViewItem) {
                $existingItem->fill([
                    'card_view_section_id' => $section->id,
                    'item_type' => CardViewItem::TYPE_FIELD,
                    'field_dictionary_field_id' => $field->id,
                    'sort_order' => $this->nextItemSortOrder($section),
                    'is_visible' => true,
                    'is_system' => (bool) $existingItem->is_system || (bool) $field->is_system,
                ])->save();
            } else {
                CardViewItem::query()->create([
                    'card_view_section_id' => $section->id,
                    'item_key' => $field->field_key,
                    'item_type' => CardViewItem::TYPE_FIELD,
                    'field_dictionary_field_id' => $field->id,
                    'sort_order' => $this->nextItemSortOrder($section),
                    'is_visible' => true,
                    'is_system' => (bool) $field->is_system,
                ]);
            }

            $this->newFieldItem = ['field_id' => ''];
            $this->fieldItemSearch = '';
            $this->reloadRows();
            $this->notifySuccess($existingItem instanceof CardViewItem ? 'Поле перенесено' : 'Поле добавлено');
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось добавить поле', $throwable);
        }
    }

    public function addBlockItem(): void
    {
        if (! filled($this->selectedTabKey) || ! filled($this->selectedSectionKey)) {
            return;
        }

        try {
            if (! $this->canAddBlockItems()) {
                throw ValidationException::withMessages([
                    'block_key' => 'Виды карточек настраиваются через поля справочника.',
                ]);
            }

            $blockKey = (string) ($this->newBlockItem['block_key'] ?? '');

            if (! $this->blockRegistry()->contains($blockKey)) {
                throw ValidationException::withMessages([
                    'block_key' => 'Выберите готовый блок из списка.',
                ]);
            }

            $section = $this->editableSection((string) $this->selectedTabKey, (string) $this->selectedSectionKey);
            $existingItem = $this->findItemInView($section, $blockKey);

            if ($existingItem instanceof CardViewItem) {
                $existingItem->fill([
                    'card_view_section_id' => $section->id,
                    'item_type' => CardViewItem::TYPE_BLOCK,
                    'field_dictionary_field_id' => null,
                    'sort_order' => $this->nextItemSortOrder($section),
                    'is_visible' => true,
                ])->save();
            } else {
                CardViewItem::query()->create([
                    'card_view_section_id' => $section->id,
                    'item_key' => $blockKey,
                    'item_type' => CardViewItem::TYPE_BLOCK,
                    'field_dictionary_field_id' => null,
                    'sort_order' => $this->nextItemSortOrder($section),
                    'is_visible' => true,
                    'is_system' => false,
                ]);
            }

            $this->newBlockItem = ['block_key' => ''];
            $this->reloadRows();
            $this->notifySuccess($existingItem instanceof CardViewItem ? 'Блок перенесён' : 'Блок добавлен');
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось добавить блок', $throwable);
        }
    }

    public function restoreStandardView(): void
    {
        try {
            $this->entity === CardView::ENTITY_DIALOG
                ? app(ResetEditableDialogCardViewAction::class)->handle()
                : app(ResetEditableContactCardViewAction::class)->handle();
            $this->selectedTabKey = null;
            $this->selectedSectionKey = null;
            $this->fieldItemSearch = '';
            $this->reloadRows();
            $this->notifySuccess('Стандартный вид восстановлен');
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось восстановить стандартный вид', $throwable);
        }
    }

    public function reloadRows(): void
    {
        $view = $this->activeView();

        if (! $view instanceof CardView) {
            $this->tabRows = [];
            $this->sectionRows = [];
            $this->itemRows = [];

            return;
        }

        $this->tabRows = $view->tabs
            ->mapWithKeys(fn ($tab): array => [
                (string) $tab->tab_key => [
                    'tab_key' => (string) $tab->tab_key,
                    'name' => (string) $tab->name,
                    'sort_order' => (int) $tab->sort_order,
                    'is_visible' => (bool) $tab->is_visible,
                    'is_system' => (bool) $tab->is_system,
                ],
            ])
            ->all();

        if (! filled($this->selectedTabKey) || ! array_key_exists((string) $this->selectedTabKey, $this->tabRows)) {
            $this->selectedTabKey = $this->defaultSelectedTabKey();
        }

        $selectedTab = $view->tabs->firstWhere('tab_key', $this->selectedTabKey);

        $this->sectionRows = $selectedTab?->sections
            ->mapWithKeys(fn ($section): array => [
                (string) $section->section_key => [
                    'tab_key' => (string) $selectedTab->tab_key,
                    'section_key' => (string) $section->section_key,
                    'name' => (string) $section->name,
                    'sort_order' => (int) $section->sort_order,
                    'is_visible' => (bool) $section->is_visible,
                    'is_collapsed_by_default' => (bool) $section->is_collapsed_by_default,
                    'is_system' => (bool) $section->is_system,
                ],
            ])
            ->all() ?? [];

        if (! filled($this->selectedSectionKey) || ! array_key_exists((string) $this->selectedSectionKey, $this->sectionRows)) {
            $this->selectedSectionKey = $this->defaultSelectedSectionKey();
        }

        $selectedSection = $selectedTab?->sections->firstWhere('section_key', $this->selectedSectionKey);
        $blockRegistry = $this->blockRegistry();
        $items = $selectedSection?->items->values() ?? collect();

        $this->itemRows = $items
            ->mapWithKeys(fn (CardViewItem $item, int $index): array => [
                (string) $item->item_key => [
                    'item_key' => (string) $item->item_key,
                    'item_type' => (string) $item->item_type,
                    'item_type_label' => $item->item_type === CardViewItem::TYPE_BLOCK ? 'Блок' : 'Поле',
                    'name' => $item->item_type === CardViewItem::TYPE_BLOCK
                        ? $blockRegistry->label((string) $item->item_key)
                        : (string) ($item->field?->name ?? $item->item_key),
                    'sort_order' => (int) $item->sort_order,
                    'is_visible' => (bool) $item->is_visible,
                    'is_system' => $this->isProtectedItem($item),
                    'can_move_up' => $index > 0,
                    'can_move_down' => $index < $items->count() - 1,
                ],
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function fieldOptions(): array
    {
        return FieldDictionaryField::query()
            ->where('entity', $this->dictionaryEntity())
            ->ordered()
            ->get()
            ->mapWithKeys(fn (FieldDictionaryField $field): array => [
                (int) $field->id => sprintf('%s · %s', $field->name, $field->field_key),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function filteredFieldOptions(): array
    {
        $query = mb_strtolower(trim($this->fieldItemSearch));

        if ($query === '') {
            return $this->fieldOptions();
        }

        return array_filter(
            $this->fieldOptions(),
            fn (string $label): bool => str_contains(mb_strtolower($label), $query),
        );
    }

    public function selectFieldItem(string $fieldId): void
    {
        $fieldId = (int) $fieldId;

        if (! array_key_exists($fieldId, $this->fieldOptions())) {
            return;
        }

        $this->newFieldItem = ['field_id' => (string) $fieldId];
        $this->fieldItemSearch = '';
    }

    public function selectedFieldItemLabel(): string
    {
        $fieldId = (int) ($this->newFieldItem['field_id'] ?? 0);

        if ($fieldId < 1) {
            return sprintf('Поле %s из справочника', $this->entityGenitiveLabel());
        }

        $field = FieldDictionaryField::query()
            ->where('entity', $this->dictionaryEntity())
            ->find($fieldId);

        if (! $field instanceof FieldDictionaryField) {
            return sprintf('Поле %s из справочника', $this->entityGenitiveLabel());
        }

        return sprintf('%s · %s', $field->name, $field->field_key);
    }

    /**
     * @return array<string, string>
     */
    public function blockOptions(): array
    {
        return $this->blockRegistry()->options();
    }

    /**
     * @return array<string, string>
     */
    public function moveSectionOptions(): array
    {
        $view = $this->activeView();

        if (! $view instanceof CardView) {
            return [];
        }

        $options = [];

        foreach ($view->tabs as $tab) {
            foreach ($tab->sections as $section) {
                $path = sprintf('%s|%s', $tab->tab_key, $section->section_key);

                if (
                    (string) $tab->tab_key === (string) $this->selectedTabKey
                    && (string) $section->section_key === (string) $this->selectedSectionKey
                ) {
                    continue;
                }

                $options[$path] = sprintf('%s / %s', $tab->name, $section->name);
            }
        }

        return $options;
    }

    public function canAddBlockItems(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $view = $this->activeView();

        return [
            'entity' => $this->entity,
            'entityOptions' => $this->entityOptions(),
            'entityLabel' => $this->entityLabel(),
            'entityGenitiveLabel' => $this->entityGenitiveLabel(),
            'activeView' => $view,
            'isEditableView' => $view?->view_key === $this->editableViewKey(),
            'previewUrl' => $this->previewUrl(),
        ];
    }

    private function activeView(): ?CardView
    {
        $view = CardView::query()
            ->where('entity', $this->entity)
            ->where('context', CardView::CONTEXT_CARD)
            ->where('is_default', true)
            ->with('tabs.sections.items.field')
            ->first()
            ?? CardView::query()
                ->where('entity', $this->entity)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('view_key', $this->systemViewKey())
                ->with('tabs.sections.items.field')
                ->first();

        if ($view instanceof CardView) {
            $this->normalizeLegacyBlockItems($view);

            return $view->fresh('tabs.sections.items.field');
        }

        return null;
    }

    private function normalizeLegacyBlockItems(CardView $view): void
    {
        $map = $this->legacyBlockFieldMap($view);

        if ($map === []) {
            return;
        }

        $legacyItems = CardViewItem::query()
            ->whereIn('item_key', array_keys($map))
            ->where('item_type', CardViewItem::TYPE_BLOCK)
            ->whereHas('section.tab', fn ($query) => $query->where('card_view_id', $view->id))
            ->with('section')
            ->get();

        if ($legacyItems->isEmpty()) {
            return;
        }

        $fields = FieldDictionaryField::query()
            ->where('entity', $this->dictionaryEntity())
            ->whereIn('field_key', array_values($map))
            ->get()
            ->keyBy('field_key');

        foreach ($legacyItems as $item) {
            $fieldKey = $map[(string) $item->item_key] ?? null;
            $field = $fieldKey !== null ? $fields->get($fieldKey) : null;

            if (! $field instanceof FieldDictionaryField) {
                continue;
            }

            $duplicate = CardViewItem::query()
                ->where('item_key', $fieldKey)
                ->whereHas('section.tab', fn ($query) => $query->where('card_view_id', $view->id))
                ->whereKeyNot($item->getKey())
                ->first();

            if ($duplicate instanceof CardViewItem) {
                $item->delete();

                continue;
            }

            $item->forceFill([
                'item_key' => $fieldKey,
                'item_type' => CardViewItem::TYPE_FIELD,
                'field_dictionary_field_id' => $field->id,
                'is_system' => (bool) $item->is_system || (bool) $field->is_system,
            ])->save();
        }
    }

    /**
     * @return array<string, string>
     */
    private function legacyBlockFieldMap(CardView $view): array
    {
        if ($this->entity === CardView::ENTITY_CONTACT && $view->entity === CardView::ENTITY_CONTACT) {
            return self::CONTACT_LEGACY_BLOCK_FIELD_MAP;
        }

        if ($this->entity === CardView::ENTITY_DIALOG && $view->entity === CardView::ENTITY_DIALOG) {
            return self::DIALOG_LEGACY_BLOCK_FIELD_MAP;
        }

        return [];
    }

    private function editableView(): CardView
    {
        return $this->entity === CardView::ENTITY_DIALOG
            ? app(EnsureEditableDialogCardViewAction::class)->handle()
            : app(EnsureEditableContactCardViewAction::class)->handle();
    }

    private function editableSection(string $tabKey, string $sectionKey): CardViewSection
    {
        return $this->editableView()
            ->tabs()
            ->where('tab_key', $tabKey)
            ->firstOrFail()
            ->sections()
            ->where('section_key', $sectionKey)
            ->firstOrFail();
    }

    private function editableItem(string $tabKey, string $sectionKey, string $itemKey): CardViewItem
    {
        return $this->editableSection($tabKey, $sectionKey)
            ->items()
            ->with('field')
            ->where('item_key', $itemKey)
            ->firstOrFail();
    }

    private function isProtectedItem(CardViewItem $item): bool
    {
        return (bool) $item->is_system
            || (
                $item->item_type === CardViewItem::TYPE_FIELD
                && $item->field instanceof FieldDictionaryField
                && (bool) $item->field->is_system
            );
    }

    private function moveSelectedItem(string $itemKey, int $direction): void
    {
        if (! filled($this->selectedTabKey) || ! filled($this->selectedSectionKey)) {
            return;
        }

        try {
            $section = $this->editableSection((string) $this->selectedTabKey, (string) $this->selectedSectionKey);
            $items = $section->items()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->values();

            $currentIndex = $items->search(fn (CardViewItem $item): bool => $item->item_key === $itemKey);

            if ($currentIndex === false) {
                return;
            }

            $targetIndex = $currentIndex + $direction;

            if (! $items->has($targetIndex)) {
                return;
            }

            $orderedItems = $items->all();
            [$orderedItems[$currentIndex], $orderedItems[$targetIndex]] = [$orderedItems[$targetIndex], $orderedItems[$currentIndex]];

            foreach (array_values($orderedItems) as $index => $item) {
                $item->fill([
                    'sort_order' => ($index + 1) * 10,
                ])->save();
            }

            $this->reloadRows();
            $this->notifySuccess('Порядок элементов обновлён');
        } catch (Throwable $throwable) {
            $this->notifyError('Не удалось изменить порядок элемента', $throwable);
        }
    }

    private function firstMoveTargetSectionPath(): string
    {
        return (string) array_key_first($this->moveSectionOptions());
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function parseSectionPath(string $path): array
    {
        $parts = explode('|', $path, 2);

        if (count($parts) !== 2 || ! filled($parts[0]) || ! filled($parts[1])) {
            throw ValidationException::withMessages([
                'section' => 'Выберите секцию назначения.',
            ]);
        }

        return [(string) $parts[0], (string) $parts[1]];
    }

    private function resetMoveState(): void
    {
        $this->movingItemKey = null;
        $this->moveTargetSectionPath = '';
    }

    private function visibleTabCount(CardView $view, string $changedTabKey): int
    {
        return $view->tabs()
            ->where('tab_key', '!=', $changedTabKey)
            ->where('is_visible', true)
            ->count();
    }

    private function nextItemSortOrder(CardViewSection $section): int
    {
        return ((int) $section->items()->max('sort_order')) + 10;
    }

    private function findItemInView(CardViewSection $section, string $itemKey): ?CardViewItem
    {
        $viewId = $section->tab()->value('card_view_id');

        if ($viewId === null) {
            return null;
        }

        return CardViewItem::query()
            ->where('item_key', $itemKey)
            ->whereHas('section.tab', fn ($query) => $query->where('card_view_id', $viewId))
            ->first();
    }

    private function normalizeEntity(string $entity): string
    {
        return in_array($entity, [CardView::ENTITY_CONTACT, CardView::ENTITY_DIALOG], true)
            ? $entity
            : CardView::ENTITY_CONTACT;
    }

    /**
     * @return array<string, string>
     */
    private function entityOptions(): array
    {
        return [
            CardView::ENTITY_CONTACT => 'Контакт',
            CardView::ENTITY_DIALOG => 'Диалог',
        ];
    }

    private function entityLabel(): string
    {
        return $this->entityOptions()[$this->entity] ?? 'Контакт';
    }

    private function entityGenitiveLabel(): string
    {
        return $this->entity === CardView::ENTITY_DIALOG ? 'диалога' : 'контакта';
    }

    private function dictionaryEntity(): string
    {
        return $this->entity === CardView::ENTITY_DIALOG
            ? FieldDictionaryField::ENTITY_DIALOG
            : FieldDictionaryField::ENTITY_CONTACT;
    }

    private function systemViewKey(): string
    {
        return $this->entity === CardView::ENTITY_DIALOG
            ? SyncSystemDialogCardViewAction::VIEW_KEY
            : SyncSystemContactCardViewAction::VIEW_KEY;
    }

    private function editableViewKey(): string
    {
        return $this->entity === CardView::ENTITY_DIALOG
            ? SyncSystemDialogCardViewAction::EDITABLE_VIEW_KEY
            : SyncSystemContactCardViewAction::EDITABLE_VIEW_KEY;
    }

    private function blockRegistry(): ContactCardViewBlockRegistry|DialogCardViewBlockRegistry
    {
        return $this->entity === CardView::ENTITY_DIALOG
            ? app(DialogCardViewBlockRegistry::class)
            : app(ContactCardViewBlockRegistry::class);
    }

    private function defaultSelectedTabKey(): ?string
    {
        if (
            $this->entity === CardView::ENTITY_DIALOG
            && array_key_exists(SyncSystemDialogCardViewAction::TAB_GENERAL, $this->tabRows)
        ) {
            return SyncSystemDialogCardViewAction::TAB_GENERAL;
        }

        return array_key_first($this->tabRows);
    }

    private function defaultSelectedSectionKey(): ?string
    {
        if (
            $this->entity === CardView::ENTITY_DIALOG
            && $this->selectedTabKey === SyncSystemDialogCardViewAction::TAB_GENERAL
            && array_key_exists(SyncSystemDialogCardViewAction::SECTION_DIALOG_SIDE_DATA, $this->sectionRows)
        ) {
            return SyncSystemDialogCardViewAction::SECTION_DIALOG_SIDE_DATA;
        }

        return array_key_first($this->sectionRows);
    }

    private function previewUrl(): ?string
    {
        if ($this->entity === CardView::ENTITY_DIALOG) {
            $dialogId = Dialog::query()->latest('id')->value('id');

            return $dialogId !== null
                ? DialogResource::getUrl('view', ['record' => $dialogId])
                : null;
        }

        $contactId = Contact::query()->latest('id')->value('id');

        return $contactId !== null
            ? ContactResource::getUrl('view', ['record' => $contactId])
            : null;
    }

    private function nextTabSortOrder(CardView $view): int
    {
        return ((int) $view->tabs()->max('sort_order')) + 10;
    }

    private function nextSectionSortOrder(CardViewTab $tab): int
    {
        return ((int) $tab->sections()->max('sort_order')) + 10;
    }

    private function optionalSortOrder(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? (int) $value : $fallback;
    }

    private function normalizeKey(mixed $value, string $fallbackName, string $message): string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            $raw = Str::slug($fallbackName, '_');
        }

        $key = Str::of($raw)
            ->lower()
            ->replace('-', '_')
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_')
            ->toString();

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw ValidationException::withMessages([
                'key' => $message,
            ]);
        }

        return $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function blankNewTabState(): array
    {
        return [
            'tab_key' => '',
            'name' => '',
            'sort_order' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blankNewSectionState(): array
    {
        return [
            'section_key' => '',
            'name' => '',
            'sort_order' => '',
            'is_collapsed_by_default' => false,
        ];
    }

    private function requiredText(mixed $value, string $message): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            throw ValidationException::withMessages([
                'value' => $message,
            ]);
        }

        return $text;
    }

    private function notifySuccess(string $title): void
    {
        Notification::make()
            ->success()
            ->title($title)
            ->send();
    }

    private function notifyValidationError(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Проверьте настройку')
            ->body(collect($exception->errors())->flatten()->filter()->implode(PHP_EOL))
            ->send();
    }

    private function notifyError(string $title, Throwable $throwable): void
    {
        Notification::make()
            ->danger()
            ->title($title)
            ->body($throwable->getMessage())
            ->send();
    }
}
