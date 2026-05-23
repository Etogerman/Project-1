<?php

namespace App\Services\Questionnaires;

use App\Models\Contact;
use App\Services\Scenarios\ScenarioEdgeExpressionCondition;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ValidateQuestionnaireFieldsPayloadAction
{
    private const TYPE_TEXT = 'text';

    private const TYPE_SINGLE_CHOICE = 'single_choice';

    private const TYPE_PHONE = 'phone';

    private const TYPE_DICTIONARY_LOOKUP = 'dictionary_lookup';

    private const DICTIONARY_NAMES = 'names';

    /**
     * @var list<string>
     */
    private const ALLOWED_TYPES = [
        self::TYPE_TEXT,
        self::TYPE_SINGLE_CHOICE,
        self::TYPE_PHONE,
        self::TYPE_DICTIONARY_LOOKUP,
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_TARGETS = [
        'contact.first_name',
        'contact.gender',
        'contact.country',
        'contact.city',
        'contact.region',
        'contact.age_years',
        'contact.age_range',
        'contact.phone',
    ];

    public function __construct(
        private readonly ScenarioEdgeExpressionCondition $expressionCondition,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(mixed $fieldsPayload, string $attribute = 'fields_payload'): array
    {
        $errors = [];

        if (! is_array($fieldsPayload) || ! array_is_list($fieldsPayload)) {
            throw ValidationException::withMessages([
                $attribute => 'Поля анкеты должны быть JSON-массивом.',
            ]);
        }

        if ($fieldsPayload === []) {
            throw ValidationException::withMessages([
                $attribute => 'В анкете должен быть хотя бы один шаг.',
            ]);
        }

        $fieldKeys = [];
        $normalizedFields = [];

        foreach ($fieldsPayload as $index => $field) {
            $fieldNumber = $index + 1;

            if (! is_array($field)) {
                $errors[] = "Поле #{$fieldNumber}: шаг должен быть JSON-объектом.";

                continue;
            }

            $normalized = $this->normalizeField($field, $fieldNumber, $errors);

            if ($normalized === null) {
                continue;
            }

            $fieldKey = $normalized['field_key'];

            if (in_array($fieldKey, $fieldKeys, true)) {
                $errors[] = "Поле #{$fieldNumber}: field_key «{$fieldKey}» повторяется.";
            }

            $fieldKeys[] = $fieldKey;
            $normalizedFields[] = $normalized;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                $attribute => implode("\n", $errors),
            ]);
        }

        return $normalizedFields;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function normalizeField(array $field, int $fieldNumber, array &$errors): ?array
    {
        $fieldErrors = [];

        $fieldKey = $this->requiredString($field, 'field_key', $fieldNumber, $fieldErrors);
        $label = $this->requiredString($field, 'label', $fieldNumber, $fieldErrors);
        $type = $this->requiredString($field, 'type', $fieldNumber, $fieldErrors);

        if ($fieldKey !== null && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $fieldKey) !== 1) {
            $fieldErrors[] = "Поле #{$fieldNumber}: field_key должен начинаться с латинской буквы и содержать только латиницу, цифры и _.";
        }

        if ($type !== null && ! in_array($type, self::ALLOWED_TYPES, true)) {
            $fieldErrors[] = "Поле #{$fieldNumber}: type должен быть одним из ".implode(', ', self::ALLOWED_TYPES).'.';
        }

        $required = $this->requiredBool($field, 'required', $fieldNumber, $fieldErrors);
        $allowSkip = $this->requiredBool($field, 'allow_skip', $fieldNumber, $fieldErrors);
        $maxAttempts = $this->requiredPositiveInt($field, 'max_attempts', $fieldNumber, $fieldErrors);
        $prompts = $this->requiredStringList($field, 'prompts', $fieldNumber, $fieldErrors);
        $target = $this->optionalString($field, 'target');
        $requiredWhen = $this->optionalString($field, 'required_when') ?? '';

        if (array_key_exists('overwrite_contact', $field) && ! is_bool($field['overwrite_contact'])) {
            $fieldErrors[] = "Поле #{$fieldNumber}: overwrite_contact должен быть boolean.";
        }

        if ($target !== null && ! in_array($target, self::ALLOWED_TARGETS, true)) {
            $fieldErrors[] = "Поле #{$fieldNumber}: target «{$target}» не входит в разрешённый список.";
        }

        if ($requiredWhen !== '') {
            try {
                $this->expressionCondition->assertValid($requiredWhen);
            } catch (InvalidArgumentException $exception) {
                $fieldErrors[] = "Поле #{$fieldNumber}: required_when некорректен ({$exception->getMessage()}).";
            }
        }

        $options = [];

        if ($type === self::TYPE_SINGLE_CHOICE) {
            $options = $this->requiredOptions($field, $fieldNumber, $fieldErrors);
        }

        if ($type === self::TYPE_DICTIONARY_LOOKUP) {
            $dictionaryKey = $this->requiredString($field, 'dictionary_key', $fieldNumber, $fieldErrors);

            if ($dictionaryKey !== null && $dictionaryKey !== self::DICTIONARY_NAMES) {
                $fieldErrors[] = "Поле #{$fieldNumber}: в MVP поддерживается только dictionary_key=names.";
            }

            if ($target !== null && $target !== 'contact.first_name') {
                $fieldErrors[] = "Поле #{$fieldNumber}: dictionary_lookup в MVP можно писать только в contact.first_name.";
            }
        }

        if ($type === self::TYPE_PHONE && $target !== null && $target !== 'contact.phone') {
            $fieldErrors[] = "Поле #{$fieldNumber}: phone можно писать только в contact.phone.";
        }

        if ($target !== null && $type !== null) {
            $this->validateTargetContract($target, $type, $options, $fieldNumber, $fieldErrors);
        }

        if ($fieldErrors !== []) {
            array_push($errors, ...$fieldErrors);

            return null;
        }

        $normalized = [
            'field_key' => $fieldKey,
            'label' => $label,
            'type' => $type,
            'required' => $required,
            'allow_skip' => $allowSkip,
            'max_attempts' => $maxAttempts,
            'prompts' => $prompts,
        ];

        foreach (['target', 'overwrite_contact', 'required_when', 'dictionary_key'] as $key) {
            if (array_key_exists($key, $field)) {
                $normalized[$key] = $field[$key];
            }
        }

        if ($options !== []) {
            $normalized['options'] = $options;
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, string>>  $options
     * @param  list<string>  $errors
     */
    private function validateTargetContract(string $target, string $type, array $options, int $fieldNumber, array &$errors): void
    {
        if (in_array($target, ['contact.gender', 'contact.country', 'contact.region', 'contact.age_range'], true)
            && $type !== self::TYPE_SINGLE_CHOICE
        ) {
            $errors[] = "Поле #{$fieldNumber}: {$target} должен быть single_choice.";

            return;
        }

        $values = array_column($options, 'value');

        if ($target === 'contact.gender') {
            $this->assertValuesSubset($values, array_keys(Contact::genderOptions()), $fieldNumber, 'gender', $errors);
        }

        if ($target === 'contact.age_range') {
            $this->assertValuesSubset($values, array_keys(Contact::ageRangeOptions()), $fieldNumber, 'age_range', $errors);
        }

        if ($target === 'contact.region') {
            $this->assertValuesSubset($values, array_keys(Contact::russianRegionOptions()), $fieldNumber, 'region', $errors);
        }

        if ($target === 'contact.country') {
            foreach ($values as $value) {
                if (! is_string($value) || preg_match('/^[A-Z]{2}$/', $value) !== 1) {
                    $errors[] = "Поле #{$fieldNumber}: country option value должен быть ISO-кодом из двух заглавных букв.";

                    return;
                }
            }
        }
    }

    /**
     * @param  list<mixed>  $values
     * @param  list<string>  $allowedValues
     * @param  list<string>  $errors
     */
    private function assertValuesSubset(array $values, array $allowedValues, int $fieldNumber, string $name, array &$errors): void
    {
        foreach ($values as $value) {
            if (! is_string($value) || ! in_array($value, $allowedValues, true)) {
                $errors[] = "Поле #{$fieldNumber}: {$name} option value «".(is_scalar($value) ? (string) $value : 'не строка').'» не разрешён.';

                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     */
    private function requiredString(array $field, string $key, int $fieldNumber, array &$errors): ?string
    {
        $value = $this->optionalString($field, $key);

        if ($value === null || $value === '') {
            $errors[] = "Поле #{$fieldNumber}: {$key} обязательно.";

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function optionalString(array $field, string $key): ?string
    {
        if (! array_key_exists($key, $field) || $field[$key] === null) {
            return null;
        }

        if (! is_scalar($field[$key])) {
            return null;
        }

        return trim((string) $field[$key]);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     */
    private function requiredBool(array $field, string $key, int $fieldNumber, array &$errors): bool
    {
        if (! array_key_exists($key, $field) || ! is_bool($field[$key])) {
            $errors[] = "Поле #{$fieldNumber}: {$key} должен быть boolean.";

            return false;
        }

        return $field[$key];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     */
    private function requiredPositiveInt(array $field, string $key, int $fieldNumber, array &$errors): int
    {
        if (! array_key_exists($key, $field) || ! is_int($field[$key]) || $field[$key] < 1 || $field[$key] > 10) {
            $errors[] = "Поле #{$fieldNumber}: {$key} должен быть целым числом от 1 до 10.";

            return 1;
        }

        return $field[$key];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function requiredStringList(array $field, string $key, int $fieldNumber, array &$errors): array
    {
        if (! array_key_exists($key, $field) || ! is_array($field[$key]) || ! array_is_list($field[$key]) || $field[$key] === []) {
            $errors[] = "Поле #{$fieldNumber}: {$key} должен быть непустым массивом строк.";

            return [];
        }

        $values = [];

        foreach ($field[$key] as $item) {
            if (! is_string($item) || trim($item) === '') {
                $errors[] = "Поле #{$fieldNumber}: {$key} должен содержать только непустые строки.";

                return [];
            }

            $values[] = trim($item);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     * @return list<array{value:string,label:string}>
     */
    private function requiredOptions(array $field, int $fieldNumber, array &$errors): array
    {
        if (! array_key_exists('options', $field) || ! is_array($field['options']) || ! array_is_list($field['options']) || $field['options'] === []) {
            $errors[] = "Поле #{$fieldNumber}: options должен быть непустым массивом.";

            return [];
        }

        $options = [];
        $values = [];

        foreach ($field['options'] as $optionIndex => $option) {
            $optionNumber = $optionIndex + 1;

            if (! is_array($option)) {
                $errors[] = "Поле #{$fieldNumber}, option #{$optionNumber}: option должен быть объектом.";

                continue;
            }

            $value = $this->requiredString($option, 'value', $fieldNumber, $errors);
            $label = $this->requiredString($option, 'label', $fieldNumber, $errors);

            if ($value === null || $label === null) {
                continue;
            }

            if (in_array($value, $values, true)) {
                $errors[] = "Поле #{$fieldNumber}: option value «{$value}» повторяется.";
            }

            $values[] = $value;
            $options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $options;
    }
}
