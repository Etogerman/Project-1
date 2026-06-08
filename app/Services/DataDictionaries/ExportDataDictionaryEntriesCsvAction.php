<?php

namespace App\Services\DataDictionaries;

use App\Models\DataDictionaryEntry;
use League\Csv\Writer;

class ExportDataDictionaryEntriesCsvAction
{
    public function handle(): string
    {
        $csv = Writer::fromString('');
        $csv->insertOne([
            'ID',
            'Вариант',
            'Полное имя',
            'Пол',
            'Язык',
            'Тип варианта',
            'Авто',
            'Активно',
            'Комментарий',
        ]);

        DataDictionaryEntry::query()
            ->where('dictionary_key', DataDictionaryEntry::DICTIONARY_NAMES)
            ->orderBy('lookup_value')
            ->orderBy('result_value')
            ->each(function (DataDictionaryEntry $entry) use ($csv): void {
                $csv->insertOne([
                    $entry->id,
                    $this->safeCsvCell($entry->lookup_value),
                    $this->safeCsvCell($entry->result_value),
                    DataDictionaryEntry::genderLabel($entry->gender),
                    DataDictionaryEntry::languageLabel($entry->language),
                    DataDictionaryEntry::variantTypeLabel($entry->variant_type),
                    $entry->auto_apply ? 'да' : 'нет',
                    $entry->is_active ? 'да' : 'нет',
                    $this->safeCsvCell($entry->comment),
                ]);
            });

        return "\xEF\xBB\xBF".$csv->toString();
    }

    private function safeCsvCell(?string $value): string
    {
        $value ??= '';

        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
