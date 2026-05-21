<?php

namespace App\Services\DataDictionaries;

use App\Models\DataDictionaryEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;

class ImportDataDictionaryEntriesCsvAction
{
    /**
     * @return array{created:int, updated:int, skipped:int}
     */
    public function handle(string $path): array
    {
        $reader = Reader::from($path, 'r');
        $reader->setDelimiter($this->detectDelimiter($path));
        $reader->setHeaderOffset(0);

        try {
            $records = iterator_to_array($reader->getRecords());
        } catch (CsvException) {
            throw ValidationException::withMessages([
                'csv' => 'Не удалось прочитать CSV-файл. Проверьте, что первая строка содержит заголовки столбцов.',
            ]);
        }

        [$rows, $errors] = $this->normalizeRows($records);

        if ($errors !== []) {
            $this->throwValidationErrors($errors);
        }

        return DB::transaction(function () use ($rows): array {
            $summary = [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
            ];
            $errors = [];
            $naturalKeys = [];
            $payloads = [];
            $rowNumbers = [];

            foreach ($rows as $row) {
                if ($row['is_empty']) {
                    $summary['skipped']++;

                    continue;
                }

                $entry = $this->resolveEntry($row, $errors);
                $entryKey = $entry instanceof DataDictionaryEntry ? 'id:'.$entry->id : 'new:'.$this->naturalKey($row);
                $naturalKey = $this->naturalKey($row);
                $payload = $this->entryPayload($row);

                if (isset($naturalKeys[$naturalKey]) && $naturalKeys[$naturalKey] !== $entryKey) {
                    $errors[] = sprintf('Строка %d: обновление создаёт дубль варианта имени.', $row['row_number']);
                }

                if (isset($payloads[$entryKey])) {
                    if ($payloads[$entryKey] !== $payload) {
                        $errors[] = sprintf('Строка %d: одна и та же строка справочника указана с разными значениями.', $row['row_number']);
                    }

                    continue;
                }

                $naturalKeys[$naturalKey] = $entryKey;
                $payloads[$entryKey] = $payload;
                $rowNumbers[$entryKey] = $row['row_number'];
            }

            $this->assertNoAutoApplyConflicts($payloads, $rowNumbers, $errors);

            if ($errors !== []) {
                $this->throwValidationErrors($errors);
            }

            foreach ($payloads as $entryKey => $payload) {
                if (str_starts_with($entryKey, 'id:')) {
                    $entryId = (int) Str::after($entryKey, 'id:');
                    $entry = DataDictionaryEntry::query()->find($entryId);

                    if (! $entry instanceof DataDictionaryEntry) {
                        continue;
                    }

                    $entry->fill($payload);

                    if ($entry->isDirty()) {
                        $entry->save();
                        $summary['updated']++;
                    } else {
                        $summary['skipped']++;
                    }

                    continue;
                }

                DataDictionaryEntry::query()->create($payload);
                $summary['created']++;
            }

            return $summary;
        });
    }

