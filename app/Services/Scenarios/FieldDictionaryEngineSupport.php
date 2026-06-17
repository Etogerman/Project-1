<?php

namespace App\Services\Scenarios;

use App\Models\FieldDictionaryField;

class FieldDictionaryEngineSupport
{
    /**
     * @return array{
     *     condition_supported: bool,
     *     field_condition_supported: bool,
     *     write_supported: bool,
     *     prompt_variable_supported: bool,
     *     condition_unavailable_reason: ?string,
     *     write_unavailable_reason: ?string
     * }
     */
    public function supportFor(FieldDictionaryField $field): array
    {
        $conditionSupported = $this->supportsCondition($field);
        $fieldConditionSupported = $this->supportsFieldCondition($field);
        $writeSupported = $this->supportsChangeFieldWrite($field);

        return [
            'condition_supported' => $conditionSupported,
            'field_condition_supported' => $fieldConditionSupported,
            'write_supported' => $writeSupported,
            'prompt_variable_supported' => $this->supportsPromptVariable($field),
            'condition_unavailable_reason' => $conditionSupported ? null : $this->conditionUnavailableReason($field),
            'write_unavailable_reason' => $writeSupported ? null : $this->writeUnavailableReason($field),
        ];
    }

    public function supportsCondition(FieldDictionaryField $field): bool
    {
        if ($field->condition_visibility === FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY) {
            return false;
        }

        if ($field->entity === FieldDictionaryField::ENTITY_DIALOG && ! $field->is_system) {
            return $this->validDialogFieldKey((string) $field->field_key);
        }

        if (! $field->is_system) {
            return false;
        }

        return in_array(
            (string) $field->field_key,
            $this->readableSystemFieldKeys((string) $field->entity),
            true,
        );
    }

    public function supportsFieldCondition(FieldDictionaryField $field): bool
    {
        if (! $this->supportsCondition($field)) {
            return false;
        }

        if ($field->entity === FieldDictionaryField::ENTITY_CONTACT) {
            return in_array((string) $field->field_key, EngineFieldRegistry::CONTACT_FIELD_CONDITION_FIELDS, true);
        }

        return true;
    }

    public function supportsChangeFieldWrite(FieldDictionaryField $field): bool
    {
        if ($field->scenario_write_access !== FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED) {
            return false;
        }

        if ($field->entity === FieldDictionaryField::ENTITY_DIALOG && ! $field->is_system) {
            return $this->validDialogFieldKey((string) $field->field_key);
        }

        if (! $field->is_system || $field->entity !== FieldDictionaryField::ENTITY_CONTACT) {
            return false;
        }

        return in_array((string) $field->field_key, EngineFieldRegistry::CONTACT_CHANGE_FIELD_FIELDS, true);
    }

    public function supportsPromptVariable(FieldDictionaryField $field): bool
    {
        if ($field->entity === FieldDictionaryField::ENTITY_CONTACT) {
            return in_array((string) $field->field_key, EngineFieldRegistry::CONTACT_PROMPT_VARIABLE_FIELDS, true);
        }

        if ($field->entity === FieldDictionaryField::ENTITY_DIALOG && ! $field->is_system) {
            return $this->validDialogFieldKey((string) $field->field_key);
        }

        return false;
    }

    public function supportsContactChangeField(string $fieldKey): bool
    {
        $field = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', $fieldKey)
            ->first();

        return $field instanceof FieldDictionaryField && $this->supportsChangeFieldWrite($field);
    }

    public function supportsDialogChangeField(string $fieldKey): bool
    {
        if (! $this->validDialogFieldKey($fieldKey)) {
            return false;
        }

        $field = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', $fieldKey)
            ->first();

        return $field instanceof FieldDictionaryField && $this->supportsChangeFieldWrite($field);
    }

    public function supportsContactFieldCondition(string $fieldKey): bool
    {
        $field = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', $fieldKey)
            ->first();

        return $field instanceof FieldDictionaryField && $this->supportsFieldCondition($field);
    }

