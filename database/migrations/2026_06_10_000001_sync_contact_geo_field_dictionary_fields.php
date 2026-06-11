<?php

use App\Models\Contact;
use App\Models\FieldDictionaryField;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('field_dictionary_fields')) {
            return;
        }

        $now = now();
        $regionSourceOptions = $this->options([
            Contact::REGION_SOURCE_AI => 'ИИ',
            Contact::REGION_SOURCE_DICTIONARY => 'Справочник',
            Contact::REGION_SOURCE_CONFIRMED_BY_CONTACT => 'Подтверждён клиентом',
            Contact::REGION_SOURCE_MANUAL => 'Указан вручную',
        ]);

        $regionStatusOptions = $this->options(Contact::regionStatusOptions());
        $distanceStatusOptions = $this->options(Contact::distanceToMoscowStatusOptions());

        DB::transaction(function () use ($now, $regionSourceOptions, $regionStatusOptions, $distanceStatusOptions): void {
            $regionSourceExists = DB::table('field_dictionary_fields')
                ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
                ->where('field_key', 'region_source')
                ->exists();

            $locationSourceExists = DB::table('field_dictionary_fields')
                ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
                ->where('field_key', 'location_source')
                ->exists();

            if (! $regionSourceExists && $locationSourceExists) {
                DB::table('field_dictionary_fields')
                    ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
                    ->where('field_key', 'location_source')
                    ->update([
                        'field_key' => 'region_source',
                        'name' => 'Источник региона',
                        'type' => FieldDictionaryField::TYPE_SELECT,
                        'options' => $regionSourceOptions,
                        'source_field_key' => null,
                        'sort_order' => 62,
                        'is_multiple' => false,
                        'is_system' => true,
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('field_dictionary_fields')
                    ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
                    ->where('field_key', 'location_source')
                    ->delete();

                $this->upsertField('region_source', 'Источник региона', FieldDictionaryField::TYPE_SELECT, 62, $regionSourceOptions, null, false, $now);
            }

            DB::table('field_dictionary_fields')
                ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
                ->whereIn('field_key', ['country', 'region', 'city'])
                ->update([
                    'source_field_key' => 'region_source',
                    'updated_at' => $now,
                ]);

            $this->upsertField('phone', 'Основной телефон', FieldDictionaryField::TYPE_PHONE, 24, '[]', null, false, $now);
            $this->upsertField('region_status', 'Статус региона', FieldDictionaryField::TYPE_SELECT, 61, $regionStatusOptions, null, false, $now);
            $this->upsertField('distance_to_moscow_km', 'Расстояние до Москвы, км', FieldDictionaryField::TYPE_NUMBER, 80, '[]', null, false, $now);
            $this->upsertField('distance_to_moscow_status', 'Статус расчёта расстояния', FieldDictionaryField::TYPE_SELECT, 82, $distanceStatusOptions, null, false, $now);
            $this->upsertField('distance_to_moscow_calculated_at', 'Расстояние рассчитано', FieldDictionaryField::TYPE_DATE, 84, '[]', null, false, $now);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('field_dictionary_fields')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('field_dictionary_fields')
                ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
                ->whereIn('field_key', [
                    'phone',
                    'region_status',
                    'distance_to_moscow_km',
                    'distance_to_moscow_status',
                    'distance_to_moscow_calculated_at',
                ])
                ->delete();

            DB::table('field_dictionary_fields')
                ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
                ->where('field_key', 'region_source')
                ->update([
                    'field_key' => 'location_source',
                    'name' => 'Откуда знаем локацию',
                    'options' => $this->options([
                        'client' => 'Клиент сказал',
                        'operator' => 'Оператор',
                        'scenario' => 'Сценарий',
                        'dictionary' => 'Справочник',
                        'ai' => 'ИИ',
                    ]),
                    'sort_order' => 72,
                    'updated_at' => now(),
                ]);

            DB::table('field_dictionary_fields')
                ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
                ->whereIn('field_key', ['country', 'region', 'city'])
                ->update([
                    'source_field_key' => 'location_source',
                    'updated_at' => now(),
                ]);
        });
    }

    private function upsertField(
        string $fieldKey,
        string $name,
        string $type,
        int $sortOrder,
        string $options,
        ?string $sourceFieldKey,
        bool $isMultiple,
        mixed $now,
    ): void {
        DB::table('field_dictionary_fields')->updateOrInsert(
            [
                'entity' => FieldDictionaryField::ENTITY_CONTACT,
                'field_key' => $fieldKey,
            ],
            [
                'name' => $name,
                'type' => $type,
                'options' => $options,
                'source_field_key' => $sourceFieldKey,
                'sort_order' => $sortOrder,
                'is_multiple' => $isMultiple,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    /**
     * @param  array<string, string>  $options
     */
    private function options(array $options): string
    {
        return json_encode(
            collect($options)
                ->map(fn (string $label, string $value): array => [
                    'value' => $value,
                    'label' => $label,
                    'is_system' => true,
                ])
                ->values()
                ->all(),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
};
