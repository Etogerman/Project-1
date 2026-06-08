<?php

namespace Tests\Feature;

use App\Models\DataDictionaryEntry;
use App\Services\Scenarios\LookupScenarioDataDictionaryAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataDictionaryLookupActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_resolves_ambiguous_variant_by_known_gender(): void
    {
        $maleEntry = $this->createNameVariant('Александр', DataDictionaryEntry::GENDER_MALE, 'Саша');
        $femaleEntry = $this->createNameVariant('Александра', DataDictionaryEntry::GENDER_FEMALE, 'Саша');
        $lookup = app(LookupScenarioDataDictionaryAction::class);

        $maleResult = $lookup->handle(DataDictionaryEntry::DICTIONARY_NAMES, 'Саша', DataDictionaryEntry::GENDER_MALE);
        $femaleResult = $lookup->handle(DataDictionaryEntry::DICTIONARY_NAMES, 'Саша', DataDictionaryEntry::GENDER_FEMALE);
        $unknownResult = $lookup->handle(DataDictionaryEntry::DICTIONARY_NAMES, 'Саша', null);

        $this->assertSame(LookupScenarioDataDictionaryAction::STATUS_MATCHED, $maleResult['status']);
        $this->assertSame('Александр', $maleResult['value']);
        $this->assertSame($maleEntry->id, $maleResult['matched_entry_id']);
        $this->assertSame(LookupScenarioDataDictionaryAction::STATUS_MATCHED, $femaleResult['status']);
        $this->assertSame('Александра', $femaleResult['value']);
        $this->assertSame($femaleEntry->id, $femaleResult['matched_entry_id']);
        $this->assertSame(LookupScenarioDataDictionaryAction::STATUS_AMBIGUOUS, $unknownResult['status']);
        $this->assertFalse($unknownResult['matched']);
    }

    public function test_lookup_returns_manual_required_when_only_manual_variant_matches(): void
    {
        $this->createNameVariant('Георгий', DataDictionaryEntry::GENDER_MALE, 'Гоша', autoApply: false);

        $result = app(LookupScenarioDataDictionaryAction::class)
            ->handle(DataDictionaryEntry::DICTIONARY_NAMES, 'Гоша', DataDictionaryEntry::GENDER_MALE);

        $this->assertSame(LookupScenarioDataDictionaryAction::STATUS_MANUAL_REQUIRED, $result['status']);
        $this->assertFalse($result['matched']);
        $this->assertNull($result['value']);
    }

    public function test_lookup_returns_ambiguous_when_known_gender_conflicts_with_name(): void
    {
        $this->createNameVariant('Марина', DataDictionaryEntry::GENDER_FEMALE, 'Марина');

        $result = app(LookupScenarioDataDictionaryAction::class)
            ->handle(DataDictionaryEntry::DICTIONARY_NAMES, 'Марина', DataDictionaryEntry::GENDER_MALE);

        $this->assertSame(LookupScenarioDataDictionaryAction::STATUS_AMBIGUOUS, $result['status']);
        $this->assertFalse($result['matched']);
    }

    public function test_lookup_normalizes_yo_variants(): void
    {
        $this->createNameVariant('Артём', DataDictionaryEntry::GENDER_MALE, 'Тёма', DataDictionaryEntry::VARIANT_TYPE_YO);

        $result = app(LookupScenarioDataDictionaryAction::class)
            ->handle(DataDictionaryEntry::DICTIONARY_NAMES, 'Тема', DataDictionaryEntry::GENDER_MALE);

        $this->assertSame(LookupScenarioDataDictionaryAction::STATUS_MATCHED, $result['status']);
        $this->assertSame('Артём', $result['value']);
    }

    private function createNameVariant(
        string $fullName,
        string $gender,
        string $variant,
        string $variantType = DataDictionaryEntry::VARIANT_TYPE_SHORT,
        bool $autoApply = true,
    ): DataDictionaryEntry {
        return DataDictionaryEntry::query()->create([
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => $variant,
            'result_value' => $fullName,
            'gender' => $gender,
            'language' => DataDictionaryEntry::LANGUAGE_RU,
            'variant_type' => $variantType,
            'auto_apply' => $autoApply,
            'is_active' => true,
        ]);
    }
}
