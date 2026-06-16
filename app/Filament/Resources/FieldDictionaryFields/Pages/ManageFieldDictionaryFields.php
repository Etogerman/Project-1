<?php

namespace App\Filament\Resources\FieldDictionaryFields\Pages;

use App\Filament\Resources\FieldDictionaryFields\FieldDictionaryFieldResource;
use App\Models\FieldDictionaryField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Throwable;

class ManageFieldDictionaryFields extends Page
{
    protected static string $resource = FieldDictionaryFieldResource::class;

    protected string $view = 'filament.field-dictionary-fields.pages.manage-field-dictionary-fields';

    #[Url(as: 'entity', history: true, except: FieldDictionaryField::ENTITY_CONTACT)]
    public string $activeEntity = FieldDictionaryField::ENTITY_CONTACT;

    #[Url(as: 'q', history: true, except: '')]
    public string $search = '';

    #[Url(as: 'group', history: true, except: 'all')]
    public string $activeHintGroup = 'all';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $fieldRows = [];

    /**
     * @var array<string, mixed>
     */
    public array $newField = [];

    public ?int $selectedFieldId = null;

    public function mount(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);

        $this->activeEntity = $this->normalizeEntity($this->activeEntity);
        $this->reloadRows();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Справочник полей';
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function hasResourceBreadcrumbs(): bool
    {
        return false;
    }

    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function selectEntity(string $entity): void
    {
        $this->activeEntity = $this->normalizeEntity($entity);
        $this->selectedFieldId = null;
        $this->activeHintGroup = 'all';
        $this->reloadRows();
    }

    public function reloadRows(): void
    {
        $this->fieldRows = FieldDictionaryField::query()
            ->where('entity', $this->activeEntity)
            ->ordered()
            ->get()
            ->mapWithKeys(fn (FieldDictionaryField $field): array => [
                $field->id => $this->fieldToRow($field),
            ])
            ->all();

        if ($this->selectedFieldId !== null && ! array_key_exists($this->selectedFieldId, $this->fieldRows)) {
            $this->selectedFieldId = null;
        }

        $this->newField = $this->blankNewFieldState();
    }

    public function selectField(int $fieldId): void
    {
        if (! array_key_exists($fieldId, $this->fieldRows)) {
            return;
        }

        $this->selectedFieldId = $fieldId;
    }

    public function selectHintGroup(string $group): void
    {
        $this->activeHintGroup = $group === 'all' || array_key_exists($group, FieldDictionaryField::hintGroupOptions())
            ? $group
            : 'all';
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->activeHintGroup = 'all';
    }

    public function closeFieldDrawer(): void
    {
        $this->reloadRows();
        $this->selectedFieldId = null;
    }

