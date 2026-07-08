<?php

namespace App\Models;

use App\Services\Dialogs\DialogStageCatalog;
use App\Services\Scenarios\EngineFieldRegistry;
use App\Services\Scenarios\FieldDictionaryEngineSupport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public const CONDITION_VISIBILITY_MAIN = 'main';

    public const CONDITION_VISIBILITY_SYSTEM = 'system';

    public const CONDITION_VISIBILITY_DISPLAY_ONLY = 'display_only';

    public const WRITE_ACCESS_WRITABLE = 'writable';

    public const WRITE_ACCESS_READ_ONLY = 'read_only';

    public const WRITE_ACCESS_SYSTEM_ONLY = 'system_only';

    public const MANUAL_WRITE_ACCESS_EDITABLE = 'editable';

    public const MANUAL_WRITE_ACCESS_READONLY = 'readonly';

    public const SCENARIO_WRITE_ACCESS_ALLOWED = 'allowed';

    public const SCENARIO_WRITE_ACCESS_DENIED = 'denied';

    public const VALUE_OWNER_OPERATOR = 'operator';

    public const VALUE_OWNER_CLIENT = 'client';

    public const VALUE_OWNER_SCENARIO = 'scenario';

    public const VALUE_OWNER_SYSTEM = 'system';

    public const VALUE_OWNER_INTEGRATION = 'integration';

    public const VALUE_OWNER_COMPUTED = 'computed';

    public const HINT_GROUP_CONTACT = 'contact';

    public const HINT_GROUP_DIALOG = 'dialog';

    public const HINT_GROUP_QUESTIONNAIRE = 'questionnaire';

    public const HINT_GROUP_GEO = 'geo';

    public const HINT_GROUP_BITRIX24 = 'bitrix24';

    public const HINT_GROUP_SYSTEM = 'system';

    public const CARD_DISPLAY_VALUE = 'value';

    public const CARD_DISPLAY_PHONE_LIST = 'phone_list';

    public const CARD_DISPLAY_EMAIL_LIST = 'email_list';

    public const CARD_DISPLAY_TAG_LIST = 'tag_list';

    public const CARD_DISPLAY_CONTACT_DIALOGS = 'contact_dialogs';

    public const CARD_DISPLAY_CONTACT_HISTORY = 'contact_history';

    public const CARD_DISPLAY_CONTACT_DEDUP = 'contact_dedup';

    public const CARD_DISPLAY_CONTACT_DIAGNOSTICS = 'contact_diagnostics';

    public const CARD_DISPLAY_DIALOG_PEER_SYNC = 'dialog_peer_sync';

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
        'condition_visibility',
        'write_access',
        'manual_write_access',
        'scenario_write_access',
        'value_owner',
        'hint_group',
        'card_display_type',
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

            if (class_exists(CardViewItem::class) && CardViewItem::query()->where('field_dictionary_field_id', $field->id)->exists()) {
                throw ValidationException::withMessages([
                    'field' => 'Нельзя удалить поле, пока оно используется в виде карточки.',
                ]);
            }

            if ($field->isUsedByPublishedScenario()) {
                throw ValidationException::withMessages([
                    'field' => 'Нельзя удалить поле, пока оно используется в опубликованном сценарии.',
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
    public static function conditionVisibilityOptions(): array
    {
        return [
            self::CONDITION_VISIBILITY_MAIN => 'В обычных условиях',
            self::CONDITION_VISIBILITY_SYSTEM => 'В системных полях',
            self::CONDITION_VISIBILITY_DISPLAY_ONLY => 'Только отображение',
        ];
    }

    public static function conditionVisibilityLabel(?string $value): string
    {
        return self::conditionVisibilityOptions()[$value] ?? (string) $value;
    }

    /**
     * @return array<string, string>
     */
    public static function writeAccessOptions(): array
    {
        return [
            self::WRITE_ACCESS_WRITABLE => 'Можно изменить',
            self::WRITE_ACCESS_READ_ONLY => 'Только чтение',
            self::WRITE_ACCESS_SYSTEM_ONLY => 'Только система',
        ];
    }

    public static function writeAccessLabel(?string $value): string
    {
        return self::writeAccessOptions()[$value] ?? (string) $value;
    }

    /**
     * @return array<string, string>
     */
    public static function manualWriteAccessOptions(): array
    {
        return [
            self::MANUAL_WRITE_ACCESS_EDITABLE => 'Можно',
            self::MANUAL_WRITE_ACCESS_READONLY => 'Нельзя',
        ];
    }

    public static function manualWriteAccessLabel(?string $value): string
    {
        return self::manualWriteAccessOptions()[$value] ?? (string) $value;
    }

    /**
     * @return array<string, string>
     */
    public static function scenarioWriteAccessOptions(): array
    {
        return [
            self::SCENARIO_WRITE_ACCESS_ALLOWED => 'Можно',
            self::SCENARIO_WRITE_ACCESS_DENIED => 'Нельзя',
        ];
    }

    public static function scenarioWriteAccessLabel(?string $value): string
    {
        return self::scenarioWriteAccessOptions()[$value] ?? (string) $value;
    }

    /**
     * @return array<string, string>
     */
    public static function valueOwnerOptions(): array
    {
        return [
            self::VALUE_OWNER_OPERATOR => 'Оператор',
            self::VALUE_OWNER_CLIENT => 'Клиент',
            self::VALUE_OWNER_SCENARIO => 'Сценарий',
            self::VALUE_OWNER_SYSTEM => 'Система',
            self::VALUE_OWNER_INTEGRATION => 'Интеграция',
            self::VALUE_OWNER_COMPUTED => 'Вычисляется',
        ];
    }

    public static function valueOwnerLabel(?string $value): string
    {
        return self::valueOwnerOptions()[$value] ?? (string) $value;
    }

    public static function legacyWriteAccessForScenario(string $scenarioWriteAccess, string $previousWriteAccess = self::WRITE_ACCESS_READ_ONLY): string
    {
        return self::legacyWriteAccessFromScenario($scenarioWriteAccess, $previousWriteAccess);
    }

    /**
     * @return array<string, string>
     */
    public static function hintGroupOptions(): array
    {
        return [
            self::HINT_GROUP_CONTACT => 'Контакт',
            self::HINT_GROUP_DIALOG => 'Диалог',
            self::HINT_GROUP_QUESTIONNAIRE => 'Анкета',
            self::HINT_GROUP_GEO => 'География',
            self::HINT_GROUP_BITRIX24 => 'Битрикс24',
            self::HINT_GROUP_SYSTEM => 'Системные поля',
        ];
    }

    public static function hintGroupLabel(?string $value): string
    {
        return self::hintGroupOptions()[$value] ?? (string) $value;
    }

    /**
     * @return array<string, string>
     */
    public static function cardDisplayTypeOptions(): array
    {
        return [
            self::CARD_DISPLAY_VALUE => 'Обычное значение',
            self::CARD_DISPLAY_PHONE_LIST => 'Список телефонов',
            self::CARD_DISPLAY_EMAIL_LIST => 'Список email',
            self::CARD_DISPLAY_TAG_LIST => 'Список тегов',
            self::CARD_DISPLAY_CONTACT_DIALOGS => 'Диалоги контакта',
            self::CARD_DISPLAY_CONTACT_HISTORY => 'История контакта',
            self::CARD_DISPLAY_CONTACT_DEDUP => 'Склейки контакта',
            self::CARD_DISPLAY_CONTACT_DIAGNOSTICS => 'Диагностика контакта',
            self::CARD_DISPLAY_DIALOG_PEER_SYNC => 'Загрузка истории диалога',
        ];
    }

    public static function cardDisplayTypeLabel(?string $value): string
    {
        return self::cardDisplayTypeOptions()[$value] ?? (string) $value;
    }

    public static function isValidDialogUserFieldKey(string $key): bool
    {
        if ($key === '' || mb_strlen($key) > 64) {
            return false;
        }

        if (in_array($key, ['__proto__', 'constructor', 'prototype'], true)) {
            return false;
        }

        return preg_match('/^(?!_)[\p{L}][\p{L}\p{N}_]{0,63}$/u', $key) === 1;
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

        if ($entity === self::ENTITY_DIALOG && $fieldKey === 'stage') {
            return collect(self::dialogStageOptionsWithDictionaryOverrides($field?->options ?? []))
                ->mapWithKeys(fn (array $option): array => [$option['value'] => $option['label']])
                ->all();
        }

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
            ->get([
                'id',
                'entity',
                'field_key',
                'name',
                'type',
                'options',
                'source_field_key',
                'sort_order',
                'is_multiple',
                'is_system',
                'condition_visibility',
                'write_access',
                'manual_write_access',
                'scenario_write_access',
                'value_owner',
                'hint_group',
            ])
            ->each(function (FieldDictionaryField $field) use (&$catalog): void {
                $support = app(FieldDictionaryEngineSupport::class)->supportFor($field);
                $options = $field->entity === self::ENTITY_DIALOG && $field->field_key === 'stage'
                    ? self::dialogStageOptionsWithDictionaryOverrides($field->options ?? [])
                    : self::normalizeOptions($field->options ?? []);

                $catalog[$field->entity][] = [
                    'id' => (int) $field->id,
                    'entity' => (string) $field->entity,
                    'key' => (string) $field->field_key,
                    'label' => (string) $field->name,
                    'type' => (string) $field->type,
                    'options' => $options,
                    'source_field_key' => filled($field->source_field_key) ? (string) $field->source_field_key : null,
                    'sort_order' => (int) $field->sort_order,
                    'is_multiple' => (bool) $field->is_multiple,
                    'is_system' => (bool) $field->is_system,
                    'condition_visibility' => (string) $field->condition_visibility,
                    'write_access' => (string) $field->write_access,
                    'manual_write_access' => (string) ($field->manual_write_access ?? self::defaultManualWriteAccessForModel($field)),
                    'scenario_write_access' => (string) ($field->scenario_write_access ?? self::defaultScenarioWriteAccessForModel($field)),
                    'value_owner' => (string) ($field->value_owner ?? self::defaultValueOwnerForModel($field)),
                    'hint_group' => (string) $field->hint_group,
                    'hint_group_label' => self::hintGroupLabel($field->hint_group),
                    'condition_supported' => $support['condition_supported'],
                    'field_condition_supported' => $support['field_condition_supported'],
                    'write_supported' => $support['write_supported'],
                    'prompt_variable_supported' => $support['prompt_variable_supported'],
                    'condition_unavailable_reason' => $support['condition_unavailable_reason'],
                    'write_unavailable_reason' => $support['write_unavailable_reason'],
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

    public function isUsedByPublishedScenario(): bool
    {
        if (! filled($this->field_key) || ! filled($this->entity)) {
            return false;
        }

        $used = false;
        $entity = (string) $this->entity;
        $fieldKey = (string) $this->field_key;

        ScenarioVersion::query()
            ->where('status', ScenarioVersion::STATUS_PUBLISHED)
            ->select(['id', 'schema_payload'])
            ->chunkById(50, function ($versions) use (&$used, $entity, $fieldKey): bool {
                foreach ($versions as $version) {
                    if ($this->scenarioPayloadUsesField($version->schema_payload, $entity, $fieldKey)) {
                        $used = true;

                        return false;
                    }
                }

                return true;
            });

        return $used;
    }

    private function scenarioPayloadUsesField(mixed $payload, string $entity, string $fieldKey): bool
    {
        if (is_string($payload)) {
            return $this->scenarioStringUsesField($payload, $entity, $fieldKey);
        }

        if (! is_array($payload)) {
            return false;
        }

        if ($this->scenarioArrayUsesStructuredField($payload, $entity, $fieldKey)) {
            return true;
        }

        foreach ($payload as $value) {
            if ($this->scenarioPayloadUsesField($value, $entity, $fieldKey)) {
                return true;
            }
        }

        return false;
    }

    private function scenarioStringUsesField(string $value, string $entity, string $fieldKey): bool
    {
        return preg_match(
            '/{{\s*'.preg_quote($entity, '/').'\s*\.\s*'.preg_quote($fieldKey, '/').'(?:\|[^}]*)?\s*}}/u',
            $value,
        ) === 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function scenarioArrayUsesStructuredField(array $payload, string $entity, string $fieldKey): bool
    {
        if (($payload['field_scope'] ?? null) === $entity && ($payload['field_key'] ?? null) === $fieldKey) {
            return true;
        }

        if (($payload['target_scope'] ?? null) === $entity && ($payload['target_field'] ?? null) === $fieldKey) {
            return true;
        }

        if (($payload['source_scope'] ?? null) === $entity && ($payload['source_field_key'] ?? null) === $fieldKey) {
            return true;
        }

        if ($entity !== self::ENTITY_DIALOG) {
            return false;
        }

        if (($payload['variable_key'] ?? null) === $fieldKey) {
            return true;
        }

        if (($payload['type'] ?? null) === 'simulate_start_parameter' && ($payload['source_field_key'] ?? null) === $fieldKey) {
            return true;
        }

        foreach (['city_field_key', 'region_field_key', 'country_field_key'] as $key) {
            if (($payload[$key] ?? null) === $fieldKey) {
                return true;
            }
        }

        $operations = $payload['operations'] ?? null;

        if (($payload['type'] ?? null) !== 'variables' || ! is_array($operations)) {
            return false;
        }

        foreach ($operations as $operation) {
            if (is_array($operation) && ($operation['field_key'] ?? null) === $fieldKey) {
                return true;
            }
        }

        return false;
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
                        static::query()->create(self::filterDefinitionForCurrentSchema($definition));

                        continue;
                    }

                    $attributes = [
                        'options' => self::mergeSystemOptions($field->options ?? [], $definition['options'] ?? []),
                        'is_system' => true,
                        'condition_visibility' => $definition['condition_visibility'],
                        'write_access' => $definition['write_access'],
                        'hint_group' => $definition['hint_group'],
                        'card_display_type' => $definition['card_display_type'],
                    ];

                    if (self::supportsSeparatedWriteAccess()) {
                        $attributes['manual_write_access'] = $definition['manual_write_access'];
                        $attributes['scenario_write_access'] = $definition['scenario_write_access'];
                        $attributes['value_owner'] = $definition['value_owner'];
                    }

                    if (! $field->is_system) {
                        $attributes['type'] = $definition['type'];
                        $attributes['source_field_key'] = $definition['source_field_key'];
                        $attributes['is_multiple'] = (bool) ($definition['is_multiple'] ?? false);
                    }

                    $field->forceFill(self::filterDefinitionForCurrentSchema($attributes))->save();
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
            self::definition(self::ENTITY_CONTACT, 'region_source', 'Источник региона', self::TYPE_SELECT, 62, self::regionSourceOptions()),
            self::definition(self::ENTITY_CONTACT, 'id', 'ID', self::TYPE_NUMBER, 1),
            self::definition(self::ENTITY_CONTACT, 'first_name', 'Имя', self::TYPE_TEXT, 10, [], 'first_name_source'),
            self::definition(self::ENTITY_CONTACT, 'last_name', 'Фамилия', self::TYPE_TEXT, 20),
            self::definition(self::ENTITY_CONTACT, 'phone', 'Основной телефон', self::TYPE_PHONE, 24),
            self::definition(self::ENTITY_CONTACT, 'phones', 'Телефоны', self::TYPE_PHONE, 25, isMultiple: true, cardDisplayType: self::CARD_DISPLAY_PHONE_LIST),
            self::definition(self::ENTITY_CONTACT, 'emails', 'Email', self::TYPE_EMAIL, 26, isMultiple: true, cardDisplayType: self::CARD_DISPLAY_EMAIL_LIST),
            self::definition(self::ENTITY_CONTACT, 'gender', 'Пол', self::TYPE_SELECT, 30, [
                ['value' => 'male', 'label' => 'Мужской', 'is_system' => true],
                ['value' => 'female', 'label' => 'Женский', 'is_system' => true],
                ['value' => 'unknown', 'label' => 'Непонятно', 'is_system' => true],
            ], 'gender_source'),
            self::definition(self::ENTITY_CONTACT, 'birth_date', 'Дата рождения', self::TYPE_DATE, 40),
            self::definition(self::ENTITY_CONTACT, 'age_years', 'Возраст', self::TYPE_NUMBER, 42),
            self::definition(self::ENTITY_CONTACT, 'effective_age_years', 'Возраст', self::TYPE_NUMBER, 43, conditionVisibility: self::CONDITION_VISIBILITY_DISPLAY_ONLY),
            self::definition(self::ENTITY_CONTACT, 'age_range', 'Возрастной диапазон', self::TYPE_SELECT, 45, [
                ['value' => 'under_18', 'label' => 'До 18 лет', 'is_system' => true],
                ['value' => '18_23', 'label' => '18 - 23 года', 'is_system' => true],
                ['value' => '24_29', 'label' => '24 - 29 лет', 'is_system' => true],
                ['value' => '30_39', 'label' => '30 - 39 лет', 'is_system' => true],
                ['value' => 'over_40', 'label' => 'Больше 40 лет', 'is_system' => true],
            ]),
            self::definition(self::ENTITY_CONTACT, 'country', 'Страна', self::TYPE_TEXT, 50, [], 'region_source'),
            self::definition(self::ENTITY_CONTACT, 'region', 'Регион', self::TYPE_TEXT, 60, [], 'region_source'),
            self::definition(self::ENTITY_CONTACT, 'region_status', 'Статус региона', self::TYPE_SELECT, 61, self::optionsFrom(Contact::regionStatusOptions())),
            self::definition(self::ENTITY_CONTACT, 'city', 'Город', self::TYPE_TEXT, 70, [], 'region_source'),
            self::definition(self::ENTITY_CONTACT, 'pending_region_candidates', 'Кандидаты региона', self::TYPE_TEXT, 72, conditionVisibility: self::CONDITION_VISIBILITY_DISPLAY_ONLY, hintGroup: self::HINT_GROUP_GEO),
            self::definition(self::ENTITY_CONTACT, 'distance_to_moscow_km', 'Расстояние до Москвы, км', self::TYPE_NUMBER, 80),
            self::definition(self::ENTITY_CONTACT, 'distance_to_moscow_status', 'Статус расчёта расстояния', self::TYPE_SELECT, 82, self::optionsFrom(Contact::distanceToMoscowStatusOptions())),
            self::definition(self::ENTITY_CONTACT, 'distance_to_moscow_calculated_at', 'Расстояние рассчитано', self::TYPE_DATE, 84),
            self::definition(self::ENTITY_CONTACT, 'data_collection_status', 'Статус анкеты', self::TYPE_SELECT, 100, [
                ['value' => Contact::DATA_COLLECTION_STATUS_ACTIVE, 'label' => 'Активна', 'is_system' => true],
                ['value' => Contact::DATA_COLLECTION_STATUS_COMPLETED, 'label' => 'Завершена', 'is_system' => true],
            ]),
            self::definition(self::ENTITY_CONTACT, 'data_collection_current_field', 'Текущее поле анкеты', self::TYPE_TEXT, 102),
            self::definition(self::ENTITY_CONTACT, 'data_collection_last_prompted_field', 'Последнее запрошенное поле анкеты', self::TYPE_TEXT, 104),
            self::definition(self::ENTITY_CONTACT, 'data_collection_started_at', 'Анкета начата', self::TYPE_DATE, 106),
            self::definition(self::ENTITY_CONTACT, 'data_collection_current_field_started_at', 'Текущий вопрос начат', self::TYPE_DATE, 108),
            self::definition(self::ENTITY_CONTACT, 'data_collection_completed_at', 'Анкета завершена', self::TYPE_DATE, 110),
            self::definition(self::ENTITY_CONTACT, 'data_collection_attempts_count', 'Количество попыток анкеты', self::TYPE_NUMBER, 112),
            self::definition(self::ENTITY_CONTACT, 'is_auto_reply_enabled', 'Автоответы включены', self::TYPE_BOOLEAN, 130),
            self::definition(self::ENTITY_CONTACT, 'auto_reply_category', 'Категория автоответа', self::TYPE_TEXT, 132, conditionVisibility: self::CONDITION_VISIBILITY_DISPLAY_ONLY),
            self::definition(self::ENTITY_CONTACT, 'has_blocked_bot_dialog', 'Заблокирован клиентом', self::TYPE_BOOLEAN, 134, conditionVisibility: self::CONDITION_VISIBILITY_DISPLAY_ONLY),
            self::definition(self::ENTITY_CONTACT, 'tags', 'Теги', self::TYPE_TEXT, 136, isMultiple: true, conditionVisibility: self::CONDITION_VISIBILITY_DISPLAY_ONLY, cardDisplayType: self::CARD_DISPLAY_TAG_LIST),
            self::definition(self::ENTITY_CONTACT, 'contact_dialogs', 'Диалоги', self::TYPE_TEXT, 137, isMultiple: true, conditionVisibility: self::CONDITION_VISIBILITY_DISPLAY_ONLY, writeAccess: self::WRITE_ACCESS_READ_ONLY, cardDisplayType: self::CARD_DISPLAY_CONTACT_DIALOGS),
            self::definition(self::ENTITY_CONTACT, 'contact_history', 'История', self::TYPE_TEXT, 138, isMultiple: true, conditionVisibility: self::CONDITION_VISIBILITY_DISPLAY_ONLY, writeAccess: self::WRITE_ACCESS_READ_ONLY, cardDisplayType: self::CARD_DISPLAY_CONTACT_HISTORY),
            self::definition(self::ENTITY_CONTACT, 'contact_dedup', 'Склейки', self::TYPE_TEXT, 139, isMultiple: true, conditionVisibility: self::CONDITION_VISIBILITY_DISPLAY_ONLY, writeAccess: self::WRITE_ACCESS_READ_ONLY, cardDisplayType: self::CARD_DISPLAY_CONTACT_DEDUP),
            self::definition(self::ENTITY_CONTACT, 'assigned_user_id', 'Ответственный', self::TYPE_NUMBER, 140),
            self::definition(self::ENTITY_CONTACT, 'contact_diagnostics', 'Диагностика', self::TYPE_TEXT, 142, isMultiple: true, conditionVisibility: self::CONDITION_VISIBILITY_DISPLAY_ONLY, writeAccess: self::WRITE_ACCESS_READ_ONLY, hintGroup: self::HINT_GROUP_SYSTEM, cardDisplayType: self::CARD_DISPLAY_CONTACT_DIAGNOSTICS),
            self::definition(self::ENTITY_CONTACT, 'duplicate_review_status', 'Статус проверки дубля', self::TYPE_SELECT, 600, [
                ['value' => Contact::DUPLICATE_REVIEW_STATUS_NONE, 'label' => 'Нет проверки', 'is_system' => true],
                ['value' => Contact::DUPLICATE_REVIEW_STATUS_PENDING, 'label' => 'Нужна проверка', 'is_system' => true],
                ['value' => Contact::DUPLICATE_REVIEW_STATUS_RESOLVED, 'label' => 'Разобрано', 'is_system' => true],
            ], conditionVisibility: self::CONDITION_VISIBILITY_SYSTEM, writeAccess: self::WRITE_ACCESS_SYSTEM_ONLY, hintGroup: self::HINT_GROUP_SYSTEM),
            self::definition(self::ENTITY_CONTACT, 'merged_into_contact_id', 'Основной контакт', self::TYPE_NUMBER, 602, conditionVisibility: self::CONDITION_VISIBILITY_SYSTEM, writeAccess: self::WRITE_ACCESS_SYSTEM_ONLY, hintGroup: self::HINT_GROUP_SYSTEM),
            self::definition(self::ENTITY_CONTACT, 'merged_at', 'Склеен', self::TYPE_DATE, 604, conditionVisibility: self::CONDITION_VISIBILITY_SYSTEM, writeAccess: self::WRITE_ACCESS_SYSTEM_ONLY, hintGroup: self::HINT_GROUP_SYSTEM),
            self::definition(self::ENTITY_CONTACT, 'merge_reason', 'Причина склейки', self::TYPE_SELECT, 606, [
                ['value' => 'phone_exact_match', 'label' => 'Совпадение телефона', 'is_system' => true],
                ['value' => 'cross_channel_identity_resolution', 'label' => 'Разрешение cross-channel identity ambiguity', 'is_system' => true],
            ], conditionVisibility: self::CONDITION_VISIBILITY_SYSTEM, writeAccess: self::WRITE_ACCESS_SYSTEM_ONLY, hintGroup: self::HINT_GROUP_SYSTEM),
            self::definition(self::ENTITY_CONTACT, 'merge_trigger_phone', 'Триггерный телефон', self::TYPE_PHONE, 608, conditionVisibility: self::CONDITION_VISIBILITY_SYSTEM, writeAccess: self::WRITE_ACCESS_SYSTEM_ONLY, hintGroup: self::HINT_GROUP_SYSTEM),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_contact_id', 'ID контакта Битрикс24', self::TYPE_NUMBER, 700),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_sync_status', 'Статус синхронизации контакта Битрикс24', self::TYPE_SELECT, 702, self::bitrix24SyncStatusOptions()),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_last_synced_at', 'Контакт Битрикс24 синхронизирован', self::TYPE_DATE, 704),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_linked_at', 'Контакт Битрикс24 привязан', self::TYPE_DATE, 706),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_sync_pending', 'Синхронизация контакта Битрикс24 в очереди', self::TYPE_BOOLEAN, 708),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_sync_fingerprint', 'Fingerprint синхронизации Битрикс24', self::TYPE_TEXT, 709),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_deal_id', 'ID сделки Битрикс24', self::TYPE_NUMBER, 710),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_deal_sync_status', 'Статус синхронизации сделки Битрикс24', self::TYPE_SELECT, 712, self::bitrix24DealSyncStatusOptions()),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_deal_last_synced_at', 'Сделка Битрикс24 синхронизирована', self::TYPE_DATE, 714),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_deal_linked_at', 'Сделка Битрикс24 привязана', self::TYPE_DATE, 716),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_deal_sync_pending', 'Синхронизация сделки Битрикс24 в очереди', self::TYPE_BOOLEAN, 718),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_history_sync_status', 'Статус выгрузки истории Битрикс24', self::TYPE_SELECT, 720, self::bitrix24HistorySyncStatusOptions()),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_history_last_synced_at', 'История Битрикс24 синхронизирована', self::TYPE_DATE, 722),
            self::definition(self::ENTITY_CONTACT, 'bitrix24_history_sync_pending', 'Выгрузка истории Битрикс24 в очереди', self::TYPE_BOOLEAN, 724),
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
            self::definition(self::ENTITY_DIALOG, 'assigned_user_id', 'Ответственный', self::TYPE_NUMBER, 35, conditionVisibility: self::CONDITION_VISIBILITY_DISPLAY_ONLY, writeAccess: self::WRITE_ACCESS_READ_ONLY, hintGroup: self::HINT_GROUP_SYSTEM),
            self::definition(self::ENTITY_DIALOG, 'stage', 'Этап', self::TYPE_SELECT, 40, self::dialogStageOptionsWithDictionaryOverrides()),
            self::definition(self::ENTITY_DIALOG, 'current_block_id', 'Текущий блок', self::TYPE_TEXT, 45),
            self::definition(self::ENTITY_DIALOG, 'phone', 'Телефон', self::TYPE_PHONE, 50),
            self::definition(self::ENTITY_DIALOG, 'external_username', 'Юзернейм', self::TYPE_TEXT, 55),
            self::definition(self::ENTITY_DIALOG, 'peer_sync', 'Загрузка истории', self::TYPE_TEXT, 58, conditionVisibility: self::CONDITION_VISIBILITY_DISPLAY_ONLY, writeAccess: self::WRITE_ACCESS_READ_ONLY, hintGroup: self::HINT_GROUP_SYSTEM, cardDisplayType: self::CARD_DISPLAY_DIALOG_PEER_SYNC),
            self::definition(self::ENTITY_DIALOG, 'avatar', 'Аватарка', self::TYPE_TEXT, 60),
            self::definition(self::ENTITY_DIALOG, 'bot_subscription_status', 'Подписка на бота', self::TYPE_SELECT, 70, [
                ['value' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER, 'label' => 'Бот заблокирован пользователем', 'is_system' => true],
            ]),
            self::definition(self::ENTITY_DIALOG, 'bot_subscription_changed_at', 'Подписка на бота изменена', self::TYPE_DATE, 72),
            self::definition(self::ENTITY_DIALOG, 'external_chat_id', 'Внешний ID чата', self::TYPE_TEXT, 80),
            self::definition(self::ENTITY_DIALOG, 'bitrix24_live_chat_id', 'ID чата Битрикс24', self::TYPE_TEXT, 700),
            self::definition(self::ENTITY_DIALOG, 'bitrix24_live_status', 'Статус чата Битрикс24', self::TYPE_SELECT, 702, [
                ['value' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED, 'label' => 'Не связан', 'is_system' => true],
                ['value' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE, 'label' => 'Активен', 'is_system' => true],
                ['value' => Dialog::BITRIX24_LIVE_STATUS_FAILED, 'label' => 'Ошибка', 'is_system' => true],
                ['value' => Dialog::BITRIX24_LIVE_STATUS_CLOSED, 'label' => 'Закрыт', 'is_system' => true],
            ]),
            self::definition(self::ENTITY_DIALOG, 'bitrix24_live_last_exported_at', 'Чат Битрикс24 выгружен', self::TYPE_DATE, 704),
            self::definition(self::ENTITY_DIALOG, 'bitrix24_live_last_imported_at', 'Чат Битрикс24 загружен', self::TYPE_DATE, 706),
            self::definition(self::ENTITY_DIALOG, 'phone_confirmed_at', 'Телефон подтверждён', self::TYPE_DATE, 90),
            self::definition(self::ENTITY_DIALOG, 'phone_confirmed_via', 'Как подтверждён телефон', self::TYPE_SELECT, 92, [
                ['value' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE, 'label' => 'Сбор телефона', 'is_system' => true],
            ]),
            self::definition(self::ENTITY_DIALOG, 'last_message_at', 'Последнее сообщение', self::TYPE_DATE, 100),
            self::definition(self::ENTITY_DIALOG, 'last_inbound_message_at', 'Последнее входящее', self::TYPE_DATE, 105),
            self::definition(self::ENTITY_DIALOG, 'last_outbound_message_at', 'Последнее исходящее', self::TYPE_DATE, 106),
            self::definition(self::ENTITY_DIALOG, 'last_message_id', 'ID последнего сообщения', self::TYPE_NUMBER, 108),
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
     * @return list<array<string, mixed>>
     */
    protected static function regionSourceOptions(): array
    {
        return [
            ['value' => Contact::REGION_SOURCE_AI, 'label' => 'ИИ', 'is_system' => true],
            ['value' => Contact::REGION_SOURCE_DICTIONARY, 'label' => 'Справочник', 'is_system' => true],
            ['value' => Contact::REGION_SOURCE_CONFIRMED_BY_CONTACT, 'label' => 'Подтверждён клиентом', 'is_system' => true],
            ['value' => Contact::REGION_SOURCE_MANUAL, 'label' => 'Указан вручную', 'is_system' => true],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function bitrix24SyncStatusOptions(): array
    {
        return [
            ['value' => Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED, 'label' => 'Не синхронизирован', 'is_system' => true],
            ['value' => Contact::BITRIX24_SYNC_STATUS_PENDING, 'label' => 'В очереди', 'is_system' => true],
            ['value' => Contact::BITRIX24_SYNC_STATUS_SYNCED, 'label' => 'Синхронизирован', 'is_system' => true],
            ['value' => Contact::BITRIX24_SYNC_STATUS_FAILED, 'label' => 'Ошибка', 'is_system' => true],
            ['value' => Contact::BITRIX24_SYNC_STATUS_PENDING_REVIEW, 'label' => 'Требует проверки', 'is_system' => true],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function bitrix24DealSyncStatusOptions(): array
    {
        return [
            ['value' => Contact::BITRIX24_DEAL_SYNC_STATUS_NOT_SYNCED, 'label' => 'Не синхронизирована', 'is_system' => true],
            ['value' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING, 'label' => 'В очереди', 'is_system' => true],
            ['value' => Contact::BITRIX24_DEAL_SYNC_STATUS_SYNCED, 'label' => 'Синхронизирована', 'is_system' => true],
            ['value' => Contact::BITRIX24_DEAL_SYNC_STATUS_FAILED, 'label' => 'Ошибка', 'is_system' => true],
            ['value' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING_REVIEW, 'label' => 'Требует проверки', 'is_system' => true],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function bitrix24HistorySyncStatusOptions(): array
    {
        return [
            ['value' => Contact::BITRIX24_HISTORY_SYNC_STATUS_NOT_SYNCED, 'label' => 'Не выгружалась', 'is_system' => true],
            ['value' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING, 'label' => 'В очереди', 'is_system' => true],
            ['value' => Contact::BITRIX24_HISTORY_SYNC_STATUS_SYNCED, 'label' => 'Выгружена', 'is_system' => true],
            ['value' => Contact::BITRIX24_HISTORY_SYNC_STATUS_FAILED, 'label' => 'Ошибка', 'is_system' => true],
        ];
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array<string, mixed>>
     */
    protected static function optionsFrom(array $options): array
    {
        return collect($options)
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
                'is_system' => true,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $storedOptions
     * @return list<array{value:string,label:string,is_system:bool}>
     */
    protected static function dialogStageOptionsWithDictionaryOverrides(array $storedOptions = []): array
    {
        $storedLabels = collect(self::normalizeOptions($storedOptions))
            ->mapWithKeys(fn (array $option): array => [$option['value'] => $option['label']])
            ->all();

        return collect(app(DialogStageCatalog::class)->options())
            ->map(function (array $option) use ($storedLabels): array {
                $value = (string) $option['value'];
                $storedLabel = trim((string) ($storedLabels[$value] ?? ''));

                if ($storedLabel !== '') {
                    $option['label'] = $storedLabel;
                }

                return $option;
            })
            ->values()
            ->all();
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
        ?string $conditionVisibility = null,
        ?string $writeAccess = null,
        ?string $hintGroup = null,
        ?string $cardDisplayType = null,
        ?string $manualWriteAccess = null,
        ?string $scenarioWriteAccess = null,
        ?string $valueOwner = null,
    ): array {
        $resolvedWriteAccess = $writeAccess ?? self::defaultWriteAccess($entity, $fieldKey);
        $resolvedCardDisplayType = $cardDisplayType ?? self::CARD_DISPLAY_VALUE;
        $resolvedScenarioWriteAccess = $scenarioWriteAccess ?? self::scenarioWriteAccessFromLegacy($resolvedWriteAccess);

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
            'condition_visibility' => $conditionVisibility ?? self::defaultConditionVisibility($entity, $fieldKey),
            'write_access' => self::legacyWriteAccessFromScenario($resolvedScenarioWriteAccess, $resolvedWriteAccess),
            'manual_write_access' => $manualWriteAccess ?? self::defaultManualWriteAccess($entity, $fieldKey, true),
            'scenario_write_access' => $resolvedScenarioWriteAccess,
            'value_owner' => $valueOwner ?? self::defaultValueOwner($entity, $fieldKey, true, $resolvedCardDisplayType),
            'hint_group' => $hintGroup ?? self::defaultHintGroup($entity, $fieldKey),
            'card_display_type' => $resolvedCardDisplayType,
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
        $this->condition_visibility = trim((string) ($this->condition_visibility ?? self::defaultConditionVisibilityForModel($this)));
        $this->write_access = trim((string) ($this->write_access ?? self::defaultWriteAccessForModel($this)));
        if (self::supportsSeparatedWriteAccess()) {
            $this->manual_write_access = trim((string) ($this->manual_write_access ?? self::defaultManualWriteAccessForModel($this)));
            $this->scenario_write_access = trim((string) ($this->scenario_write_access ?? self::defaultScenarioWriteAccessForModel($this)));
            $this->value_owner = trim((string) ($this->value_owner ?? self::defaultValueOwnerForModel($this)));
            $this->write_access = self::legacyWriteAccessFromScenario($this->scenario_write_access, $this->write_access);
        }
        $this->hint_group = trim((string) ($this->hint_group ?? self::defaultHintGroup($this->entity, $this->field_key)));

        if (self::supportsCardDisplayType()) {
            $this->card_display_type = trim((string) ($this->card_display_type ?? self::CARD_DISPLAY_VALUE));
        }

        if ($this->entity === self::ENTITY_CONTACT && ! $this->is_system) {
            $this->condition_visibility = self::CONDITION_VISIBILITY_DISPLAY_ONLY;
            $this->write_access = self::WRITE_ACCESS_READ_ONLY;
            if (self::supportsSeparatedWriteAccess()) {
                $this->manual_write_access = self::MANUAL_WRITE_ACCESS_READONLY;
                $this->scenario_write_access = self::SCENARIO_WRITE_ACCESS_DENIED;
                $this->value_owner = self::VALUE_OWNER_OPERATOR;
            }
            $this->hint_group = self::HINT_GROUP_CONTACT;
        }
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

        if (
            $original->is_system
            && ! self::$syncingSystemDefinitions
            && (
                $original->condition_visibility !== $this->condition_visibility
                || $original->write_access !== $this->write_access
                || (self::supportsSeparatedWriteAccess() && (
                    $original->manual_write_access !== $this->manual_write_access
                    || $original->scenario_write_access !== $this->scenario_write_access
                    || $original->value_owner !== $this->value_owner
                ))
                || $original->hint_group !== $this->hint_group
                || (self::supportsCardDisplayType() && $original->card_display_type !== $this->card_display_type)
            )
        ) {
            throw ValidationException::withMessages([
                'field' => 'Доступность, группа и отображение системного поля задаются системным справочником.',
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

        if (! array_key_exists($this->condition_visibility, self::conditionVisibilityOptions())) {
            throw ValidationException::withMessages([
                'condition_visibility' => 'Неизвестная доступность поля в условиях.',
            ]);
        }

        if (! array_key_exists($this->write_access, self::writeAccessOptions())) {
            throw ValidationException::withMessages([
                'write_access' => 'Неизвестная доступность поля для изменения.',
            ]);
        }

        if (self::supportsSeparatedWriteAccess()) {
            if (! array_key_exists($this->manual_write_access, self::manualWriteAccessOptions())) {
                throw ValidationException::withMessages([
                    'manual_write_access' => 'Неизвестная доступность поля для ручного изменения.',
                ]);
            }

            if (! array_key_exists($this->scenario_write_access, self::scenarioWriteAccessOptions())) {
                throw ValidationException::withMessages([
                    'scenario_write_access' => 'Неизвестная доступность поля для изменения сценарием.',
                ]);
            }

            if (! array_key_exists($this->value_owner, self::valueOwnerOptions())) {
                throw ValidationException::withMessages([
                    'value_owner' => 'Неизвестный основной источник значения поля.',
                ]);
            }
        }

        if (! array_key_exists($this->hint_group, self::hintGroupOptions())) {
            throw ValidationException::withMessages([
                'hint_group' => 'Неизвестная группа подсказок.',
            ]);
        }

        if (self::supportsCardDisplayType() && ! array_key_exists($this->card_display_type, self::cardDisplayTypeOptions())) {
            throw ValidationException::withMessages([
                'card_display_type' => 'Неизвестный тип отображения поля в карточке.',
            ]);
        }

        $validFieldKey = $this->entity === self::ENTITY_DIALOG && ! $this->is_system
            ? self::isValidDialogUserFieldKey((string) $this->field_key)
            : preg_match('/^[a-z][a-z0-9_]*$/', (string) $this->field_key) === 1;

        if (! $validFieldKey) {
            throw ValidationException::withMessages([
                'field_key' => $this->entity === self::ENTITY_DIALOG && ! $this->is_system
                    ? 'Ключ пользовательского поля диалога должен начинаться с буквы и содержать буквы, цифры или подчёркивания.'
                    : 'Ключ поля должен быть латиницей: буквы, цифры и подчёркивания.',
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

    protected static function defaultConditionVisibility(string $entity, string $fieldKey): string
    {
        $readableKeys = match ($entity) {
            self::ENTITY_CONTACT => EngineFieldRegistry::readableFieldKeys(EngineFieldRegistry::ENTITY_CONTACT),
            self::ENTITY_DIALOG => EngineFieldRegistry::readableFieldKeys(EngineFieldRegistry::ENTITY_DIALOG),
            default => [],
        };

        if (! in_array($fieldKey, $readableKeys, true)) {
            return self::CONDITION_VISIBILITY_DISPLAY_ONLY;
        }

        if (
            in_array($fieldKey, ['id', 'created_at', 'updated_at'], true)
            || str_ends_with($fieldKey, '_id')
            || str_ends_with($fieldKey, '_source')
            || str_ends_with($fieldKey, '_method')
            || str_contains($fieldKey, 'bitrix24_')
        ) {
            return self::CONDITION_VISIBILITY_SYSTEM;
        }

        return self::CONDITION_VISIBILITY_MAIN;
    }

    protected static function defaultWriteAccess(string $entity, string $fieldKey): string
    {
        if ($entity === self::ENTITY_CONTACT && in_array($fieldKey, EngineFieldRegistry::writableFieldKeys(EngineFieldRegistry::ENTITY_CONTACT), true)) {
            return self::WRITE_ACCESS_WRITABLE;
        }

        if (str_contains($fieldKey, 'bitrix24_') || str_ends_with($fieldKey, '_status') || str_ends_with($fieldKey, '_source')) {
            return self::WRITE_ACCESS_SYSTEM_ONLY;
        }

        return self::WRITE_ACCESS_READ_ONLY;
    }

    protected static function defaultManualWriteAccess(string $entity, string $fieldKey, bool $isSystem): string
    {
        if ($entity === self::ENTITY_DIALOG && ! $isSystem) {
            return self::MANUAL_WRITE_ACCESS_EDITABLE;
        }

        return self::MANUAL_WRITE_ACCESS_READONLY;
    }

    protected static function defaultScenarioWriteAccess(string $entity, string $fieldKey, bool $isSystem): string
    {
        if ($entity === self::ENTITY_DIALOG && ! $isSystem) {
            return self::SCENARIO_WRITE_ACCESS_ALLOWED;
        }

        return self::scenarioWriteAccessFromLegacy(self::defaultWriteAccess($entity, $fieldKey));
    }

    protected static function defaultValueOwner(string $entity, string $fieldKey, bool $isSystem, string $cardDisplayType = self::CARD_DISPLAY_VALUE): string
    {
        if (! $isSystem) {
            return $entity === self::ENTITY_DIALOG
                ? self::VALUE_OWNER_OPERATOR
                : self::VALUE_OWNER_OPERATOR;
        }

        if (str_contains($fieldKey, 'bitrix24_')) {
            return self::VALUE_OWNER_INTEGRATION;
        }

        if ($cardDisplayType !== self::CARD_DISPLAY_VALUE || str_starts_with($fieldKey, 'effective_')) {
            return self::VALUE_OWNER_COMPUTED;
        }

        if (
            $fieldKey === 'id'
            || $fieldKey === 'created_at'
            || $fieldKey === 'updated_at'
            || str_ends_with($fieldKey, '_id')
            || str_ends_with($fieldKey, '_at')
            || str_ends_with($fieldKey, '_status')
        ) {
            return self::VALUE_OWNER_SYSTEM;
        }

        return self::VALUE_OWNER_SYSTEM;
    }

    protected static function scenarioWriteAccessFromLegacy(string $writeAccess): string
    {
        return $writeAccess === self::WRITE_ACCESS_WRITABLE
            ? self::SCENARIO_WRITE_ACCESS_ALLOWED
            : self::SCENARIO_WRITE_ACCESS_DENIED;
    }

    protected static function legacyWriteAccessFromScenario(string $scenarioWriteAccess, string $previousWriteAccess = self::WRITE_ACCESS_READ_ONLY): string
    {
        if ($scenarioWriteAccess === self::SCENARIO_WRITE_ACCESS_ALLOWED) {
            return self::WRITE_ACCESS_WRITABLE;
        }

        return $previousWriteAccess === self::WRITE_ACCESS_SYSTEM_ONLY
            ? self::WRITE_ACCESS_SYSTEM_ONLY
            : self::WRITE_ACCESS_READ_ONLY;
    }

    protected static function defaultConditionVisibilityForModel(self $field): string
    {
        if ($field->entity === self::ENTITY_DIALOG && ! $field->is_system) {
            return self::CONDITION_VISIBILITY_MAIN;
        }

        return self::defaultConditionVisibility($field->entity, $field->field_key);
    }

    protected static function defaultWriteAccessForModel(self $field): string
    {
        if ($field->entity === self::ENTITY_DIALOG && ! $field->is_system) {
            return self::WRITE_ACCESS_WRITABLE;
        }

        return self::defaultWriteAccess($field->entity, $field->field_key);
    }

    protected static function defaultManualWriteAccessForModel(self $field): string
    {
        return self::defaultManualWriteAccess($field->entity, $field->field_key, (bool) $field->is_system);
    }

    protected static function defaultScenarioWriteAccessForModel(self $field): string
    {
        if (filled($field->write_access)) {
            return self::scenarioWriteAccessFromLegacy((string) $field->write_access);
        }

        return self::defaultScenarioWriteAccess($field->entity, $field->field_key, (bool) $field->is_system);
    }

    protected static function defaultValueOwnerForModel(self $field): string
    {
        return self::defaultValueOwner(
            $field->entity,
            $field->field_key,
            (bool) $field->is_system,
            (string) ($field->card_display_type ?? self::CARD_DISPLAY_VALUE),
        );
    }

    protected static function filterDefinitionForCurrentSchema(array $definition): array
    {
        if (! self::supportsCardDisplayType()) {
            unset($definition['card_display_type']);
        }

        if (self::supportsSeparatedWriteAccess()) {
            return $definition;
        }

        unset($definition['manual_write_access'], $definition['scenario_write_access'], $definition['value_owner']);

        return $definition;
    }

    protected static function supportsCardDisplayType(): bool
    {
        return Schema::hasColumn('field_dictionary_fields', 'card_display_type');
    }

    protected static function supportsSeparatedWriteAccess(): bool
    {
        return Schema::hasColumn('field_dictionary_fields', 'manual_write_access')
            && Schema::hasColumn('field_dictionary_fields', 'scenario_write_access')
            && Schema::hasColumn('field_dictionary_fields', 'value_owner');
    }

    public static function defaultHintGroup(string $entity, string $fieldKey): string
    {
        if (str_contains($fieldKey, 'bitrix24_')) {
            return self::HINT_GROUP_BITRIX24;
        }

        if (in_array($fieldKey, [
            'country',
            'region',
            'region_status',
            'region_source',
            'city',
            'distance_to_moscow_km',
            'distance_to_moscow_status',
            'distance_to_moscow_calculated_at',
        ], true)) {
            return self::HINT_GROUP_GEO;
        }

        if (str_starts_with($fieldKey, 'data_collection_')) {
            return self::HINT_GROUP_QUESTIONNAIRE;
        }

        if (
            in_array($fieldKey, ['id', 'created_at', 'updated_at', 'current_block_id'], true)
            || str_ends_with($fieldKey, '_id')
            || str_ends_with($fieldKey, '_source')
            || str_ends_with($fieldKey, '_method')
        ) {
            return self::HINT_GROUP_SYSTEM;
        }

        return $entity === self::ENTITY_DIALOG
            ? self::HINT_GROUP_DIALOG
            : self::HINT_GROUP_CONTACT;
    }
}