    public function supportsDialogFieldCondition(string $fieldKey): bool
    {
        if (! $this->validDialogFieldKey($fieldKey)) {
            return false;
        }

        $field = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', $fieldKey)
            ->first();

        return $field instanceof FieldDictionaryField && $this->supportsFieldCondition($field);
    }

    /**
     * @return list<string>
     */
    public function consistencyProblems(): array
    {
        $problems = [];

        foreach ([FieldDictionaryField::ENTITY_CONTACT, FieldDictionaryField::ENTITY_DIALOG] as $entity) {
            $dictionary = FieldDictionaryField::query()
                ->where('entity', $entity)
                ->where('is_system', true)
                ->get(['field_key', 'type', 'condition_visibility', 'scenario_write_access'])
                ->keyBy('field_key');

            foreach (EngineFieldRegistry::fields($entity) as $fieldKey => $engineField) {
                $field = $dictionary->get($fieldKey);

                if (! $field instanceof FieldDictionaryField) {
                    $problems[] = "{$entity}.{$fieldKey}: поле поддержано движком, но отсутствует в справочнике.";

                    continue;
                }

                if ((string) $field->type !== (string) $engineField['type']) {
                    $problems[] = "{$entity}.{$fieldKey}: тип в справочнике не совпадает с типом движка.";
                }
            }

            foreach ($dictionary as $fieldKey => $field) {
                if ($field->condition_visibility !== FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY
                    && ! in_array((string) $fieldKey, $this->readableSystemFieldKeys($entity), true)) {
                    $problems[] = "{$entity}.{$fieldKey}: поле разрешено в условиях, но движок не умеет его читать.";
                }

                if ($field->scenario_write_access === FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED
                    && ! in_array((string) $fieldKey, $this->writableSystemFieldKeys($entity), true)) {
                    $problems[] = "{$entity}.{$fieldKey}: поле разрешено для записи, но движок не умеет его писать.";
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function readableSystemFieldKeys(string $entity): array
    {
        return match ($entity) {
            FieldDictionaryField::ENTITY_CONTACT => EngineFieldRegistry::readableFieldKeys(EngineFieldRegistry::ENTITY_CONTACT),
            FieldDictionaryField::ENTITY_DIALOG => EngineFieldRegistry::readableFieldKeys(EngineFieldRegistry::ENTITY_DIALOG),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function writableSystemFieldKeys(string $entity): array
    {
        return match ($entity) {
            FieldDictionaryField::ENTITY_CONTACT => EngineFieldRegistry::writableFieldKeys(EngineFieldRegistry::ENTITY_CONTACT),
            FieldDictionaryField::ENTITY_DIALOG => EngineFieldRegistry::writableFieldKeys(EngineFieldRegistry::ENTITY_DIALOG),
            default => [],
        };
    }

    private function conditionUnavailableReason(FieldDictionaryField $field): string
    {
        if ($field->condition_visibility === FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY) {
            return 'Поле настроено только для отображения.';
        }

        if ($field->entity === FieldDictionaryField::ENTITY_CONTACT && ! $field->is_system) {
            return 'Пользовательские поля контакта пока не поддержаны в условиях.';
        }

        return 'Движок сценариев пока не умеет читать это поле.';
    }

    private function writeUnavailableReason(FieldDictionaryField $field): string
    {
        if ($field->scenario_write_access !== FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED) {
            return 'Поле не разрешено менять через действие.';
        }

        if ($field->entity === FieldDictionaryField::ENTITY_CONTACT && ! $field->is_system) {
            return 'Пользовательские поля контакта пока не поддержаны для записи.';
        }

        if ($field->entity === FieldDictionaryField::ENTITY_DIALOG && $field->is_system) {
            return 'Системные поля диалога пока не поддержаны для записи.';
        }

        return 'Движок сценариев пока не умеет писать это поле.';
    }

    private function validDialogFieldKey(string $key): bool
    {
        return FieldDictionaryField::isValidDialogUserFieldKey($key);
    }
}