    public function saveField(int $fieldId): void
    {
        $field = FieldDictionaryField::query()
            ->where('entity', $this->activeEntity)
            ->findOrFail($fieldId);

        $row = $this->fieldRows[$fieldId] ?? [];

        try {
            $field->fill($this->rowToAttributes($row, $field));
            $field->save();
            $this->reloadRows();

            Notification::make()
                ->success()
                ->title('Поле сохранено')
                ->body(sprintf('Поле «%s» обновлено.', $field->name))
                ->send();
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось сохранить поле')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function createField(): void
    {
        try {
            $field = FieldDictionaryField::query()->create($this->rowToAttributes($this->newField));
            $fieldId = (int) $field->getKey();
            $this->reloadRows();
            $this->selectedFieldId = $fieldId;

            Notification::make()
                ->success()
                ->title('Поле добавлено')
                ->body(sprintf('Поле «%s» создано.', $field->name))
                ->send();
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось добавить поле')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function deleteField(int $fieldId): void
    {
        $field = FieldDictionaryField::query()
            ->where('entity', $this->activeEntity)
            ->findOrFail($fieldId);

        try {
            $name = $field->name;
            $field->delete();
            $this->reloadRows();

            Notification::make()
                ->success()
                ->title('Поле удалено')
                ->body(sprintf('Поле «%s» удалено из справочника.', $name))
                ->send();
        } catch (ValidationException $exception) {
            $this->notifyValidationError($exception);
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось удалить поле')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    /**
     * @return array<string, string>
     */
    public function entityTabs(): array
    {
        return FieldDictionaryField::entityOptions();
    }

    /**
     * @return array<string, string>
     */
    public function typeOptions(): array
    {
        return FieldDictionaryField::typeOptions();
    }

    /**
     * @return array<string, string>
     */
    public function conditionVisibilityOptions(): array
    {
        return FieldDictionaryField::conditionVisibilityOptions();
    }

    /**
     * @return array<string, string>
     */
    public function writeAccessOptions(): array
    {
        return FieldDictionaryField::writeAccessOptions();
    }

    /**
     * @return array<string, string>
     */
    public function hintGroupOptions(): array
    {
        return FieldDictionaryField::hintGroupOptions();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function visibleFieldRows(): array
    {
        $search = mb_strtolower(trim($this->search));

        return collect($this->fieldRows)
            ->when($this->activeHintGroup !== 'all', fn ($rows) => $rows
                ->filter(fn (array $row): bool => ($row['hint_group'] ?? null) === $this->activeHintGroup))
            ->when($search !== '', fn ($rows) => $rows
                ->filter(function (array $row) use ($search): bool {
                    $haystack = mb_strtolower(implode(' ', [
                        $row['field_key'] ?? '',
                        $row['name'] ?? '',
                        $row['type_label'] ?? '',
                        $row['group_label'] ?? '',
                        $row['condition_visibility_label'] ?? '',
                        $row['write_access_label'] ?? '',
                    ]));

                    return str_contains($haystack, $search);
                }))
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public function hintGroupCounts(): array
    {
        return collect($this->fieldRows)
            ->countBy(fn (array $row): string => (string) ($row['hint_group'] ?? ''))
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function sourceOptions(?int $fieldId = null): array
    {
        $currentFieldKey = $fieldId !== null
            ? (string) Arr::get($this->fieldRows, "{$fieldId}.field_key", '')
            : (string) Arr::get($this->newField, 'field_key', '');

        return FieldDictionaryField::query()
            ->where('entity', $this->activeEntity)
            ->when($currentFieldKey !== '', fn ($query) => $query->where('field_key', '!=', $currentFieldKey))
            ->ordered()
            ->pluck('name', 'field_key')
            ->all();
    }

    protected function normalizeEntity(string $entity): string
    {
        return array_key_exists($entity, FieldDictionaryField::entityOptions())
            ? $entity
            : FieldDictionaryField::ENTITY_CONTACT;
    }

    /**
     * @return array<string, mixed>
     */
    protected function blankNewFieldState(): array
    {
        $lastSortOrder = FieldDictionaryField::query()
            ->where('entity', $this->activeEntity)
            ->max('sort_order');

        return [
            'entity' => $this->activeEntity,
            'field_key' => '',
            'name' => '',
            'type' => FieldDictionaryField::TYPE_TEXT,
            'source_field_key' => '',
            'options_text' => '',
            'sort_order' => ((int) $lastSortOrder) + 10,
            'is_multiple' => false,
            'is_system' => false,
            'condition_visibility' => $this->activeEntity === FieldDictionaryField::ENTITY_DIALOG
                ? FieldDictionaryField::CONDITION_VISIBILITY_MAIN
                : FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY,
            'write_access' => $this->activeEntity === FieldDictionaryField::ENTITY_DIALOG
                ? FieldDictionaryField::WRITE_ACCESS_WRITABLE
                : FieldDictionaryField::WRITE_ACCESS_READ_ONLY,
            'hint_group' => $this->activeEntity === FieldDictionaryField::ENTITY_DIALOG
                ? FieldDictionaryField::HINT_GROUP_DIALOG
                : FieldDictionaryField::HINT_GROUP_CONTACT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fieldToRow(FieldDictionaryField $field): array
    {
        return [
            'id' => $field->id,
            'entity' => $field->entity,
            'field_key' => $field->field_key,
            'name' => $field->name,
            'type' => $field->type,
            'type_label' => FieldDictionaryField::typeLabel($field->type),
            'group_label' => FieldDictionaryField::hintGroupLabel($field->hint_group),
            'condition_visibility' => $field->condition_visibility,
            'condition_visibility_label' => FieldDictionaryField::conditionVisibilityLabel($field->condition_visibility),
            'write_access' => $field->write_access,
            'write_access_label' => FieldDictionaryField::writeAccessLabel($field->write_access),
            'hint_group' => $field->hint_group,
            'source_label' => filled($field->source_field_key) ? 'Да' : 'Нет',
            'source_field_label' => $this->resolveSourceLabel($field),
            'source_field_key' => $field->source_field_key ?? '',
            'options_text' => $this->formatOptionsText($field->options ?? []),
            'sort_order' => $field->sort_order,
            'is_multiple' => (bool) $field->is_multiple,
            'is_system' => (bool) $field->is_system,
            'is_referenced_as_source' => $field->isReferencedAsSource(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function rowToAttributes(array $row, ?FieldDictionaryField $field = null): array
    {
        $type = (string) ($row['type'] ?? FieldDictionaryField::TYPE_TEXT);
        $entity = $field?->entity ?? $this->activeEntity;
        $isSystem = (bool) ($field?->is_system ?? false);
        $isUserContactField = $entity === FieldDictionaryField::ENTITY_CONTACT && ! $isSystem;

        return [
            'entity' => $entity,
            'field_key' => $field?->field_key ?? trim((string) ($row['field_key'] ?? '')),
            'name' => trim((string) ($row['name'] ?? '')),
            'type' => $isSystem ? $field->type : $type,
            'source_field_key' => $isSystem
                ? $field->source_field_key
                : (filled($row['source_field_key'] ?? null) ? trim((string) $row['source_field_key']) : null),
            'options' => $type === FieldDictionaryField::TYPE_SELECT
                ? $this->parseOptionsText((string) ($row['options_text'] ?? ''), $field)
                : [],
            'sort_order' => (int) ($row['sort_order'] ?? 100),
            'is_multiple' => $field instanceof FieldDictionaryField
                ? (bool) $field->is_multiple
                : (bool) ($row['is_multiple'] ?? false),
            'is_system' => $isSystem,
            'condition_visibility' => $isSystem
                ? $field->condition_visibility
                : ($isUserContactField
                    ? FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY
                    : (string) ($row['condition_visibility'] ?? FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY)),
            'write_access' => $isSystem
                ? $field->write_access
                : ($isUserContactField
                    ? FieldDictionaryField::WRITE_ACCESS_READ_ONLY
                    : (string) ($row['write_access'] ?? FieldDictionaryField::WRITE_ACCESS_READ_ONLY)),
            'hint_group' => $isSystem
                ? $field->hint_group
                : ($isUserContactField
                    ? FieldDictionaryField::HINT_GROUP_CONTACT
                    : (string) ($row['hint_group'] ?? FieldDictionaryField::defaultHintGroup($entity, (string) ($row['field_key'] ?? '')))),
        ];
    }

    protected function resolveSourceLabel(FieldDictionaryField $field): string
    {
        if (! filled($field->source_field_key)) {
            return 'Нет';
        }

        return FieldDictionaryField::query()
            ->where('entity', $field->entity)
            ->where('field_key', $field->source_field_key)
            ->value('name') ?? (string) $field->source_field_key;
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    protected function formatOptionsText(array $options): string
    {
        return collect(FieldDictionaryField::normalizeOptions($options))
            ->map(fn (array $option): string => sprintf('%s = %s', $option['value'], $option['label']))
            ->implode(PHP_EOL);
    }

    /**
     * @return list<array{value:string,label:string,is_system:bool}>
     */
    protected function parseOptionsText(string $text, ?FieldDictionaryField $field = null): array
    {
        $systemByValue = collect($field?->options ?? [])
            ->filter(fn (array $option): bool => (bool) ($option['is_system'] ?? false))
            ->keyBy('value');

        return collect(preg_split('/\R/u', $text) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->map(function (string $line) use ($systemByValue): array {
                [$value, $label] = str_contains($line, '=')
                    ? array_map('trim', explode('=', $line, 2))
                    : [trim($line), trim($line)];

                return [
                    'value' => $value,
                    'label' => $label,
                    'is_system' => (bool) ($systemByValue->get($value)['is_system'] ?? false),
                ];
            })
            ->values()
            ->all();
    }

    protected function notifyValidationError(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Проверьте поле')
            ->body(collect($exception->errors())->flatten()->filter()->implode(PHP_EOL))
            ->send();
    }
}
