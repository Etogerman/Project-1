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
            'language' => DataDictionaryEntry::LANGUAGE_RU,
            'variant_type' => DataDictionaryEntry::VARIANT_TYPE_SHORT,
            'auto_apply' => true,
            'is_active' => true,
            'comment' => 'Основной вариант',
        ]);

        $csv = app(ExportDataDictionaryEntriesCsvAction::class)->handle();

        $this->assertStringStartsWith("\xEF\xBB\xBFID,Вариант,\"Полное имя\",Пол", $csv);
        $this->assertStringContainsString('Вася,Василий,Мужской,Русское,Краткое,да,да,"Основной вариант"', $csv);
    }

    public function test_import_names_csv_creates_and_updates_rows(): void
    {
        DataDictionaryEntry::query()->create([
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Вася',
            'result_value' => 'Василий',
            'gender' => DataDictionaryEntry::GENDER_MALE,
            'language' => DataDictionaryEntry::LANGUAGE_RU,
            'variant_type' => DataDictionaryEntry::VARIANT_TYPE_SHORT,
            'auto_apply' => true,
            'is_active' => true,
        ]);

        $path = $this->writeTempCsv(<<<'CSV'
ID;Вариант;Полное имя;Пол;Язык;Тип варианта;Авто;Активно;Комментарий
;Вася;Василий;Мужской;Русское;Краткое;нет;да;обновили
;Клава;Клавдия;Женский;Русское;Краткое;да;да;
;;;;;;;;;
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
            'result_value' => 'Василий',
            'result_normalized' => 'василий',
            'gender' => DataDictionaryEntry::GENDER_MALE,
            'language' => DataDictionaryEntry::LANGUAGE_RU,
            'variant_type' => DataDictionaryEntry::VARIANT_TYPE_SHORT,
            'auto_apply' => false,
            'is_active' => true,
            'comment' => 'обновили',
        ]);
        $this->assertDatabaseHas('data_dictionary_entries', [
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Клава',
            'lookup_normalized' => 'клава',
            'result_value' => 'Клавдия',
            'result_normalized' => 'клавдия',
            'gender' => DataDictionaryEntry::GENDER_FEMALE,
        ]);
    }

    public function test_import_names_csv_allows_same_variant_for_different_names(): void
    {
        $path = $this->writeTempCsv(<<<'CSV'
Вариант;Полное имя;Пол;Тип варианта;Авто
Саша;Александр;Мужской;Краткое;да
Саша;Александра;Женский;Краткое;да
CSV);

        try {
            $summary = app(ImportDataDictionaryEntriesCsvAction::class)->handle($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(['created' => 2, 'updated' => 0, 'skipped' => 0], $summary);
        $this->assertDatabaseCount('data_dictionary_entries', 2);
    }

    public function test_import_names_csv_rejects_auto_apply_conflicts_in_same_gender_and_language(): void
    {
        $path = $this->writeTempCsv(<<<'CSV'
Вариант;Полное имя;Пол;Язык;Тип варианта;Авто
Alik;Али;Мужской;Русское;Транслит;да
Alik;Алихан;Мужской;Русское;Транслит;да
CSV);

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('неоднозначное автоприменение');

            app(ImportDataDictionaryEntriesCsvAction::class)->handle($path);
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseCount('data_dictionary_entries', 0);
    }

    public function test_import_names_csv_rejects_auto_apply_conflict_with_existing_row(): void
    {
        DataDictionaryEntry::query()->create([
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Алик',
            'result_value' => 'Али',
            'gender' => DataDictionaryEntry::GENDER_MALE,
            'language' => DataDictionaryEntry::LANGUAGE_RU,
            'variant_type' => DataDictionaryEntry::VARIANT_TYPE_SHORT,
            'auto_apply' => true,
            'is_active' => true,
        ]);

        $path = $this->writeTempCsv(<<<'CSV'
Вариант;Полное имя;Пол;Язык;Тип варианта;Авто
Алик;Алихан;Мужской;Русское;Краткое;да
CSV);

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('неоднозначное автоприменение');

            app(ImportDataDictionaryEntriesCsvAction::class)->handle($path);
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseCount('data_dictionary_entries', 1);
        $this->assertDatabaseMissing('data_dictionary_entries', [
            'lookup_value' => 'Алик',
            'result_value' => 'Алихан',
        ]);
    }

    public function test_import_names_csv_allows_ambiguous_variants_without_auto_apply(): void
    {
        $path = $this->writeTempCsv(<<<'CSV'
Вариант;Полное имя;Пол;Язык;Тип варианта;Авто
Alik;Али;Мужской;Русское;Транслит;нет
Alik;Алихан;Мужской;Русское;Транслит;нет
CSV);

        try {
            $summary = app(ImportDataDictionaryEntriesCsvAction::class)->handle($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(['created' => 2, 'updated' => 0, 'skipped' => 0], $summary);
        $this->assertDatabaseHas('data_dictionary_entries', [
            'lookup_value' => 'Alik',
            'result_value' => 'Али',
            'auto_apply' => false,
        ]);
        $this->assertDatabaseHas('data_dictionary_entries', [
            'lookup_value' => 'Alik',
            'result_value' => 'Алихан',
            'auto_apply' => false,
        ]);
    }

    public function test_import_names_csv_rejects_unique_conflict_on_update(): void
    {
        $first = DataDictionaryEntry::query()->create([
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Саня',
            'result_value' => 'Александр',
            'gender' => DataDictionaryEntry::GENDER_MALE,
            'language' => DataDictionaryEntry::LANGUAGE_RU,
        ]);
        $second = DataDictionaryEntry::query()->create([
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Ваня',
            'result_value' => 'Иван',
            'gender' => DataDictionaryEntry::GENDER_MALE,
            'language' => DataDictionaryEntry::LANGUAGE_RU,
        ]);

        $path = $this->writeTempCsv(<<<CSV
ID;Вариант;Полное имя;Пол
{$first->id};Лёша;Алексей;Мужской
{$second->id};Леша;Алексей;Мужской
CSV);

        try {
            $this->expectException(ValidationException::class);

            app(ImportDataDictionaryEntriesCsvAction::class)->handle($path);
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseHas('data_dictionary_entries', [
            'id' => $second->id,
            'lookup_value' => 'Ваня',
            'result_value' => 'Иван',
        ]);
    }

    public function test_import_names_csv_rejects_invalid_required_values(): void
    {
        $path = $this->writeTempCsv(<<<'CSV'
Вариант;Полное имя;Пол;Тип варианта
Тёма;Артём;кот;Краткое
CSV);

        try {
            $this->expectException(ValidationException::class);

            app(ImportDataDictionaryEntriesCsvAction::class)->handle($path);
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseCount('data_dictionary_entries', 0);
    }

    private function writeTempCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dictionary-csv-');

        $this->assertIsString($path);

        file_put_contents($path, $contents.PHP_EOL);

        return $path;
    }
}
