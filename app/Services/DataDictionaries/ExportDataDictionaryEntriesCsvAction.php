<?php

namespace App\Services\DataDictionaries;

use App\Models\DataDictionaryEntry;
use League\Csv\Writer;

class ExportDataDictionaryEntriesCsvAction
{
    public function handle(string $dictionaryKey = DataDictionaryEntry::DICTIONARY_NAMES): string
    {
        $csv = Writer::fromString('');
        $csv->insertOne([
            'ID',
            'Вариант от клиента',
            'Полное имя',
            'Пол',
            'Авто',
            'Активно',
            'Комментарий',
        ]);

        DataDictionaryEntry::query()
            ->where('dictionary_key', $dictionaryKey)
            ->orderBy('lookup_value')
            ->each(function (DataDictionaryEntry $entry) use ($csv): void {
                $csv->insertOne([
                    $entry->id,
                    $entry->lookup_value,
                    $entry->result_value,
                    DataDictionaryEntry::genderLabel($entry->gender),
                    $entry->auto_apply ? 'да' : 'нет',
                    $entry->is_active ? 'да' : 'нет',
                    $entry->comment,
                ]);
            });

        return "\xEF\xBB\xBF".$csv->toString();
    }
}
