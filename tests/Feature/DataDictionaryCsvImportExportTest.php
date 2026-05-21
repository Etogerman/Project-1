<?php

namespace Tests\Feature;

use App\Models\DataDictionaryEntry;
use App\Services\DataDictionaries\ExportDataDictionaryEntriesCsvAction;
use App\Services\DataDictionaries\ImportDataDictionaryEntriesCsvAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DataDictionaryCsvImportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_names_dictionary_as_csv(): void
    {
        DataDictionaryEntry::query()->create([
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Вася',
            'result_value' => 'Василий',
            'gender' => DataDictionaryEntry::GENDER_MALE,
            'auto_apply' => true,
            'is_active' => true,
            'comment' => 'Основной вариант',
        ]);

        $csv = app(ExportDataDictionaryEntriesCsvAction::class)->handle();

        $this->assertStringStartsWith("\xEF\xBB\xBFID,Вариант от клиента,Полное имя,Пол,Авто,Активно,Комментарий", $csv);
        $this->assertStringContainsString('Вася,Василий,Мужской,да,да,Основной вариант', $csv);
    }

    public function test_import_names_csv_creates_and_updates_rows(): void
    {
        DataDictionaryEntry::query()->create([
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Вася',
            'result_value' => 'Василий',
            'gender' => DataDictionaryEntry::GENDER_MALE,
            'auto_apply' => true,
            'is_active' => true,
        ]);

        $path = $this->writeTempCsv(<<<'CSV'
ID;Вариант от клиента;Полное имя;Пол;Авто;Активно;Комментарий
;Вася;Василий Новый;Мужской;нет;да;обновили
;Клава;Клавдия;Женский;да;да;
;;;;;;
CSV);

        try {
            $summary = app(ImportDataDictionaryEntriesCsvAction::class)->handle($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(['created' => 1, 'updated' => 1, 'skipped' => 1], $summary);

        $this->assertDatabaseHas('data_dictionary_entries', [
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Вася',
            'lookup_normalized' => 'вася',
            'result_value' => 'Василий Новый',
            'gender' => DataDictionaryEntry::GENDER_MALE,
            'auto_apply' => false,
            'is_active' => true,
            'comment' => 'обновили',
        ]);
        $this->assertDatabaseHas('data_dictionary_entries', [
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Клава',
            'lookup_normalized' => 'клава',
            'result_value' => 'Клавдия',
            'gender' => DataDictionaryEntry::GENDER_FEMALE,
            'auto_apply' => true,
            'is_active' => true,
        ]);
    }

    public function test_import_names_csv_rejects_duplicate_lookup_values(): void
    {
        $path = $this->writeTempCsv(<<<'CSV'
Вариант от клиента,Полное имя,Пол
Тема,Артём,Мужской
Тёма,Артём,Мужской
CSV);

        try {
            $this->expectException(ValidationException::class);

            app(ImportDataDictionaryEntriesCsvAction::class)->handle($path);
        } finally {
            @unlink($path);
        }
    }

    private function writeTempCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dictionary-csv-');

        $this->assertIsString($path);

        file_put_contents($path, $contents.PHP_EOL);

        return $path;
    }
}
