<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class BotConstructorConstant extends Model
{
    use HasFactory;

    public const KEY_ARROW_PASS_LIMIT = 'bot_constructor_arrow_pass_limit';

    public const VALUE_TYPE_INTEGER = 'integer';

    public const VALUE_TYPE_STRING = 'string';

    public const VALUE_TYPE_BOOLEAN = 'boolean';

    public const DEFAULT_ARROW_PASS_LIMIT = 10;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'value_type',
        'value',
        'description',
    ];

    protected static function booted(): void
    {
        static::saving(function (BotConstructorConstant $constant): void {
            $constant->key = trim((string) $constant->key);
            $constant->name = trim((string) $constant->name);
            $constant->value_type = trim((string) $constant->value_type);
            $constant->value = trim((string) $constant->value);

            if ($constant->description !== null) {
                $constant->description = trim((string) $constant->description);
            }

            $constant->guardRequiredFields();
            $constant->guardValueType();
            $constant->guardValueMatchesType();
        });
    }

    /**
     * @return list<string>
     */
    public static function valueTypes(): array
    {
        return [
            self::VALUE_TYPE_INTEGER,
            self::VALUE_TYPE_STRING,
            self::VALUE_TYPE_BOOLEAN,
        ];
    }

    public function integerValue(): ?int
    {
        if ($this->value_type !== self::VALUE_TYPE_INTEGER) {
            return null;
        }

        return filter_var($this->value, FILTER_VALIDATE_INT) === false
            ? null
            : (int) $this->value;
    }

    private function guardRequiredFields(): void
    {
        $errors = [];

        if ($this->key === '') {
            $errors['key'] = 'Укажите системный ключ константы.';
        }

        if ($this->name === '') {
            $errors['name'] = 'Укажите название константы.';
        }

        if ($this->value_type === '') {
            $errors['value_type'] = 'Укажите тип значения константы.';
        }

        if ($this->value === '') {
            $errors['value'] = 'Укажите значение константы.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function guardValueType(): void
    {
        if (! in_array($this->value_type, self::valueTypes(), true)) {
            throw ValidationException::withMessages([
                'value_type' => 'Выберите поддерживаемый тип значения константы.',
            ]);
        }
    }

    private function guardValueMatchesType(): void
    {
        if ($this->value_type === self::VALUE_TYPE_INTEGER && filter_var($this->value, FILTER_VALIDATE_INT) === false) {
            throw ValidationException::withMessages([
                'value' => 'Значение константы должно быть целым числом.',
            ]);
        }

        if (
            $this->key === self::KEY_ARROW_PASS_LIMIT
            && $this->value_type === self::VALUE_TYPE_INTEGER
            && (int) $this->value < 1
        ) {
            throw ValidationException::withMessages([
                'value' => 'Лимит переходов должен быть больше 0.',
            ]);
        }

        if ($this->value_type === self::VALUE_TYPE_BOOLEAN && ! in_array($this->value, ['0', '1'], true)) {
            throw ValidationException::withMessages([
                'value' => 'Значение константы должно быть 0 или 1.',
            ]);
        }
    }
}
