<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldDictionaryField extends Model
{
    protected static bool $syncingSystemDefinitions = false;

    public const ENTITY_CONTACT = 'contact';

    public const ENTITY_DIALOG = 'dialog';

    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_SELECT = 'select';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_DATE = 'date';

    public const TYPE_PHONE = 'phone';

    public const TYPE_EMAIL = 'email';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'entity',
        'field_key',
        'name',
        'type',
        'options',
        'source_field_key',
        'sort_order',
        'is_multiple',
        'is_system',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'options' => 'array',
        'sort_order' => 'integer',
        'is_multiple' => 'boolean',
        'is_system' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (FieldDictionaryField $field): void {
            $field->normalizeAttributes();
            $field->guardImmutableFields();
            $field->guardSourceField();
            $field->guardOptions();
        });

        static::deleting(function (FieldDictionaryField $field): void {
            if ($field->is_system) {
                throw ValidationException::withMessages([
                    'field' => 'Системное поле нельзя удалить.',
                ]);
            }

            if ($field->isReferencedAsSource()) {
                throw ValidationException::withMessages([
                    'source_field_key' => 'Нельзя удалить поле, пока другие поля используют его как источник.',
                ]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public static function entityOptions(): array
    {
        return [
            self::ENTITY_CONTACT => 'Контакты',
            self::ENTITY_DIALOG => 'Диалоги',
        ];
    }

    public static function entityLabel(?string $entity): string
    {
        return self::entityOptions()[$entity] ?? (string) $entity;
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_TEXT => 'Текст',
            self::TYPE_NUMBER => 'Число',
            self::TYPE_SELECT => 'Список',
            self::TYPE_BOOLEAN => 'Да/нет',
            self::TYPE_DATE => 'Дата',
            self::TYPE_PHONE => 'Телефон',
            self::TYPE_EMAIL => 'Email',
        ];
    }

    public static function typeLabel(?string $type): string
    {
        return self::typeOptions()[$type] ?? (string) $type;
    }

    /**
     * @return array<string, string>
     */
    public static function labelsFor(string $entity): array
    {
        return static::query()
            ->where('entity', $entity)
            ->pluck('name', 'field_key')
            ->map(fn (mixed $label): string => trim((string) $label))
            ->filter(fn (string $label): bool => $label !== '')
            ->all();
    }

    public static function labelFor(string $entity, string $fieldKey, string $fallback): string
    {
        return static::labelFrom(static::labelsFor($entity), $fieldKey, $fallback);
    }

    /**
     * @param  array<string, string>  $labels
     */
    public static function labelFrom(array $labels, string $fieldKey, string $fallback): string
    {
        $label = trim((string) ($labels[$fieldKey] ?? ''));

        return $label !== '' ? $label : $fallback;
    }

    /**
     * @return array<string, string>
     */
    public static function optionLabelsFor(string $entity, string $fieldKey): array
    {
        $field = static::query()
            ->where('entity', $entity)
            ->where('field_key', $fieldKey)
            ->first(['options', 'type']);

        if (! $field instanceof self || $field->type !== self::TYPE_SELECT) {
            return [];
        }

        return collect(self::normalizeOptions($field->options ?? []))
            ->mapWithKeys(fn (array $option): array => [$option['value'] => $option['label']])
            ->all();
    }

    public static function optionLabelFor(string $entity, string $fieldKey, mixed $value, string $fallback): string
    {
        return static::optionLabelFrom(static::optionLabelsFor($entity, $fieldKey), $value, $fallback);
    }

    /**
     * @param  array<string, string>  $optionLabels
     */
    public static function optionLabelFrom(array $optionLabels, mixed $value, string $fallback): string
    {
        $key = is_scalar($value) ? trim((string) $value) : '';

        if ($key === '') {
            return $fallback;
        }

        $label = trim((string) ($optionLabels[$key] ?? ''));

        return $label !== '' ? $label : $fallback;
    }

    /**
     * @return array{contact: list<array<string, mixed>>, dialog: list<array<string, mixed>>}
     */
    public static function constructorCatalog(): array
    {
        $catalog = [
            self::ENTITY_CONTACT => [],
            self::ENTITY_DIALOG => [],
        ];

        static::query()
            ->whereIn('entity', [self::ENTITY_CONTACT, self::ENTITY_DIALOG])
            ->ordered()
            ->get(['id', 'entity', 'field_key', 'name', 'type', 'options', 'source_field_key', 'sort_order', 'is_multiple', 'is_system'])
            ->each(function (FieldDictionaryField $field) use (&$catalog): void {
                $catalog[$field->entity][] = [
                    'id' => (int) $field->id,
                    'entity' => (string) $field->entity,
                    'key' => (string) $field->field_key,
                    'label' => (string) $field->name,
                    'type' => (string) $field->type,
                    'options' => self::normalizeOptions($field->options ?? []),
                    'source_field_key' => filled($field->source_field_key) ? (string) $field->source_field_key : null,
                    'sort_order' => (int) $field->sort_order,
                    'is_multiple' => (bool) $field->is_multiple,
                    'is_system' => (bool) $field->is_system,
                ];
            });

        return [
            'contact' => $catalog[self::ENTITY_CONTACT],
            'dialog' => $catalog[self::ENTITY_DIALOG],
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy('entity')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id');
    }

    public function isReferencedAsSource(): bool
    {
        if (! filled($this->field_key) || ! filled($this->entity)) {
            return false;
        }

        return static::query()
            ->where('entity', $this->entity)
            ->where('source_field_key', $this->field_key)
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->getKey()))
            ->exists();
    }

    public function optionsSummary(): string
    {
        if ($this->type !== self::TYPE_SELECT) {
            return '—';
        }

        $options = $this->options ?? [];

        if ($options === []) {
            return '—';
        }

        return collect($options)
            ->map(fn (array $option): string => sprintf('%s = %s', $option['value'] ?? '', $option['label'] ?? ''))
            ->filter(fn (string $option): bool => trim($option) !== '=')
            ->take(4)
            ->implode('; ');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function systemDefinitions(): array
    {
        return [
            ...self::contactSystemDefinitions(),
            ...self::dialogSystemDefinitions(),
        ];
    }

    public static function syncSystemDefinitions(): void
    {
        self::$syncingSystemDefinitions = true;

        try {
            DB::transaction(function (): void {
                foreach (self::systemDefinitions() as $definition) {
                    $field = static::query()
                        ->where('entity', $definition['entity'])
                        ->where('field_key', $definition['field_key'])
                        ->first();

                    if (! $field instanceof self) {
                        static::query()->create($definition);

                        continue;
                    }

                    $attributes = [
                        'options' => self::mergeSystemOptions($field->options ?? [], $definition['options'] ?? []),
                        'is_system' => true,
                    ];

                    if (! $field->is_system) {
                        $attributes['type'] = $definition['type'];
                        $attributes['source_field_key'] = $definition['source_field_key'];
                        $attributes['is_multiple'] = (bool) ($definition['is_multiple'] ?? false);
                    }

                    $field->forceFill($attributes)->save();
                }
            });
        } finally {
            self::$syncingSystemDefinitions = false;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function contactSystemDefinitions(): array
    {
        return [
            self::definition(self::ENTITY_CONTACT, 'first_name_source', 'Откуда знаем имя', self::TYPE_SELECT, 11, [
                ['value' => 'auto', 'label' => 'Профиль мессенджера', 'is_system' => true],
                ['value' => 'contact_confirmed', 'label' => 'Клиент подтвердил', 'is_system' => true],
                ['value' => 'manual', 'label' => 'Оператор', 'is_system' => true],
            ]),
            self::definition(self::ENTITY_CONTACT, 'first_name_resolution_method', 'Как обработали имя', self::TYPE_SELECT, 12, [
                ['value' => 'messenger_profile', 'label' => 'Профиль мессенджера', 'is_system' => true],
                ['value' => 'scenario_direct', 'label' => 'Сценарий', 'is_system' => true],
                ['value' => 'operator_manual', 'label' => 'Оператор', 'is_system' => true],
                ['value' => 'dictionary_lookup', 'label' => 'Справочник имён', 'is_system' => true],
                ['value' => 'ai_analysis', 'label' => 'ИИ-анализ', 'is_system' => true],
            ]),
            self::definition(self::ENTITY_CONTACT, 'gender_source', 'Откуда знаем пол', self::TYPE_SELECT, 32, self::sourceOptions()),
            self::definition(self::ENTITY_CONTACT, 'location_source', 'Откуда знаем локацию', self::TYPE_SELECT, 72, self::sourceOptions()),
            self::definition(self::ENTITY_CONTACT, 'id', 'ID', self::TYPE_NUMBER, 1),
            self::definition(self::ENTITY_CONTACT, 'first_name', 'Имя', self::TYPE_TEXT, 10, [], 'first_name_source'),
            self::definition(self::ENTITY_CONTACT, 'last_name', 'Фамилия', self::TYPE_TEXT, 20),
            self::definition(self::ENTITY_CONTACT, 'phones', 'Телефоны', self::TYPE_PHONE, 25, isMultiple: true),
            self::definition(self::ENTITY_CONTACT, 'emails', 'Email', self::TYPE_EMAIL, 26, isMultiple: true),
            self::definition(self::ENTITY_CONTACT, 'gender', 'Пол', self::TYPE_SELECT, 30, [
                ['value' => 'male', 'label' => 'Мужской', 'is_system' => true],
                ['value' => 'female', 'label' => 'Женский', 'is_system' => true],
                ['value' => 'unknown', 'label' => 'Непонятно', 'is_system' => true],
            ], 'gender_source'),
            self::definition(self::ENTITY_CONTACT, 'birth_date', 'Дата рождения', self::TYPE_DATE, 40),
            self::definition(self::ENTITY_CONTACT, 'age_years', 'Возраст', self::TYPE_NUMBER, 42),
            self::definition(self::ENTITY_CONTACT, 'age_range', 'Возрастной диапазон', self::TYPE_SELECT, 45, [
                ['value' => 'under_18', 'label' => 'До 18 лет', 'is_system' => true],
                ['value' => '18_23', 'label' => '18 - 23 года', 'is_system' => true],
                ['value' => '24_29', 'label' => '24 - 29 лет', 'is_system' => true],
                ['value' => '30_39', 'label' => '30 - 39 лет', 'is_system' => true],
                ['value' => 'over_40', 'label' => 'Больше 40 лет', 'is_system' => true],
            ]),
            self::definition(self::ENTITY_CONTACT, 'country', 'Страна', self::TYPE_TEXT, 50, [], 'location_source'),
            self::definition(self::ENTITY_CONTACT, 'region', 'Регион', self::TYPE_TEXT, 60, [], 'location_source'),
            self::definition(self::ENTITY_CONTACT, 'city', 'Город', self::TYPE_TEXT, 70, [], 'location_source'),
            self::definition(self::ENTITY_CONTACT, 'created_at', 'Создан', self::TYPE_DATE, 900),
            self::definition(self::ENTITY_CONTACT, 'updated_at', 'Обновлён', self::TYPE_DATE, 910),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function dialogSystemDefinitions(): array
    {
        return [
            self::definition(self::ENTITY_DIALOG, 'id', 'ID', self::TYPE_NUMBER, 1),
            self::definition(self::ENTITY_DIALOG, 'contact_id', 'Контакт', self::TYPE_NUMBER, 10),
            self::definition(self::ENTITY_DIALOG, 'channel_id', 'Канал', self::TYPE_NUMBER, 20),
            self::definition(self::ENTITY_DIALOG, 'status', 'Статус', self::TYPE_TEXT, 30),
            self::definition(self::ENTITY_DIALOG, 'stage', 'Этап', self::TYPE_SELECT, 40, [
                ['value' => Dialog::STAGE_NEW_DIALOG, 'label' => 'Новый диалог', 'is_system' => true],
                ['value' => Dialog::STAGE_PHONE_RECEIVED, 'label' => 'Телефон получен', 'is_system' => true],
                ['value' => Dialog::STAGE_QUESTIONNAIRE_COMPLETED, 'label' => 'Данные собраны', 'is_system' => true],
                ['value' => Dialog::STAGE_TRANSFERRED_TO_MPL, 'label' => 'МПЛ взял в работу', 'is_system' => true],
                ['value' => Dialog::STAGE_TRANSFERRED_TO_MPP, 'label' => 'Передан в МПП', 'is_system' => true],
            ]),
            self::definition(self::ENTITY_DIALOG, 'current_block_id', 'Текущий блок', self::TYPE_TEXT, 45),
            self::definition(self::ENTITY_DIALOG, 'phone', 'Телефон', self::TYPE_PHONE, 50),
            self::definition(self::ENTITY_DIALOG, 'avatar', 'Аватарка', self::TYPE_TEXT, 60),
            self::definition(self::ENTITY_DIALOG, 'last_message_at', 'Последнее сообщение', self::TYPE_DATE, 100),
            self::definition(self::ENTITY_DIALOG, 'last_inbound_message_at', 'Последнее входящее', self::TYPE_DATE, 105),
            self::definition(self::ENTITY_DIALOG, 'last_outbound_message_at', 'Последнее исходящее', self::TYPE_DATE, 106),
            self::definition(self::ENTITY_DIALOG, 'last_inbound_message_id', 'Последнее входящее', self::TYPE_NUMBER, 110),
            self::definition(self::ENTITY_DIALOG, 'last_outbound_message_id', 'Последнее исходящее', self::TYPE_NUMBER, 120),
            self::definition(self::ENTITY_DIALOG, 'created_at', 'Создан', self::TYPE_DATE, 900),
            self::definition(self::ENTITY_DIALOG, 'updated_at', 'Обновлён', self::TYPE_DATE, 910),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function sourceOptions(): array
    {
        return [
            ['value' => 'client', 'label' => 'Клиент сказал', 'is_system' => true],
            ['value' => 'operator', 'label' => 'Оператор', 'is_system' => true],
            ['value' => 'scenario', 'label' => 'Сценарий', 'is_system' => true],
            ['value' => 'dictionary', 'label' => 'Справочник', 'is_system' => true],
            ['value' => 'ai', 'label' => 'ИИ', 'is_system' => true],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>
     */
    protected static function definition(
        string $entity,
        string $fieldKey,
        string $name,
        string $type,
        int $sortOrder,
        array $options = [],
        ?string $sourceFieldKey = null,
        bool $isMultiple = false,
    ): array {
        return [
            'entity' => $entity,
            'field_key' => $fieldKey,
            'name' => $name,
            'type' => $type,
            'options' => $options,
            'source_field_key' => $sourceFieldKey,
            'sort_order' => $sortOrder,
            'is_multiple' => $isMultiple,
            'is_system' => true,
        ];
    }

    protected function normalizeAttributes(): void
    {
        $this->entity = trim((string) $this->entity);
        $this->field_key = trim((string) $this->field_key);
        $this->name = trim((string) $this->name);
        $this->type = trim((string) $this->type);
        $this->source_field_key = filled($this->source_field_key) ? trim((string) $this->source_field_key) : null;
        $this->sort_order = (int) ($this->sort_order ?? 100);
        $this->is_multiple = (bool) $this->is_multiple;
        $this->is_system = (bool) $this->is_system;
    }

    protected function guardImmutableFields(): void
    {
        if (! $this->exists) {
            if ($this->is_system && ! self::$syncingSystemDefinitions) {
                throw ValidationException::withMessages([
                    'is_system' => 'Системные поля создаются только системным сидером.',
                ]);
            }

            return;
        }

        $original = static::query()->find($this->getKey());

        if (! $original instanceof self) {
            return;
        }

        if ($original->field_key !== $this->field_key) {
            throw ValidationException::withMessages([
                'field_key' => 'Ключ поля нельзя менять после создания.',
            ]);
        }

        if ($original->entity !== $this->entity) {
            throw ValidationException::withMessages([
                'entity' => 'Объект поля нельзя менять после создания.',
            ]);
        }

        if ($original->is_system !== $this->is_system && ! self::$syncingSystemDefinitions) {
            throw ValidationException::withMessages([
                'is_system' => 'Системную метку поля нельзя менять.',
            ]);
        }

        if ($original->is_system && $original->type !== $this->type) {
            throw ValidationException::withMessages([
                'type' => 'Тип системного поля нельзя менять.',
            ]);
        }

        if ($original->is_system && $original->source_field_key !== $this->source_field_key) {
            throw ValidationException::withMessages([
                'source_field_key' => 'Поле источника системного поля нельзя менять.',
            ]);
        }

        if ($original->is_multiple !== $this->is_multiple && ! self::$syncingSystemDefinitions) {
            throw ValidationException::withMessages([
                'is_multiple' => 'Признак множественного поля можно выбрать только при создании поля.',
            ]);
        }
    }

    protected function guardSourceField(): void
    {
        $this->guardBaseValues();

        if (! filled($this->source_field_key)) {
            return;
        }

        if ($this->source_field_key === $this->field_key) {
            throw ValidationException::withMessages([
                'source_field_key' => 'Поле не может ссылаться само на себя как на источник.',
            ]);
        }

        $exists = static::query()
            ->where('entity', $this->entity)
            ->where('field_key', $this->source_field_key)
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->getKey()))
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'source_field_key' => 'Поле источника должно существовать в том же объекте.',
            ]);
        }
    }

    protected function guardBaseValues(): void
    {
        if (! array_key_exists($this->entity, self::entityOptions())) {
            throw ValidationException::withMessages([
                'entity' => 'Неизвестный объект справочника.',
            ]);
        }

        if (! array_key_exists($this->type, self::typeOptions())) {
            throw ValidationException::withMessages([
                'type' => 'Неизвестный тип поля.',
            ]);
        }

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $this->field_key)) {
            throw ValidationException::withMessages([
                'field_key' => 'Ключ поля должен быть латиницей: буквы, цифры и подчёркивания.',
            ]);
        }

        if ($this->name === '') {
            throw ValidationException::withMessages([
                'name' => 'Название поля обязательно.',
            ]);
        }
    }

    protected function guardOptions(): void
    {
        $this->options = $this->type === self::TYPE_SELECT
            ? self::normalizeOptions($this->options ?? [])
            : [];

        if ($this->type !== self::TYPE_SELECT) {
            return;
        }

        if ($this->options === []) {
            throw ValidationException::withMessages([
                'options' => 'Для поля-списка нужны доступные значения.',
            ]);
        }

        $values = collect($this->options)->pluck('value')->all();

        if (count($values) !== count(array_unique($values))) {
            throw ValidationException::withMessages([
                'options' => 'Коды значений внутри одного поля должны быть уникальны.',
            ]);
        }

        $this->guardSystemOptions();
    }

    protected function guardSystemOptions(): void
    {
        if (! $this->exists) {
            return;
        }

        $original = static::query()->find($this->getKey());

        if (! $original instanceof self || ! $original->is_system || $original->type !== self::TYPE_SELECT) {
            return;
        }

        $currentByValue = collect($this->options)->keyBy('value');

        foreach (self::normalizeOptions($original->options ?? []) as $option) {
            if (! (bool) ($option['is_system'] ?? false)) {
                continue;
            }

            $current = $currentByValue->get($option['value']);

            if (! is_array($current)) {
                throw ValidationException::withMessages([
                    'options' => 'Системные значения списка нельзя удалять.',
                ]);
            }

            if (! (bool) ($current['is_system'] ?? false)) {
                throw ValidationException::withMessages([
                    'options' => 'Системную метку значения нельзя менять.',
                ]);
            }
        }

        if (self::$syncingSystemDefinitions) {
            return;
        }

        foreach ($this->options as $option) {
            $originalOption = collect($original->options ?? [])
                ->first(fn (array $candidate): bool => ($candidate['value'] ?? null) === $option['value']);

            if (! $originalOption && (bool) ($option['is_system'] ?? false)) {
                throw ValidationException::withMessages([
                    'options' => 'Новые значения списка создаются как пользовательские.',
                ]);
            }
        }
    }

    /**
     * @param  mixed  $options
     * @return list<array{value:string,label:string,is_system:bool}>
     */
    public static function normalizeOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $normalized = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $value = trim((string) Arr::get($option, 'value', ''));
            $label = trim((string) Arr::get($option, 'label', ''));

            if ($value === '' || $label === '') {
                continue;
            }

            $normalized[] = [
                'value' => $value,
                'label' => $label,
                'is_system' => (bool) Arr::get($option, 'is_system', false),
            ];
        }

        return array_values($normalized);
    }

    /**
     * @param  mixed  $currentOptions
     * @param  mixed  $systemOptions
     * @return list<array{value:string,label:string,is_system:bool}>
     */
    protected static function mergeSystemOptions(mixed $currentOptions, mixed $systemOptions): array
    {
        $current = collect(self::normalizeOptions($currentOptions))
            ->keyBy('value');

        foreach (self::normalizeOptions($systemOptions) as $systemOption) {
            if (! $current->has($systemOption['value'])) {
                $current->put($systemOption['value'], $systemOption);
            }
        }

        return $current->values()->all();
    }
}
