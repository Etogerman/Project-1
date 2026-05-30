<?php

namespace Tests\Feature;

use App\Filament\Resources\FieldDictionaryFields\Pages\ManageFieldDictionaryFields;
use App\Models\Dialog;
use App\Models\FieldDictionaryField;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentFieldDictionaryFieldsResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_system_field_definitions_are_seeded(): void
    {
        $gender = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'gender')
            ->firstOrFail();

        $country = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'country')
            ->firstOrFail();

        $phones = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'phones')
            ->firstOrFail();

        $emails = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'emails')
            ->firstOrFail();

        $ageRange = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'age_range')
            ->firstOrFail();

        $genderSource = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'gender_source')
            ->firstOrFail();

        $dialogStage = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'stage')
            ->firstOrFail();

        $dialogCurrentBlock = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'current_block_id')
            ->firstOrFail();

        $this->assertTrue($gender->is_system);
        $this->assertSame(FieldDictionaryField::TYPE_SELECT, $gender->type);
        $this->assertSame('gender_source', $gender->source_field_key);
        $this->assertSame(FieldDictionaryField::TYPE_TEXT, $country->type);
        $this->assertSame(FieldDictionaryField::TYPE_PHONE, $phones->type);
        $this->assertTrue($phones->is_multiple);
        $this->assertSame(FieldDictionaryField::TYPE_EMAIL, $emails->type);
        $this->assertTrue($emails->is_multiple);
        $this->assertSame(FieldDictionaryField::TYPE_SELECT, $ageRange->type);
        $this->assertContains('30_39', collect($ageRange->options)->pluck('value')->all());
        $this->assertContains('scenario', collect($genderSource->options)->pluck('value')->all());
        $this->assertContains(Dialog::STAGE_TRANSFERRED_TO_MPP, collect($dialogStage->options)->pluck('value')->all());
        $this->assertTrue($dialogCurrentBlock->is_system);
        $this->assertSame(FieldDictionaryField::TYPE_TEXT, $dialogCurrentBlock->type);
        $this->assertFalse($dialogCurrentBlock->is_multiple);
    }

    public function test_field_dictionary_label_helpers_return_dictionary_value_or_caller_fallback(): void
    {
        FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'phones')
            ->firstOrFail()
            ->update(['name' => 'Номера клиента']);

        $gender = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'gender')
            ->firstOrFail();

        $options = $gender->options;
        $options[1]['label'] = 'Женщина из справочника';
        $gender->update(['options' => $options]);

        $this->assertSame(
            'Номера клиента',
            FieldDictionaryField::labelFor(FieldDictionaryField::ENTITY_CONTACT, 'phones', 'Телефон'),
        );
        $this->assertSame(
            'Fallback label',
            FieldDictionaryField::labelFor(FieldDictionaryField::ENTITY_CONTACT, 'missing_field', 'Fallback label'),
        );
        $this->assertSame(
            'Женщина из справочника',
            FieldDictionaryField::optionLabelFor(FieldDictionaryField::ENTITY_CONTACT, 'gender', 'female', 'Женский'),
        );
        $this->assertSame(
            'Fallback option',
            FieldDictionaryField::optionLabelFor(FieldDictionaryField::ENTITY_CONTACT, 'gender', 'missing_option', 'Fallback option'),
        );
    }

    public function test_admin_can_open_field_dictionary(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $field = FieldDictionaryField::query()->firstOrFail();

        $this->actingAs($admin)
            ->get('/admin/field-dictionary-fields')
            ->assertOk()
            ->assertSee('Справочник полей');

        Livewire::actingAs($admin)
            ->test(ManageFieldDictionaryFields::class)
            ->assertSet('activeEntity', FieldDictionaryField::ENTITY_CONTACT)
            ->assertSee($field->name);

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', FieldDictionaryField::class));
    }

    public function test_employee_cannot_open_field_dictionary(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->assertFalse(Gate::forUser($employee)->allows('viewAny', FieldDictionaryField::class));

        $this->actingAs($employee)
            ->get('/admin/field-dictionary-fields')
            ->assertForbidden();
    }

    public function test_cancel_closes_field_drawer_and_discards_changes(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $field = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'last_name')
            ->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ManageFieldDictionaryFields::class)
            ->call('selectField', $field->id)
            ->assertSet('selectedFieldId', $field->id)
            ->set("fieldRows.{$field->id}.name", 'Черновик')
            ->call('closeFieldDrawer')
            ->assertSet('selectedFieldId', null)
            ->assertSet("fieldRows.{$field->id}.name", $field->name);

        $this->assertDatabaseHas('field_dictionary_fields', [
            'id' => $field->id,
            'name' => $field->name,
        ]);
    }

    public function test_system_field_can_change_name_and_order_but_not_identity(): void
    {
        $field = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'gender')
            ->firstOrFail();

        $field->update([
            'name' => 'Пол клиента',
            'sort_order' => 33,
        ]);

        $this->assertDatabaseHas('field_dictionary_fields', [
            'id' => $field->id,
            'name' => 'Пол клиента',
            'sort_order' => 33,
        ]);

        $this->assertValidationFails(fn (): bool => $field->fresh()->delete());
        $this->assertValidationFails(fn (): bool => $field->fresh()->update(['field_key' => 'gender_new']));
        $this->assertValidationFails(fn (): bool => $field->fresh()->update(['type' => FieldDictionaryField::TYPE_TEXT]));
        $this->assertValidationFails(fn (): bool => $field->fresh()->update(['source_field_key' => null]));
        $this->assertValidationFails(fn (): bool => $field->fresh()->update(['is_multiple' => true]));
    }

    public function test_system_select_options_allow_labels_and_new_values_only(): void
    {
        $field = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'gender')
            ->firstOrFail();

        $options = $field->options;
        $options[0]['label'] = 'Мужчина';
        $options[] = [
            'value' => 'other',
            'label' => 'Другое',
            'is_system' => false,
        ];

        $field->update(['options' => $options]);

        $updated = $field->fresh();
        $this->assertSame('Мужчина', collect($updated->options)->firstWhere('value', 'male')['label']);
        $this->assertSame('Другое', collect($updated->options)->firstWhere('value', 'other')['label']);

        $withoutSystemValue = collect($updated->options)
            ->reject(fn (array $option): bool => ($option['value'] ?? null) === 'male')
            ->values()
            ->all();

        $this->assertValidationFails(fn (): bool => $updated->update(['options' => $withoutSystemValue]));

        $renamedSystemValue = $updated->options;
        $renamedSystemValue[0]['value'] = 'male_new';
        $this->assertValidationFails(fn (): bool => $updated->fresh()->update(['options' => $renamedSystemValue]));

        $newSystemValue = $updated->options;
        $newSystemValue[] = [
            'value' => 'system_new',
            'label' => 'Новое системное',
            'is_system' => true,
        ];
        $this->assertValidationFails(fn (): bool => $updated->fresh()->update(['options' => $newSystemValue]));
    }

    public function test_system_sync_does_not_overwrite_manual_field_or_option_labels(): void
    {
        $phones = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'phones')
            ->firstOrFail();
        $stage = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'stage')
            ->firstOrFail();

        $phones->update(['name' => 'Номера клиента']);

        $stageOptions = $stage->options;
        $stageOptions[4]['label'] = 'МПП из справочника';
        $stage->update(['options' => $stageOptions]);

        FieldDictionaryField::syncSystemDefinitions();

        $this->assertSame('Номера клиента', $phones->fresh()->name);
        $this->assertSame(
            'МПП из справочника',
            collect($stage->fresh()->options)
                ->firstWhere('value', Dialog::STAGE_TRANSFERRED_TO_MPP)['label'] ?? null,
        );
    }

    public function test_user_field_source_reference_rules_and_delete(): void
    {
        $source = FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_CONTACT,
            'field_key' => 'my_source',
            'name' => 'Мой источник',
            'type' => FieldDictionaryField::TYPE_TEXT,
            'sort_order' => 1000,
        ]);

        $field = FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_CONTACT,
            'field_key' => 'my_new_field',
            'name' => 'Моё новое поле',
            'type' => FieldDictionaryField::TYPE_SELECT,
            'source_field_key' => 'my_source',
            'sort_order' => 1010,
            'is_multiple' => true,
            'options' => [
                ['value' => 'first', 'label' => 'Первый вариант', 'is_system' => false],
            ],
        ]);

        $this->assertFalse($field->is_system);
        $this->assertTrue($field->is_multiple);
        $this->assertValidationFails(fn (): bool => $field->fresh()->update(['is_multiple' => false]));
        $this->assertValidationFails(fn (): bool => $source->delete());
        $this->assertValidationFails(fn (): FieldDictionaryField => FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_CONTACT,
            'field_key' => 'self_source',
            'name' => 'Сам на себя',
            'type' => FieldDictionaryField::TYPE_TEXT,
            'source_field_key' => 'self_source',
        ]));

        $field->update(['source_field_key' => null]);

        $this->assertTrue($source->fresh()->delete());
        $this->assertTrue($field->fresh()->delete());
    }

    private function assertValidationFails(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException) {
            $this->assertTrue(true);

            return;
        }

        $this->fail('Ожидалась ошибка валидации.');
    }
}
