<?php

namespace App\Services\Scenarios;

use App\Models\DataDictionaryEntry;

class LookupScenarioDataDictionaryAction
{
    /**
     * @return array{matched: bool, value: ?string}
     */
    public function handle(string $dictionaryKey, string $rawValue): array
    {
        $lookup = DataDictionaryEntry::normalizeLookupValue($rawValue);

        if ($dictionaryKey !== DataDictionaryEntry::DICTIONARY_NAMES || $lookup === '') {
            return ['matched' => false, 'value' => null];
        }

        $rows = DataDictionaryEntry::query()
            ->where('dictionary_key', $dictionaryKey)
            ->where('lookup_normalized', $lookup)
            ->where('is_active', true)
            ->where('auto_apply', true)
            ->limit(2)
            ->get(['result_value']);

        if ($rows->count() !== 1) {
            return ['matched' => false, 'value' => null];
        }

        $value = trim((string) $rows->first()?->result_value);

        return $value !== ''
            ? ['matched' => true, 'value' => $value]
            : ['matched' => false, 'value' => null];
    }
}
