<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DataDictionaryEntry extends Model
{
    public const DICTIONARY_NAMES = 'names';

    public const GENDER_MALE = 'male';

    public const GENDER_FEMALE = 'female';

    public const GENDER_UNKNOWN = 'unknown';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dictionary_key',
        'lookup_value',
        'lookup_normalized',
        'result_value',
        'gender',
        'auto_apply',
        'is_active',
        'comment',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'auto_apply' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (DataDictionaryEntry $entry): void {
            $entry->dictionary_key = filled($entry->dictionary_key)
                ? trim((string) $entry->dictionary_key)
                : self::DICTIONARY_NAMES;
            $entry->lookup_value = trim((string) $entry->lookup_value);
            $entry->lookup_normalized = self::normalizeLookupValue($entry->lookup_value);
            $entry->result_value = trim((string) $entry->result_value);
            $entry->gender = self::normalizeGender($entry->gender);
            $entry->comment = filled($entry->comment) ? trim((string) $entry->comment) : null;
        });
    }

    /**
     * @return array<string, string>
     */
    public static function genderOptions(): array
    {
        return [
            self::GENDER_MALE => 'Мужской',
            self::GENDER_FEMALE => 'Женский',
            self::GENDER_UNKNOWN => 'Непонятно',
        ];
    }

    public static function genderLabel(?string $gender): string
    {
        return self::genderOptions()[self::normalizeGender($gender)] ?? 'Непонятно';
    }

    public static function normalizeGender(mixed $gender): string
    {
        $gender = is_string($gender) ? trim($gender) : '';

        return array_key_exists($gender, self::genderOptions())
            ? $gender
            : self::GENDER_UNKNOWN;
    }

    public static function normalizeLookupValue(string $value): string
    {
        $value = trim($value);

        if ($value === '' || preg_match('/\s/u', $value) === 1) {
            return '';
        }

        $value = Str::of($value)
            ->lower()
            ->replace('ё', 'е')
            ->toString();

        return preg_match('/^[\p{L}][\p{L}-]{0,63}$/u', $value) === 1
            ? $value
            : '';
    }
}
