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
    public function handle(string $path, string $dictionaryKey = DataDictionaryEntry::DICTIONARY_NAMES): array
    {
        $reader = Reader::from($path, 'r');
        $reader->setDelimiter($this->detectDelimiter($path));
        $reader->setHeaderOffset(0);

        try {
            $records = iterator_to_array($reader->getRecords());
        } catch (CsvException $exception) {
            throw ValidationException::withMessages([
                'csv' => 'Не удалось прочитать CSV-файл. Проверьте, что первая строка содержит заголовки столбцов.',
            ]);
        }

        $rows = $this->normalizeRows($records);
        $this->validateRows($rows);

        $summary = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        DB::transaction(function () use ($rows, $dictionaryKey, &$summary): void {
            foreach ($rows as $row) {
                if ($row['is_empty']) {
                    $summary['skipped']++;

                    continue;
                }

                $entry = $this->resolveExistingEntry($row, $dictionaryKey);
                $exists = $entry instanceof DataDictionaryEntry;

                $entry ??= new DataDictionaryEntry;
                $entry->fill([
                    'dictionary_key' => $dictionaryKey,
                    'lookup_value' => $row['lookup_value'],
                    'result_value' => $row['result_value'],
                    'gender' => $row['gender'] ?? ($exists ? $entry->gender : DataDictionaryEntry::GENDER_UNKNOWN),
                    'auto_apply' => $row['auto_apply'] ?? ($exists ? $entry->auto_apply : true),
                    'is_active' => $row['is_active'] ?? ($exists ? $entry->is_active : true),
                    'comment' => $row['comment'],
                ]);
                $entry->save();

                $summary[$exists ? 'updated' : 'created']++;
            }
        });

        return $summary;
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
     * @return list<array{
     *     row_number:int,
     *     id:int|null,
     *     lookup_value:string,
     *     lookup_normalized:string,
     *     result_value:string,
     *     gender:string|null,
     *     auto_apply:bool|null,
     *     is_active:bool|null,
     *     comment:string|null,
     *     is_empty:bool
     * }>
     */
    protected function normalizeRows(array $records): array
    {
        $rows = [];

        foreach ($records as $offset => $record) {
            $normalized = $this->normalizeRecord($record);
            $rowNumber = is_int($offset) ? $offset + 1 : count($rows) + 2;

            $id = $this->nullableInteger($normalized['id'] ?? null);
            $lookupValue = trim((string) ($normalized['lookup_value'] ?? ''));
            $resultValue = trim((string) ($normalized['result_value'] ?? ''));
            $gender = $this->nullableGender($normalized['gender'] ?? null);
            $autoApply = $this->nullableBoolean($normalized['auto_apply'] ?? null);
            $isActive = $this->nullableBoolean($normalized['is_active'] ?? null);
            $comment = $this->nullableString($normalized['comment'] ?? null);

            $rows[] = [
                'row_number' => $rowNumber,
                'id' => $id,
                'lookup_value' => $lookupValue,
                'lookup_normalized' => DataDictionaryEntry::normalizeLookupValue($lookupValue),
                'result_value' => $resultValue,
                'gender' => $gender,
                'auto_apply' => $autoApply,
                'is_active' => $isActive,
                'comment' => $comment,
                'is_empty' => $id === null
                    && $lookupValue === ''
                    && $resultValue === ''
                    && $comment === null
                    && $gender === null
                    && $autoApply === null
                    && $isActive === null,
            ];
        }

        return $rows;
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
            'id', 'ид', 'айди' => 'id',
            'lookupvalue', 'variant', 'вариант', 'вариантотклиента', 'имяотклиента' => 'lookup_value',
            'resultvalue', 'fullname', 'firstname', 'полноеимя', 'имя' => 'result_value',
            'gender', 'пол' => 'gender',
            'autoapply', 'auto', 'авто', 'автоматическиприменять' => 'auto_apply',
            'isactive', 'active', 'активно', 'активность' => 'is_active',
            'comment', 'комментарий' => 'comment',
            default => null,
        };
    }

    /**
     * @param  list<array{row_number:int, id:int|null, lookup_value:string, lookup_normalized:string, result_value:string, comment:string|null, is_empty:bool}>  $rows
     */
    protected function validateRows(array $rows): void
    {
        $errors = [];
        $seen = [];

        foreach ($rows as $row) {
            if ($row['is_empty']) {
                continue;
            }

            if ($row['lookup_value'] === '') {
                $errors[] = sprintf('Строка %d: заполните «Вариант от клиента».', $row['row_number']);
            } elseif ($row['lookup_normalized'] === '') {
                $errors[] = sprintf('Строка %d: «Вариант от клиента» должен быть одним словом из букв.', $row['row_number']);
            }

            if ($row['result_value'] === '') {
                $errors[] = sprintf('Строка %d: заполните «Полное имя».', $row['row_number']);
            }

            if (mb_strlen($row['lookup_value']) > 255 || mb_strlen($row['result_value']) > 255) {
                $errors[] = sprintf('Строка %d: имя должно быть не длиннее 255 символов.', $row['row_number']);
            }

            if ($row['comment'] !== null && mb_strlen($row['comment']) > 1000) {
                $errors[] = sprintf('Строка %d: комментарий должен быть не длиннее 1000 символов.', $row['row_number']);
            }

            if ($row['lookup_normalized'] !== '') {
                if (isset($seen[$row['lookup_normalized']])) {
                    $errors[] = sprintf(
                        'Строка %d: вариант уже есть в строке %d.',
                        $row['row_number'],
                        $seen[$row['lookup_normalized']],
                    );
                }

                $seen[$row['lookup_normalized']] = $row['row_number'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'csv' => implode(PHP_EOL, array_slice($errors, 0, 20)),
            ]);
        }
    }

    /**
     * @param  array{id:int|null, lookup_normalized:string}  $row
     */
    protected function resolveExistingEntry(array $row, string $dictionaryKey): ?DataDictionaryEntry
    {
        if ($row['id'] !== null) {
            $entry = DataDictionaryEntry::query()
                ->where('dictionary_key', $dictionaryKey)
                ->find($row['id']);

            if ($entry instanceof DataDictionaryEntry) {
                return $entry;
            }
        }

        return DataDictionaryEntry::query()
            ->where('dictionary_key', $dictionaryKey)
            ->where('lookup_normalized', $row['lookup_normalized'])
            ->orderBy('id')
            ->first();
    }

    protected function nullableInteger(mixed $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return ctype_digit($value) ? (int) $value : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function nullableGender(mixed $value): ?string
    {
        $value = Str::of(trim((string) $value))
            ->lower()
            ->replace('ё', 'е')
            ->toString();

        return match ($value) {
            '' => null,
            DataDictionaryEntry::GENDER_MALE, 'мужской', 'муж', 'м' => DataDictionaryEntry::GENDER_MALE,
            DataDictionaryEntry::GENDER_FEMALE, 'женский', 'жен', 'ж' => DataDictionaryEntry::GENDER_FEMALE,
            DataDictionaryEntry::GENDER_UNKNOWN, 'непонятно', 'неизвестно', 'неизвестный' => DataDictionaryEntry::GENDER_UNKNOWN,
            default => DataDictionaryEntry::GENDER_UNKNOWN,
        };
    }

    protected function nullableBoolean(mixed $value): ?bool
    {
        $value = Str::of(trim((string) $value))
            ->lower()
            ->replace('ё', 'е')
            ->toString();

        return match ($value) {
            '' => null,
            '1', 'true', 'yes', 'y', 'да', 'д', 'истина', 'вкл', 'on' => true,
            '0', 'false', 'no', 'n', 'нет', 'н', 'ложь', 'выкл', 'off' => false,
            default => null,
        };
    }
}
