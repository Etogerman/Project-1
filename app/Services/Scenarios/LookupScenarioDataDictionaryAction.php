<?php

namespace App\Services\Scenarios;

use App\Models\DataDictionaryEntry;

class LookupScenarioDataDictionaryAction
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_MANUAL_REQUIRED = 'manual_required';

    public const STATUS_NOT_FOUND = 'not_found';

    /**
     * @return array{
     *     status: string,
     *     matched: bool,
     *     value: ?string,
     *     matched_entry_id: ?int
     * }
     */
    public function handle(string $dictionaryKey, string $rawValue, mixed $contactGender = null): array
    {
        $lookup = DataDictionaryEntry::normalizeLookupValue($rawValue);

        if ($dictionaryKey !== DataDictionaryEntry::DICTIONARY_NAMES || $lookup === '') {
            return $this->result(self::STATUS_NOT_FOUND);
        }

        $entries = DataDictionaryEntry::query()
            ->where('dictionary_key', $dictionaryKey)
            ->where('lookup_normalized', $lookup)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($entries->isEmpty()) {
            return $this->result(self::STATUS_NOT_FOUND);
        }

        $lookupGender = DataDictionaryEntry::normalizeLookupGender($contactGender);

        if (in_array($lookupGender, [DataDictionaryEntry::GENDER_MALE, DataDictionaryEntry::GENDER_FEMALE], true)) {
            $entries = $entries
                ->filter(fn (DataDictionaryEntry $entry): bool => in_array($entry->gender, [
                    $lookupGender,
                    DataDictionaryEntry::GENDER_UNKNOWN,
                ], true))
                ->values();

            if ($entries->isEmpty()) {
                return $this->result(self::STATUS_AMBIGUOUS);
            }
        }

        $automaticEntries = $entries
            ->filter(fn (DataDictionaryEntry $entry): bool => $entry->auto_apply === true)
            ->values();

        if ($automaticEntries->isEmpty()) {
            return $this->result(self::STATUS_MANUAL_REQUIRED);
        }

        $resultKeys = $automaticEntries
            ->map(fn (DataDictionaryEntry $entry): string => implode('|', [
                $entry->result_normalized,
                $entry->gender,
                $entry->language,
            ]))
            ->unique()
            ->values();

        if ($resultKeys->count() !== 1) {
            return $this->result(self::STATUS_AMBIGUOUS);
        }

        $matchedEntry = $automaticEntries->first();
        $value = trim((string) ($matchedEntry?->result_value ?? ''));

        if (! $matchedEntry instanceof DataDictionaryEntry || $value === '') {
            return $this->result(self::STATUS_NOT_FOUND);
        }

        return $this->result(
            self::STATUS_MATCHED,
            $value,
            (int) $matchedEntry->id,
        );
    }

    /**
     * @return array{status: string, matched: bool, value: ?string, matched_entry_id: ?int}
     */
    private function result(
        string $status,
        ?string $value = null,
        ?int $matchedEntryId = null,
    ): array {
        return [
            'status' => $status,
            'matched' => $status === self::STATUS_MATCHED,
            'value' => $value,
            'matched_entry_id' => $matchedEntryId,
        ];
    }
}
