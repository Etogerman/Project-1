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

    public const LANGUAGE_RU = 'ru';

    public const LANGUAGE_FOREIGN = 'foreign';

    public const LANGUAGE_UNKNOWN = 'unknown';

    public const VARIANT_TYPE_FULL = 'full';

    public const VARIANT_TYPE_SHORT = 'short';

    public const VARIANT_TYPE_SPOKEN = 'spoken';

    public const VARIANT_TYPE_TRANSLIT = 'translit';

    public const VARIANT_TYPE_YO = 'yo';

    public const VARIANT_TYPE_OTHER = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dictionary_key',
        'lookup_value',
        'lookup_normalized',
        'result_value',
        'result_normalized',
        'gender',
        'language',
        'variant_type',
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
            $entry->result_normalized = self::normalizeLookupValue($entry->result_value);
            $entry->gender = self::normalizeGender($entry->gender);
            $entry->language = self::normalizeLanguage($entry->language);
            $entry->variant_type = self::normalizeVariantType($entry->variant_type);
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
        $gender = self::normalizeDictionaryText($gender);

        return match ($gender) {
            self::GENDER_MALE, 'мужской', 'муж', 'м' => self::GENDER_MALE,
            self::GENDER_FEMALE, 'женский', 'жен', 'ж' => self::GENDER_FEMALE,
            self::GENDER_UNKNOWN, 'непонятно', 'неизвестно', 'неизвестный' => self::GENDER_UNKNOWN,
            default => self::GENDER_UNKNOWN,
        };
    }

    public static function normalizeContactGender(mixed $gender): ?string
    {
        $gender = self::normalizeDictionaryText($gender);

        return match ($gender) {
            '' => null,
            self::GENDER_MALE, 'мужской', 'муж', 'м' => self::GENDER_MALE,
            self::GENDER_FEMALE, 'женский', 'жен', 'ж' => self::GENDER_FEMALE,
            self::GENDER_UNKNOWN, 'непонятно', 'неизвестно', 'неизвестный' => self::GENDER_UNKNOWN,
            default => null,
        };
    }

    public static function normalizeLookupGender(mixed $gender): string
    {
        $gender = self::normalizeDictionaryText($gender);

        return match ($gender) {
            self::GENDER_MALE, 'мужской', 'муж', 'м' => self::GENDER_MALE,
            self::GENDER_FEMALE, 'женский', 'жен', 'ж' => self::GENDER_FEMALE,
            default => self::GENDER_UNKNOWN,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function languageOptions(): array
    {
        return [
            self::LANGUAGE_RU => 'Русское',
            self::LANGUAGE_FOREIGN => 'Иностранное',
            self::LANGUAGE_UNKNOWN => 'Непонятно',
        ];
    }

    public static function languageLabel(?string $language): string
    {
        return self::languageOptions()[self::normalizeLanguage($language)] ?? 'Непонятно';
    }

    public static function normalizeLanguage(mixed $language): string
    {
        $language = self::normalizeDictionaryText($language);

        return match ($language) {
            '', self::LANGUAGE_RU, 'русское', 'русский', 'ru' => self::LANGUAGE_RU,
            self::LANGUAGE_FOREIGN, 'иностранное', 'иностранный', 'foreign' => self::LANGUAGE_FOREIGN,
            self::LANGUAGE_UNKNOWN, 'непонятно', 'неизвестно', 'unknown' => self::LANGUAGE_UNKNOWN,
            default => self::LANGUAGE_RU,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function variantTypeOptions(): array
    {
        return [
            self::VARIANT_TYPE_FULL => 'Полное',
            self::VARIANT_TYPE_SHORT => 'Краткое',
            self::VARIANT_TYPE_SPOKEN => 'Разговорное',
            self::VARIANT_TYPE_TRANSLIT => 'Транслит',
            self::VARIANT_TYPE_YO => 'Е/Ё',
            self::VARIANT_TYPE_OTHER => 'Другое',
        ];
    }

    public static function variantTypeLabel(?string $variantType): string
    {
        return self::variantTypeOptions()[self::normalizeVariantType($variantType)] ?? 'Другое';
    }

    public static function normalizeVariantType(mixed $variantType): string
    {
        $variantType = Str::of(self::normalizeDictionaryText($variantType))
            ->replace('/', '')
            ->toString();

        return match ($variantType) {
            self::VARIANT_TYPE_FULL, 'полное', 'полный' => self::VARIANT_TYPE_FULL,
            '', self::VARIANT_TYPE_SHORT, 'краткое', 'краткий' => self::VARIANT_TYPE_SHORT,
            self::VARIANT_TYPE_SPOKEN, 'разговорное', 'разговорный' => self::VARIANT_TYPE_SPOKEN,
            self::VARIANT_TYPE_TRANSLIT, 'транслит' => self::VARIANT_TYPE_TRANSLIT,
            self::VARIANT_TYPE_YO, 'ее', 'её' => self::VARIANT_TYPE_YO,
            self::VARIANT_TYPE_OTHER, 'другое', 'другой' => self::VARIANT_TYPE_OTHER,
            default => self::VARIANT_TYPE_OTHER,
        };
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

    private static function normalizeDictionaryText(mixed $value): string
    {
        return Str::of(is_scalar($value) ? (string) $value : '')
            ->trim()
            ->lower()
            ->replace('ё', 'е')
            ->toString();
    }
}