    protected function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ',';
        }

        $firstLine = fgets($handle) ?: '';
        fclose($handle);

        $delimiters = [
            ',' => substr_count($firstLine, ','),
            ';' => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];

        arsort($delimiters);

        return array_key_first($delimiters) ?: ',';
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $records
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    protected function normalizeRows(array $records): array
    {
        $rows = [];
        $errors = [];

        foreach ($records as $offset => $record) {
            $normalized = $this->normalizeRecord($record);
            $rowNumber = is_int($offset) ? $offset + 1 : count($rows) + 2;
            $rawValues = array_map(fn (mixed $value): string => trim((string) $value), $normalized);
            $isEmpty = $rawValues === [] || collect($rawValues)->every(fn (string $value): bool => $value === '');

            if ($isEmpty) {
                $rows[] = [
                    'row_number' => $rowNumber,
                    'is_empty' => true,
                ];

                continue;
            }

            $variant = trim((string) ($normalized['variant'] ?? ''));
            $fullName = trim((string) ($normalized['full_name'] ?? ''));
            $gender = $this->normalizeRequiredGender($normalized['gender'] ?? null, $rowNumber, $errors);
            $language = $this->normalizeLanguage($normalized['language'] ?? null, $rowNumber, $errors);
            $variantType = $this->normalizeVariantType($normalized['variant_type'] ?? null, $rowNumber, $errors);
            $autoApply = $this->normalizeBoolean($normalized['auto_apply'] ?? null, $rowNumber, 'Авто', $errors);
            $isActive = $this->normalizeBoolean($normalized['is_active'] ?? null, $rowNumber, 'Активно', $errors);
            $normalizedVariant = DataDictionaryEntry::normalizeLookupValue($variant);
            $normalizedFullName = DataDictionaryEntry::normalizeLookupValue($fullName);

            if ($variant === '') {
                $errors[] = sprintf('Строка %d: заполните «Вариант».', $rowNumber);
            }

            if ($fullName === '') {
                $errors[] = sprintf('Строка %d: заполните «Полное имя».', $rowNumber);
            }

            if ($variant !== '' && $normalizedVariant === '') {
                $errors[] = sprintf('Строка %d: «Вариант» должен быть одним словом из букв.', $rowNumber);
            }

            if ($fullName !== '' && $normalizedFullName === '') {
                $errors[] = sprintf('Строка %d: «Полное имя» должно быть одним словом из букв.', $rowNumber);
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'is_empty' => false,
                'id' => $this->nullableInteger($normalized['id'] ?? null, $rowNumber, 'ID', $errors),
                'variant' => $variant,
                'normalized_variant' => $normalizedVariant,
                'full_name' => $fullName,
                'normalized_full_name' => $normalizedFullName,
                'gender' => $gender,
                'language' => $language,
                'variant_type' => $variantType,
                'auto_apply' => $autoApply,
                'is_active' => $isActive,
                'comment' => $this->nullableString($normalized['comment'] ?? null),
            ];
        }

        return [$rows, $errors];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function normalizeRecord(array $record): array
    {
        $normalized = [];

        foreach ($record as $header => $value) {
            $key = $this->canonicalColumnKey((string) $header);

            if ($key !== null) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    protected function canonicalColumnKey(string $header): ?string
    {
        $key = Str::of($header)
            ->replace("\xEF\xBB\xBF", '')
            ->lower()
            ->replace('ё', 'е')
            ->replaceMatches('/[^\p{L}\p{N}]+/u', '')
            ->toString();

        return match ($key) {
            'id' => 'id',
            'вариант', 'вариантотклиента', 'lookupvalue', 'variant' => 'variant',
            'полноеимя', 'resultvalue', 'fullname', 'firstname' => 'full_name',
            'пол', 'gender' => 'gender',
            'язык', 'language' => 'language',
            'типварианта', 'varianttype' => 'variant_type',
            'авто', 'auto', 'autoapply' => 'auto_apply',
            'активно', 'active', 'isactive' => 'is_active',
            'комментарий', 'comment' => 'comment',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function resolveEntry(array $row, array &$errors): ?DataDictionaryEntry
    {
        if ($row['id'] !== null) {
            $entry = DataDictionaryEntry::query()->find($row['id']);

            if (! $entry instanceof DataDictionaryEntry) {
                $errors[] = sprintf('Строка %d: строка справочника с таким ID не найдена.', $row['row_number']);

                return null;
            }

            $conflictExists = $this->naturalKeyQuery($row)
                ->whereKeyNot($entry->id)
                ->exists();

            if ($conflictExists) {
                $errors[] = sprintf('Строка %d: обновление создаёт дубль варианта имени.', $row['row_number']);
            }

            return $entry;
        }

        return $this->naturalKeyQuery($row)->first();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function naturalKeyQuery(array $row)
    {
        return DataDictionaryEntry::query()
            ->where('dictionary_key', DataDictionaryEntry::DICTIONARY_NAMES)
            ->where('lookup_normalized', $row['normalized_variant'])
            ->where('result_normalized', $row['normalized_full_name'])
            ->where('gender', $row['gender'])
            ->where('language', $row['language']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function naturalKey(array $row): string
    {
        return implode('|', [
            DataDictionaryEntry::DICTIONARY_NAMES,
            $row['normalized_variant'],
            $row['normalized_full_name'],
            $row['gender'],
            $row['language'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function entryPayload(array $row): array
    {
        return [
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => $row['variant'],
            'result_value' => $row['full_name'],
            'gender' => $row['gender'],
            'language' => $row['language'],
            'variant_type' => $row['variant_type'],
            'auto_apply' => $row['auto_apply'],
            'is_active' => $row['is_active'],
            'comment' => $row['comment'],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $payloads
     * @param  array<string, int>  $rowNumbers
     * @param  list<string>  $errors
     */
    protected function assertNoAutoApplyConflicts(array $payloads, array $rowNumbers, array &$errors): void
    {
        $updatedEntryIds = collect(array_keys($payloads))
            ->filter(fn (string $entryKey): bool => str_starts_with($entryKey, 'id:'))
            ->map(fn (string $entryKey): int => (int) Str::after($entryKey, 'id:'))
            ->values();

        $groups = [];

        foreach ($payloads as $entryKey => $payload) {
            if ($payload['auto_apply'] !== true || $payload['is_active'] !== true) {
                continue;
            }

            $lookupNormalized = DataDictionaryEntry::normalizeLookupValue((string) $payload['lookup_value']);
            $resultNormalized = DataDictionaryEntry::normalizeLookupValue((string) $payload['result_value']);

            if ($lookupNormalized === '' || $resultNormalized === '') {
                continue;
            }

            $groupKey = implode('|', [
                $payload['dictionary_key'],
                $lookupNormalized,
                $payload['gender'],
                $payload['language'],
            ]);

            $groups[$groupKey] ??= [
                'dictionary_key' => $payload['dictionary_key'],
                'lookup_normalized' => $lookupNormalized,
                'gender' => $payload['gender'],
                'language' => $payload['language'],
                'entries' => [],
            ];
            $groups[$groupKey]['entries'][] = [
                'row_number' => $rowNumbers[$entryKey] ?? null,
                'result_normalized' => $resultNormalized,
                'result_value' => $payload['result_value'],
            ];
        }

        foreach ($groups as $group) {
            $existingEntries = DataDictionaryEntry::query()
                ->where('dictionary_key', $group['dictionary_key'])
                ->where('lookup_normalized', $group['lookup_normalized'])
                ->where('gender', $group['gender'])
                ->where('language', $group['language'])
                ->where('auto_apply', true)
                ->where('is_active', true)
                ->when(
                    $updatedEntryIds->isNotEmpty(),
                    fn ($query) => $query->whereNotIn('id', $updatedEntryIds),
                )
                ->get(['id', 'result_value', 'result_normalized']);

            $results = collect($group['entries'])
                ->mapWithKeys(fn (array $entry): array => [
                    $entry['result_normalized'] => (string) $entry['result_value'],
                ]);

            foreach ($existingEntries as $entry) {
                $results[(string) $entry->result_normalized] = (string) $entry->result_value;
            }

            if ($results->count() <= 1) {
                continue;
            }

            $rowNumbersText = collect($group['entries'])
                ->pluck('row_number')
                ->filter()
                ->unique()
                ->implode(', ');
            $resultValues = $results->values()->unique()->implode(', ');

            $errors[] = sprintf(
                'Строка %s: «Авто=да» создаёт неоднозначное автоприменение для варианта «%s» (%s). Оставьте один вариант или поставьте «Авто=нет».',
                $rowNumbersText !== '' ? $rowNumbersText : '?',
                $group['lookup_normalized'],
                $resultValues,
            );
        }
    }

    protected function nullableInteger(mixed $value, int $rowNumber, string $field, array &$errors): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $errors[] = sprintf('Строка %d: поле «%s» должно быть числом или пустым.', $rowNumber, $field);

        return null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function normalizeRequiredGender(mixed $value, int $rowNumber, array &$errors): string
    {
        $normalized = $this->normalizeText($value);

        return match ($normalized) {
            DataDictionaryEntry::GENDER_MALE, 'мужской', 'муж', 'м' => DataDictionaryEntry::GENDER_MALE,
            DataDictionaryEntry::GENDER_FEMALE, 'женский', 'жен', 'ж' => DataDictionaryEntry::GENDER_FEMALE,
            DataDictionaryEntry::GENDER_UNKNOWN, 'непонятно', 'неизвестно', 'неизвестный' => DataDictionaryEntry::GENDER_UNKNOWN,
            '' => $this->invalidValue($rowNumber, 'Пол', $errors, DataDictionaryEntry::GENDER_UNKNOWN),
            default => $this->invalidValue($rowNumber, 'Пол', $errors, DataDictionaryEntry::GENDER_UNKNOWN),
        };
    }

    protected function normalizeLanguage(mixed $value, int $rowNumber, array &$errors): string
    {
        $normalized = $this->normalizeText($value);

        return match ($normalized) {
            '' => DataDictionaryEntry::LANGUAGE_RU,
            DataDictionaryEntry::LANGUAGE_RU, 'русское', 'русский', 'ru' => DataDictionaryEntry::LANGUAGE_RU,
            DataDictionaryEntry::LANGUAGE_FOREIGN, 'иностранное', 'иностранный', 'foreign' => DataDictionaryEntry::LANGUAGE_FOREIGN,
            DataDictionaryEntry::LANGUAGE_UNKNOWN, 'непонятно', 'неизвестно', 'unknown' => DataDictionaryEntry::LANGUAGE_UNKNOWN,
            default => $this->invalidValue($rowNumber, 'Язык', $errors, DataDictionaryEntry::LANGUAGE_RU),
        };
    }

    protected function normalizeVariantType(mixed $value, int $rowNumber, array &$errors): string
    {
        $normalized = Str::of($this->normalizeText($value))
            ->replace('/', '')
            ->toString();

        return match ($normalized) {
            '' => DataDictionaryEntry::VARIANT_TYPE_SHORT,
            DataDictionaryEntry::VARIANT_TYPE_FULL, 'полное', 'полный' => DataDictionaryEntry::VARIANT_TYPE_FULL,
            DataDictionaryEntry::VARIANT_TYPE_SHORT, 'краткое', 'краткий' => DataDictionaryEntry::VARIANT_TYPE_SHORT,
            DataDictionaryEntry::VARIANT_TYPE_SPOKEN, 'разговорное', 'разговорный' => DataDictionaryEntry::VARIANT_TYPE_SPOKEN,
            DataDictionaryEntry::VARIANT_TYPE_TRANSLIT, 'транслит' => DataDictionaryEntry::VARIANT_TYPE_TRANSLIT,
            DataDictionaryEntry::VARIANT_TYPE_YO, 'ее', 'её' => DataDictionaryEntry::VARIANT_TYPE_YO,
            DataDictionaryEntry::VARIANT_TYPE_OTHER, 'другое', 'другой' => DataDictionaryEntry::VARIANT_TYPE_OTHER,
            default => $this->invalidValue($rowNumber, 'Тип варианта', $errors, DataDictionaryEntry::VARIANT_TYPE_SHORT),
        };
    }

    protected function normalizeBoolean(mixed $value, int $rowNumber, string $field, array &$errors): bool
    {
        $normalized = $this->normalizeText($value);

        return match ($normalized) {
            '' => true,
            '1', 'true', 'yes', 'y', 'да', 'д', 'истина', 'вкл', 'on' => true,
            '0', 'false', 'no', 'n', 'нет', 'н', 'ложь', 'выкл', 'off' => false,
            default => $this->invalidValue($rowNumber, $field, $errors, true),
        };
    }

    protected function normalizeText(mixed $value): string
    {
        return Str::of(is_scalar($value) ? (string) $value : '')
            ->trim()
            ->lower()
            ->replace('ё', 'е')
            ->toString();
    }

    protected function invalidValue(int $rowNumber, string $field, array &$errors, mixed $fallback): mixed
    {
        $errors[] = sprintf('Строка %d: недопустимое значение в поле «%s».', $rowNumber, $field);

        return $fallback;
    }

    /**
     * @param  list<string>  $errors
     */
    protected function throwValidationErrors(array $errors): never
    {
        throw ValidationException::withMessages([
            'csv' => implode(PHP_EOL, array_slice($errors, 0, 20)),
        ]);
    }
}
