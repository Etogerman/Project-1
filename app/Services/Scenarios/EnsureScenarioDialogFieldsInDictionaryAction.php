<?php

namespace App\Services\Scenarios;

use App\Models\FieldDictionaryField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnsureScenarioDialogFieldsInDictionaryAction
{
    /**
     * @param  array<string, mixed>  $builder
     */
    public function handle(array $builder): void
    {
        $fields = $this->collectDialogFields($builder);

        if ($fields === []) {
            return;
        }

        DB::transaction(function () use ($fields): void {
            $existing = FieldDictionaryField::query()
                ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
                ->whereIn('field_key', array_keys($fields))
                ->pluck('field_key')
                ->all();
            $existing = array_fill_keys($existing, true);
            $sortOrder = (int) FieldDictionaryField::query()
                ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
                ->max('sort_order');

            foreach ($fields as $fieldKey => $type) {
                if (isset($existing[$fieldKey])) {
                    continue;
                }

                FieldDictionaryField::query()->create([
                    'entity' => FieldDictionaryField::ENTITY_DIALOG,
                    'field_key' => $fieldKey,
                    'name' => $this->labelFromKey($fieldKey),
                    'type' => $type,
                    'sort_order' => $sortOrder += 10,
                    'is_system' => false,
                    'condition_visibility' => FieldDictionaryField::CONDITION_VISIBILITY_MAIN,
                    'write_access' => FieldDictionaryField::WRITE_ACCESS_WRITABLE,
                    'manual_write_access' => FieldDictionaryField::MANUAL_WRITE_ACCESS_EDITABLE,
                    'scenario_write_access' => FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED,
                    'value_owner' => FieldDictionaryField::VALUE_OWNER_SCENARIO,
                    'hint_group' => FieldDictionaryField::HINT_GROUP_DIALOG,
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $builder
     * @return array<string, string>
     */
    private function collectDialogFields(array $builder): array
    {
        $fields = [];

        foreach ($builder['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $this->collectStringTokens($block, $fields);

            foreach ($block['settings_payload']['modules'] ?? [] as $module) {
                if (! is_array($module)) {
                    continue;
                }

                $payload = is_array($module['payload'] ?? null) ? $module['payload'] : [];

                foreach ($payload['actions'] ?? [] as $action) {
                    if (is_array($action)) {
                        $this->collectActionFields($action, $fields);
                    }
                }
            }
        }

        foreach ($builder['edges'] ?? [] as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $this->collectStringTokens($edge, $fields);
            $condition = is_array($edge['condition_payload'] ?? null) ? $edge['condition_payload'] : [];
            $fieldCondition = is_array($condition['field_condition'] ?? null) ? $condition['field_condition'] : [];

            if (($fieldCondition['field_scope'] ?? null) === 'dialog') {
                $this->rememberField((string) ($fieldCondition['field_key'] ?? ''), $fields);
            }

            $inputCapture = is_array($condition['input_capture'] ?? null) ? $condition['input_capture'] : [];

            if (($inputCapture['field_scope'] ?? null) === 'dialog') {
                $this->rememberField(
                    (string) ($inputCapture['field_key'] ?? ''),
                    $fields,
                    $this->typeFromInputCapture((string) ($inputCapture['data_type'] ?? '')),
                );
            }

            foreach ($condition['transition_actions'] ?? [] as $action) {
                if (is_array($action) && ($action['target_scope'] ?? null) === 'dialog') {
                    $this->rememberField((string) ($action['target_field'] ?? ''), $fields);
                }
            }
        }

        ksort($fields);

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, string>  $fields
     */
    private function collectActionFields(array $action, array &$fields): void
    {
        $type = (string) ($action['type'] ?? '');

        if (($action['target_scope'] ?? null) === 'dialog') {
            $this->rememberField((string) ($action['target_field'] ?? ''), $fields);
        }

        if ($type === 'variables') {
            foreach ($action['operations'] ?? [] as $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                $operationType = (string) ($operation['operation'] ?? 'set');
                $this->rememberField(
                    (string) ($operation['field_key'] ?? ''),
                    $fields,
                    $operationType === 'increment' ? FieldDictionaryField::TYPE_NUMBER : FieldDictionaryField::TYPE_TEXT,
                );
            }
        }

        if ($type === 'simulate_start_parameter') {
            $this->rememberField((string) ($action['source_field_key'] ?? ''), $fields);
        }
    }

    /**
     * @param  mixed  $value
     * @param  array<string, string>  $fields
     */
    private function collectStringTokens(mixed $value, array &$fields): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collectStringTokens($item, $fields);
            }

            return;
        }

        if (! is_string($value) || ! str_contains($value, '{{dialog.')) {
            return;
        }

        if (! preg_match_all('/{{\s*dialog\.([^\s}|]+)(?:\|[^}]*)?\s*}}/u', $value, $matches)) {
            return;
        }

        foreach ($matches[1] ?? [] as $fieldKey) {
            $this->rememberField((string) $fieldKey, $fields);
        }
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function rememberField(string $fieldKey, array &$fields, string $type = FieldDictionaryField::TYPE_TEXT): void
    {
        $fieldKey = trim($fieldKey);

        if (! FieldDictionaryField::isValidDialogUserFieldKey($fieldKey)) {
            return;
        }

        if ($this->isDialogSystemField($fieldKey)) {
            return;
        }

        $fields[$fieldKey] = $this->mergeType($fields[$fieldKey] ?? null, $type);
    }

    private function isDialogSystemField(string $fieldKey): bool
    {
        return array_key_exists($fieldKey, EngineFieldRegistry::fields(EngineFieldRegistry::ENTITY_DIALOG));
    }

    private function mergeType(?string $currentType, string $nextType): string
    {
        if ($currentType === FieldDictionaryField::TYPE_NUMBER || $nextType === FieldDictionaryField::TYPE_NUMBER) {
            return FieldDictionaryField::TYPE_NUMBER;
        }

        if ($currentType === FieldDictionaryField::TYPE_PHONE || $nextType === FieldDictionaryField::TYPE_PHONE) {
            return FieldDictionaryField::TYPE_PHONE;
        }

        return FieldDictionaryField::TYPE_TEXT;
    }

    private function typeFromInputCapture(string $dataType): string
    {
        return match ($dataType) {
            'number' => FieldDictionaryField::TYPE_NUMBER,
            'phone' => FieldDictionaryField::TYPE_PHONE,
            default => FieldDictionaryField::TYPE_TEXT,
        };
    }

    private function labelFromKey(string $fieldKey): string
    {
        $label = str_replace('_', ' ', $fieldKey);
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
        $label = trim($label);

        if ($label === '') {
            return $fieldKey;
        }

        return Str::ucfirst($label);
    }
}
