<?php

namespace Tests\Feature;

use App\Filament\Resources\FieldDictionaryFields\Pages\ManageFieldDictionaryFields;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\FieldDictionaryField;
use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
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

        $phone = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'phone')
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

        $regionSource = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'region_source')
            ->firstOrFail();

        $regionStatus = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'region_status')
            ->firstOrFail();

        $distanceToMoscow = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'distance_to_moscow_km')
            ->firstOrFail();

        $distanceToMoscowStatus = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'distance_to_moscow_status')
            ->firstOrFail();

        $distanceToMoscowCalculatedAt = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'distance_to_moscow_calculated_at')
            ->firstOrFail();
        $effectiveAgeYears = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'effective_age_years')
            ->firstOrFail();
        $pendingRegionCandidates = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'pending_region_candidates')
            ->firstOrFail();
        $autoReplyCategory = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'auto_reply_category')
            ->firstOrFail();
        $hasBlockedBotDialog = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'has_blocked_bot_dialog')
            ->firstOrFail();

        $dialogStage = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'stage')
            ->firstOrFail();

        $dialogCurrentBlock = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'current_block_id')
            ->firstOrFail();
        $questionnaireStatus = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'data_collection_status')
            ->firstOrFail();
        $bitrixContactId = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'bitrix24_contact_id')
            ->firstOrFail();
        $dialogSubscription = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'bot_subscription_status')
            ->firstOrFail();

        $this->assertTrue($gender->is_system);
        $this->assertSame(FieldDictionaryField::TYPE_SELECT, $gender->type);
        $this->assertSame('gender_source', $gender->source_field_key);
        $this->assertSame(FieldDictionaryField::CONDITION_VISIBILITY_MAIN, $gender->condition_visibility);
        $this->assertSame(FieldDictionaryField::WRITE_ACCESS_WRITABLE, $gender->write_access);
        $this->assertSame(FieldDictionaryField::MANUAL_WRITE_ACCESS_READONLY, $gender->manual_write_access);
        $this->assertSame(FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED, $gender->scenario_write_access);
        $this->assertSame(FieldDictionaryField::VALUE_OWNER_SYSTEM, $gender->value_owner);
        $this->assertSame(FieldDictionaryField::HINT_GROUP_CONTACT, $gender->hint_group);
        $this->assertSame(FieldDictionaryField::TYPE_TEXT, $country->type);
        $this->assertSame('region_source', $country->source_field_key);
        $this->assertSame(FieldDictionaryField::TYPE_PHONE, $phone->type);
        $this->assertFalse($phone->is_multiple);
        $this->assertSame(FieldDictionaryField::WRITE_ACCESS_READ_ONLY, $phone->write_access);
        $this->assertSame(FieldDictionaryField::SCENARIO_WRITE_ACCESS_DENIED, $phone->scenario_write_access);
        $this->assertSame(FieldDictionaryField::TYPE_PHONE, $phones->type);
        $this->assertTrue($phones->is_multiple);
        $this->assertSame(FieldDictionaryField::VALUE_OWNER_COMPUTED, $phones->value_owner);
        $this->assertSame(FieldDictionaryField::TYPE_EMAIL, $emails->type);
        $this->assertTrue($emails->is_multiple);
        $this->assertSame(FieldDictionaryField::CONDITION_VISIBILITY_MAIN, $emails->condition_visibility);
        $this->assertSame(FieldDictionaryField::WRITE_ACCESS_READ_ONLY, $emails->write_access);
        $this->assertSame(FieldDictionaryField::MANUAL_WRITE_ACCESS_READONLY, $emails->manual_write_access);
        $this->assertSame(FieldDictionaryField::SCENARIO_WRITE_ACCESS_DENIED, $emails->scenario_write_access);
        $this->assertSame(FieldDictionaryField::VALUE_OWNER_COMPUTED, $emails->value_owner);
        $this->assertSame(FieldDictionaryField::HINT_GROUP_CONTACT, $emails->hint_group);
        $this->assertSame(FieldDictionaryField::TYPE_SELECT, $ageRange->type);
        $this->assertContains('30_39', collect($ageRange->options)->pluck('value')->all());
        $this->assertContains('scenario', collect($genderSource->options)->pluck('value')->all());
        $this->assertSame(FieldDictionaryField::TYPE_SELECT, $regionSource->type);
        $this->assertSame(FieldDictionaryField::HINT_GROUP_GEO, $regionSource->hint_group);
        $this->assertContains(Contact::REGION_SOURCE_CONFIRMED_BY_CONTACT, collect($regionSource->options)->pluck('value')->all());
        $this->assertSame(FieldDictionaryField::TYPE_SELECT, $regionStatus->type);
        $this->assertContains(Contact::REGION_STATUS_AMBIGUOUS, collect($regionStatus->options)->pluck('value')->all());
        $this->assertSame(FieldDictionaryField::TYPE_NUMBER, $distanceToMoscow->type);
        $this->assertSame(FieldDictionaryField::TYPE_SELECT, $distanceToMoscowStatus->type);
        $this->assertContains(Contact::DISTANCE_TO_MOSCOW_STATUS_PENDING, collect($distanceToMoscowStatus->options)->pluck('value')->all());
        $this->assertSame(FieldDictionaryField::TYPE_DATE, $distanceToMoscowCalculatedAt->type);
        $this->assertSame(FieldDictionaryField::TYPE_NUMBER, $effectiveAgeYears->type);
        $this->assertSame(FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY, $effectiveAgeYears->condition_visibility);
        $this->assertSame(FieldDictionaryField::WRITE_ACCESS_READ_ONLY, $effectiveAgeYears->write_access);
        $this->assertSame(FieldDictionaryField::VALUE_OWNER_COMPUTED, $effectiveAgeYears->value_owner);
        $this->assertSame(FieldDictionaryField::TYPE_TEXT, $pendingRegionCandidates->type);
        $this->assertSame(FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY, $pendingRegionCandidates->condition_visibility);
        $this->assertSame(FieldDictionaryField::HINT_GROUP_GEO, $pendingRegionCandidates->hint_group);
        $this->assertSame(FieldDictionaryField::TYPE_TEXT, $autoReplyCategory->type);
        $this->assertSame(FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY, $autoReplyCategory->condition_visibility);
        $this->assertSame(FieldDictionaryField::TYPE_BOOLEAN, $hasBlockedBotDialog->type);
        $this->assertSame(FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY, $hasBlockedBotDialog->condition_visibility);
        $this->assertFalse(FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->where('field_key', 'location_source')
            ->exists());
        $this->assertContains(Dialog::STAGE_TRANSFERRED_TO_MPP, collect($dialogStage->options)->pluck('value')->all());
        $this->assertTrue($dialogCurrentBlock->is_system);
        $this->assertSame(FieldDictionaryField::TYPE_TEXT, $dialogCurrentBlock->type);
        $this->assertFalse($dialogCurrentBlock->is_multiple);
        $this->assertSame(FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY, $dialogCurrentBlock->condition_visibility);
        $this->assertSame(FieldDictionaryField::HINT_GROUP_SYSTEM, $dialogCurrentBlock->hint_group);
        $this->assertSame(FieldDictionaryField::HINT_GROUP_QUESTIONNAIRE, $questionnaireStatus->hint_group);
        $this->assertSame(FieldDictionaryField::HINT_GROUP_BITRIX24, $bitrixContactId->hint_group);
        $this->assertSame(FieldDictionaryField::VALUE_OWNER_INTEGRATION, $bitrixContactId->value_owner);
        $this->assertSame(FieldDictionaryField::TYPE_SELECT, $dialogSubscription->type);
        $this->assertSame(FieldDictionaryField::HINT_GROUP_DIALOG, $dialogSubscription->hint_group);
        $this->assertSame(FieldDictionaryField::MANUAL_WRITE_ACCESS_READONLY, $dialogSubscription->manual_write_access);
        $this->assertSame(FieldDictionaryField::SCENARIO_WRITE_ACCESS_DENIED, $dialogSubscription->scenario_write_access);
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

    public function test_admin_can_filter_field_dictionary_rows(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $geoField = FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_DIALOG,
            'field_key' => 'local_filter_geo_city',
            'name' => 'Город для фильтра',
            'type' => FieldDictionaryField::TYPE_TEXT,
            'hint_group' => FieldDictionaryField::HINT_GROUP_GEO,
            'sort_order' => 5000,
        ]);

        $dialogField = FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_DIALOG,
            'field_key' => 'local_filter_dialog_counter',
            'name' => 'Счётчик для фильтра',
            'type' => FieldDictionaryField::TYPE_NUMBER,
            'hint_group' => FieldDictionaryField::HINT_GROUP_DIALOG,
            'sort_order' => 5010,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ManageFieldDictionaryFields::class)
            ->call('selectEntity', FieldDictionaryField::ENTITY_DIALOG)
            ->call('selectHintGroup', FieldDictionaryField::HINT_GROUP_GEO);

        $visibleRows = $component->instance()->visibleFieldRows();

        $this->assertArrayHasKey($geoField->id, $visibleRows);
        $this->assertArrayNotHasKey($dialogField->id, $visibleRows);

        $component
            ->set('search', 'Счётчик для фильтра')
            ->call('selectHintGroup', 'all');

        $visibleRows = $component->instance()->visibleFieldRows();

        $this->assertArrayHasKey($dialogField->id, $visibleRows);
        $this->assertArrayNotHasKey($geoField->id, $visibleRows);

        $component->call('resetFilters');

        $visibleRows = $component->instance()->visibleFieldRows();

        $this->assertArrayHasKey($geoField->id, $visibleRows);
        $this->assertArrayHasKey($dialogField->id, $visibleRows);
        $component->assertSet('search', '');
        $component->assertSet('activeHintGroup', 'all');
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
        $this->assertValidationFails(fn (): bool => $field->fresh()->update(['condition_visibility' => FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY]));
        $field->fresh()->update(['write_access' => FieldDictionaryField::WRITE_ACCESS_READ_ONLY]);
        $this->assertSame(FieldDictionaryField::WRITE_ACCESS_WRITABLE, $field->fresh()->write_access);
        $this->assertValidationFails(fn (): bool => $field->fresh()->update(['manual_write_access' => FieldDictionaryField::MANUAL_WRITE_ACCESS_EDITABLE]));
        $this->assertValidationFails(fn (): bool => $field->fresh()->update(['scenario_write_access' => FieldDictionaryField::SCENARIO_WRITE_ACCESS_DENIED]));
        $this->assertValidationFails(fn (): bool => $field->fresh()->update(['value_owner' => FieldDictionaryField::VALUE_OWNER_OPERATOR]));
        $this->assertValidationFails(fn (): bool => $field->fresh()->update(['hint_group' => FieldDictionaryField::HINT_GROUP_SYSTEM]));
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

    public function test_user_field_used_by_published_scenario_cannot_be_deleted_but_draft_usage_does_not_block(): void
    {
        $publishedField = FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_DIALOG,
            'field_key' => 'published_counter',
            'name' => 'Опубликованный счётчик',
            'type' => FieldDictionaryField::TYPE_NUMBER,
            'sort_order' => 1000,
        ]);

        $draftOnlyField = FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_DIALOG,
            'field_key' => 'draft_counter',
            'name' => 'Черновой счётчик',
            'type' => FieldDictionaryField::TYPE_NUMBER,
            'sort_order' => 1010,
        ]);

        $scenario = Scenario::query()->create([
            'code' => 'field_delete_guard',
            'name' => 'Защита удаления поля',
            'is_active' => true,
        ]);

        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
            'schema_payload' => [
                'builder_v3_runtime' => [
                    'blocks' => [
                        'start' => [
                            'type' => 'message',
                            'message' => [
                                'text' => 'Счётчик {{ dialog.published_counter|0 }}',
                            ],
                            'automatic_edges' => [[
                                'field_condition' => [
                                    'enabled' => true,
                                    'field_scope' => 'dialog',
                                    'field_key' => 'published_counter',
                                    'operator' => '>',
                                    'value' => '0',
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ]);

        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 2,
            'status' => ScenarioVersion::STATUS_DRAFT,
            'schema_payload' => [
                'builder_v3_runtime' => [
                    'blocks' => [
                        'draft' => [
                            'type' => 'action',
                            'actions' => [[
                                'type' => 'variables',
                                'operations' => [[
                                    'type' => 'increment',
                                    'field_key' => 'draft_counter',
                                    'amount' => 1,
                                ]],
                            ]],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertValidationFails(
            fn (): bool => $publishedField->fresh()->delete(),
            'Нельзя удалить поле, пока оно используется в опубликованном сценарии.',
        );

        $this->assertTrue($draftOnlyField->fresh()->delete());
    }

    public function test_user_dialog_field_defaults_to_constructor_access(): void
    {
        $field = FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_DIALOG,
            'field_key' => 'asked_name_count',
            'name' => 'Сколько раз спросили имя',
            'type' => FieldDictionaryField::TYPE_NUMBER,
            'sort_order' => 1000,
        ]);

        $this->assertFalse($field->is_system);
        $this->assertSame(FieldDictionaryField::CONDITION_VISIBILITY_MAIN, $field->condition_visibility);
        $this->assertSame(FieldDictionaryField::WRITE_ACCESS_WRITABLE, $field->write_access);
        $this->assertSame(FieldDictionaryField::MANUAL_WRITE_ACCESS_EDITABLE, $field->manual_write_access);
        $this->assertSame(FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED, $field->scenario_write_access);
        $this->assertSame(FieldDictionaryField::VALUE_OWNER_OPERATOR, $field->value_owner);
        $this->assertSame(FieldDictionaryField::HINT_GROUP_DIALOG, $field->hint_group);
    }

    public function test_user_dialog_field_syncs_legacy_write_access_from_scenario_access(): void
    {
        $field = FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_DIALOG,
            'field_key' => 'scenario_editable_counter',
            'name' => 'Счётчик сценария',
            'type' => FieldDictionaryField::TYPE_NUMBER,
            'sort_order' => 1000,
        ]);

        $this->assertSame(FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED, $field->scenario_write_access);
        $this->assertSame(FieldDictionaryField::WRITE_ACCESS_WRITABLE, $field->write_access);

        $field->update([
            'scenario_write_access' => FieldDictionaryField::SCENARIO_WRITE_ACCESS_DENIED,
        ]);
        $field->refresh();

        $this->assertSame(FieldDictionaryField::SCENARIO_WRITE_ACCESS_DENIED, $field->scenario_write_access);
        $this->assertSame(FieldDictionaryField::WRITE_ACCESS_READ_ONLY, $field->write_access);

        $field->update([
            'scenario_write_access' => FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED,
        ]);
        $field->refresh();

        $this->assertSame(FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED, $field->scenario_write_access);
        $this->assertSame(FieldDictionaryField::WRITE_ACCESS_WRITABLE, $field->write_access);
    }

    public function test_user_contact_field_stays_display_only_and_read_only(): void
    {
        $field = FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_CONTACT,
            'field_key' => 'custom_contact_note',
            'name' => 'Комментарий контакта',
            'type' => FieldDictionaryField::TYPE_TEXT,
            'condition_visibility' => FieldDictionaryField::CONDITION_VISIBILITY_MAIN,
            'write_access' => FieldDictionaryField::WRITE_ACCESS_WRITABLE,
            'hint_group' => FieldDictionaryField::HINT_GROUP_GEO,
            'sort_order' => 1000,
        ]);

        $this->assertFalse($field->is_system);
        $this->assertSame(FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY, $field->condition_visibility);
        $this->assertSame(FieldDictionaryField::WRITE_ACCESS_READ_ONLY, $field->write_access);
        $this->assertSame(FieldDictionaryField::MANUAL_WRITE_ACCESS_READONLY, $field->manual_write_access);
        $this->assertSame(FieldDictionaryField::SCENARIO_WRITE_ACCESS_DENIED, $field->scenario_write_access);
        $this->assertSame(FieldDictionaryField::VALUE_OWNER_OPERATOR, $field->value_owner);
        $this->assertSame(FieldDictionaryField::HINT_GROUP_CONTACT, $field->hint_group);

        $field->update([
            'condition_visibility' => FieldDictionaryField::CONDITION_VISIBILITY_MAIN,
            'manual_write_access' => FieldDictionaryField::MANUAL_WRITE_ACCESS_EDITABLE,
            'scenario_write_access' => FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED,
            'value_owner' => FieldDictionaryField::VALUE_OWNER_SCENARIO,
            'hint_group' => FieldDictionaryField::HINT_GROUP_SYSTEM,
        ]);
        $field->refresh();

        $this->assertSame(FieldDictionaryField::CONDITION_VISIBILITY_DISPLAY_ONLY, $field->condition_visibility);
        $this->assertSame(FieldDictionaryField::WRITE_ACCESS_READ_ONLY, $field->write_access);
        $this->assertSame(FieldDictionaryField::MANUAL_WRITE_ACCESS_READONLY, $field->manual_write_access);
        $this->assertSame(FieldDictionaryField::SCENARIO_WRITE_ACCESS_DENIED, $field->scenario_write_access);
        $this->assertSame(FieldDictionaryField::VALUE_OWNER_OPERATOR, $field->value_owner);
        $this->assertSame(FieldDictionaryField::HINT_GROUP_CONTACT, $field->hint_group);
    }

    private function assertValidationFails(callable $callback, ?string $expectedMessage = null): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            if ($expectedMessage !== null) {
                $this->assertStringContainsString($expectedMessage, implode(' ', Arr::flatten($exception->errors())));
            }

            $this->assertTrue(true);

            return;
        }

        $this->fail('Ожидалась ошибка валидации.');
    }
}
