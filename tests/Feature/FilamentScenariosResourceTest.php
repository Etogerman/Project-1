<?php

namespace Tests\Feature;

use App\Filament\Resources\Scenarios\Pages\ManageScenarios;
use App\Filament\Resources\Scenarios\ScenarioResource;
use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Models\Tag;
use App\Models\User;
use App\Services\Scenarios\CreateScenarioAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentScenariosResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_active_admin_can_open_scenarios_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get(ScenarioResource::getUrl())
            ->assertOk()
            ->assertSee('Сценарии');
    }

    public function test_employee_cannot_open_scenarios_page(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->actingAs($employee)
            ->get(ScenarioResource::getUrl())
            ->assertForbidden();
    }

    public function test_employee_with_scenarios_view_can_open_scenarios_page(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'scenarios.view')
            ->update(['granted' => true]);

        $this->actingAs($employee->fresh())
            ->get(ScenarioResource::getUrl())
            ->assertOk()
            ->assertSee('Сценарии');
    }

    public function test_scenario_policy_for_employee_uses_role_permission_matrix(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'qualification',
            'name' => 'Квалификация',
            'is_active' => true,
        ]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->whereIn('permission_key', [
                'scenarios.view',
                'scenarios.edit',
                'scenarios.archive',
            ])
            ->update(['granted' => true]);

        $employee = $employee->fresh();

        $this->assertTrue(Gate::forUser($employee)->allows('viewAny', Scenario::class));
        $this->assertTrue(Gate::forUser($employee)->allows('view', $scenario));
        $this->assertTrue(Gate::forUser($employee)->allows('create', Scenario::class));
        $this->assertTrue(Gate::forUser($employee)->allows('update', $scenario));
        $this->assertTrue(Gate::forUser($employee)->allows('archive', $scenario));
        $this->assertFalse(Gate::forUser($employee)->allows('delete', $scenario));
    }

    public function test_scenario_table_actions_respect_employee_matrix_values(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'retention',
            'name' => 'Удержание',
            'is_active' => true,
        ]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->whereIn('permission_key', [
                'scenarios.view',
                'scenarios.edit',
                'scenarios.archive',
            ])
            ->update(['granted' => false]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'scenarios.view')
            ->update(['granted' => true]);

        Livewire::actingAs($employee->fresh())
            ->test(ManageScenarios::class)
            ->assertTableActionHidden('publishDraft', $scenario)
            ->assertTableActionHidden('edit', $scenario)
            ->assertTableActionHidden('archiveScenario', $scenario);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'scenarios.edit')
            ->update(['granted' => true]);

        Livewire::actingAs($employee->fresh())
            ->test(ManageScenarios::class)
            ->assertTableActionVisible('publishDraft', $scenario)
            ->assertTableActionVisible('edit', $scenario)
            ->assertTableActionHidden('archiveScenario', $scenario);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'scenarios.archive')
            ->update(['granted' => true]);

        Livewire::actingAs($employee->fresh())
            ->test(ManageScenarios::class)
            ->assertTableActionVisible('archiveScenario', $scenario);
    }

    public function test_admin_can_create_and_edit_scenario_with_draft_json(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->callAction('create', [
                'code' => 'lead_router',
                'name' => 'Маршрутизация лида',
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $scenario = Scenario::query()->with('draftVersion')->firstOrFail();

        $this->assertSame('lead_router', $scenario->code);
        $this->assertSame('Маршрутизация лида', $scenario->name);
        $this->assertTrue($scenario->is_active);
        $this->assertFalse($scenario->is_archived);
        $this->assertSame(1, $scenario->draftVersion?->version_number);
        $this->assertSame(ScenarioVersion::STATUS_DRAFT, $scenario->draftVersion?->status);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->callTableAction('edit', $scenario, [
                'name' => 'Маршрутизация заявок',
                'is_active' => false,
                'draft_schema_payload_json' => <<<'JSON'
{
    "version": 1,
    "start_block_id": "welcome",
    "triggers": [
        {
            "type": "parameter",
            "value": "lead_router"
        }
    ],
    "blocks": {
        "welcome": {
            "type": "message",
            "text": "Добро пожаловать",
            "next": "ask_name"
        },
        "ask_name": {
            "type": "question",
            "text": "Как вас зовут?",
            "save_to": "run.first_name",
            "next": "end"
        },
        "end": {
            "type": "complete"
        }
    }
}
JSON,
            ])
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load('draftVersion');

        $this->assertSame('lead_router', $scenario->code);
        $this->assertSame('Маршрутизация заявок', $scenario->name);
        $this->assertFalse($scenario->is_active);
        $this->assertSame([
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'lead_router',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'text_format' => 'plain_text',
                    'next' => 'ask_name',
                ],
                'ask_name' => [
                    'type' => 'question',
                    'text' => 'Как вас зовут?',
                    'text_format' => 'plain_text',
                    'expects' => 'text',
                    'save_to' => 'run.first_name',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ], $scenario->draftVersion?->schema_payload);
    }

    public function test_admin_can_save_slice_two_lite_schema_with_condition_and_tag_actions(): void
    {
        $strongTag = Tag::factory()->create([
            'name' => 'VIP strong',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'VIP weak',
        ]);

        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'slice_two_lite_schema',
            'name' => 'Проверка slice 2 lite',
            'is_active' => true,
        ]);

        ScenarioResource::saveScenario([
            'name' => $scenario->name,
            'is_active' => true,
            'draft_schema_payload_json' => <<<JSON
{
    "version": 1,
    "start_block_id": "welcome",
    "triggers": [
        {
            "type": "parameter",
            "value": "slice_two_lite_schema"
        }
    ],
    "blocks": {
        "welcome": {
            "type": "message",
            "text": "Старт",
            "next": "ask_budget"
        },
        "ask_budget": {
            "type": "question",
            "text": "Какой у вас бюджет?",
            "save_to": "run.budget_tier",
            "next": "evaluate"
        },
        "evaluate": {
            "type": "condition",
            "branches": [
                {
                    "if": {
                        "var": "run.budget_tier",
                        "in": ["middle", "high"]
                    },
                    "then": "strong_branch"
                },
                {
                    "default": "weak_branch"
                }
            ]
        },
        "strong_branch": {
            "type": "message",
            "text": "Подходит",
            "actions": [
                {"type": "set_tag", "value": "{$strongTag->slug}"},
                {"type": "remove_tag", "value": "{$weakTag->slug}"}
            ],
            "next": "end"
        },
        "weak_branch": {
            "type": "message",
            "text": "Пока рано",
            "actions": [
                {"type": "set_tag", "value": "{$weakTag->slug}"}
            ],
            "next": "end"
        },
        "end": {
            "type": "complete"
        }
    }
}
JSON,
        ], $scenario);

        $scenario->refresh();
        $scenario->load('draftVersion');

        $this->assertSame('condition', data_get($scenario->draftVersion?->schema_payload, 'blocks.evaluate.type'));
        $this->assertSame($strongTag->slug, data_get($scenario->draftVersion?->schema_payload, 'blocks.strong_branch.actions.0.value'));
        $this->assertSame($weakTag->slug, data_get($scenario->draftVersion?->schema_payload, 'blocks.weak_branch.actions.0.value'));
    }

    public function test_save_scenario_rejects_condition_with_non_run_variable(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'slice_two_lite_validation',
            'name' => 'Проверка condition',
            'is_active' => true,
        ]);

        try {
            ScenarioResource::saveScenario([
                'name' => $scenario->name,
                'is_active' => true,
                'draft_schema_payload_json' => <<<'JSON'
{
    "version": 1,
    "start_block_id": "evaluate",
    "triggers": [
        {
            "type": "parameter",
            "value": "slice_two_lite_validation"
        }
    ],
    "blocks": {
        "evaluate": {
            "type": "condition",
            "branches": [
                {
                    "if": {
                        "var": "contact.city",
                        "equals": "Москва"
                    },
                    "then": "end"
                },
                {
                    "default": "end"
                }
            ]
        },
        "end": {
            "type": "complete"
        }
    }
}
JSON,
            ], $scenario);

            $this->fail('Slice 2 lite schema validation should reject non-run variables.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Блок evaluate, ветка #0 может читать только run.*.',
                $exception->errors()['draft_schema_payload_json'][0] ?? null,
            );
        }
    }

    public function test_save_scenario_rejects_action_with_unknown_tag_slug(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'slice_two_lite_tags',
            'name' => 'Проверка тегов',
            'is_active' => true,
        ]);

        try {
            ScenarioResource::saveScenario([
                'name' => $scenario->name,
                'is_active' => true,
                'draft_schema_payload_json' => <<<'JSON'
{
    "version": 1,
    "start_block_id": "welcome",
    "triggers": [
        {
            "type": "parameter",
            "value": "slice_two_lite_tags"
        }
    ],
    "blocks": {
        "welcome": {
            "type": "message",
            "text": "Старт",
            "actions": [
                {"type": "set_tag", "value": "missing-tag"}
            ],
            "next": "end"
        },
        "end": {
            "type": "complete"
        }
    }
}
JSON,
            ], $scenario);

            $this->fail('Slice 2 lite schema validation should reject missing tag slugs.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Action #0 блока welcome ссылается на несуществующий или неактивный тег missing-tag.',
                $exception->errors()['draft_schema_payload_json'][0] ?? null,
            );
        }
    }

    public function test_admin_cannot_publish_empty_draft_schema(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'empty_publish',
            'name' => 'Пустой publish',
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->assertTableActionVisible('publishDraft', $scenario)
            ->callTableAction('publishDraft', $scenario)
            ->assertHasTableActionErrors();

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertSame(ScenarioVersion::STATUS_DRAFT, $scenario->draftVersion?->status);
        $this->assertNull($scenario->publishedVersion);
    }

    public function test_admin_cannot_publish_legacy_draft_schema(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'legacy_publish',
            'name' => 'Legacy publish',
            'is_active' => true,
        ]);

        $scenario->draftVersion()->firstOrFail()->forceFill([
            'schema_payload' => [
                'steps' => [
                    ['id' => 'q1', 'type' => 'text_question'],
                ],
            ],
        ])->save();

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->assertTableActionVisible('publishDraft', $scenario)
            ->callTableAction('publishDraft', $scenario)
            ->assertHasTableActionErrors();

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertSame(ScenarioVersion::STATUS_DRAFT, $scenario->draftVersion?->status);
        $this->assertNull($scenario->publishedVersion);
    }

    public function test_admin_can_publish_create_next_draft_and_archive_scenario(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'qualification',
            'name' => 'Квалификация',
            'is_active' => true,
        ]);

        $scenario->draftVersion()->firstOrFail()->forceFill([
            'schema_payload' => $this->sliceOneSchema('qualification'),
        ])->save();

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->assertTableActionVisible('publishDraft', $scenario)
            ->callTableAction('publishDraft', $scenario)
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertNull($scenario->draftVersion);
        $this->assertSame(1, $scenario->publishedVersion?->version_number);
        $this->assertSame(ScenarioVersion::STATUS_PUBLISHED, $scenario->publishedVersion?->status);
        $this->assertSame($this->sliceOneSchema('qualification'), $scenario->publishedVersion?->schema_payload);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->assertTableActionVisible('createNextDraft', $scenario)
            ->callTableAction('createNextDraft', $scenario)
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertSame(2, $scenario->draftVersion?->version_number);
        $this->assertSame(ScenarioVersion::STATUS_DRAFT, $scenario->draftVersion?->status);
        $this->assertSame(
            $scenario->publishedVersion?->schema_payload,
            $scenario->draftVersion?->schema_payload,
        );

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->assertTableActionVisible('archiveScenario', $scenario)
            ->callTableAction('archiveScenario', $scenario)
            ->assertHasNoTableActionErrors();

        $scenario->refresh();

        $this->assertTrue($scenario->is_archived);
        $this->assertFalse($scenario->is_active);
    }

    public function test_scenarios_table_uses_inline_list_page_standard(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'retention',
            'name' => 'Удержание',
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->assertTableActionHasIcon('publishDraft', Heroicon::OutlinedBolt, $scenario)
            ->assertTableActionHasIcon('createNextDraft', Heroicon::OutlinedArrowPath, $scenario)
            ->assertTableActionHasIcon('edit', Heroicon::OutlinedPencilSquare, $scenario)
            ->assertTableActionHasIcon('archiveScenario', Heroicon::OutlinedTrash, $scenario)
            ->assertTableActionDoesNotHaveLabel('edit', $scenario)
            ->tap(function ($component): void {
                $table = $component->instance()->getTable();

                $this->assertTrue($table->hasColumnManager());
                $this->assertFalse($table->hasDeferredColumnManager());
                $this->assertFalse($table->getColumnManagerApplyAction()->isVisible());
                $this->assertSame('Кнопки', $table->getRecordActionsColumnLabel());
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function sliceOneSchema(string $triggerValue): array
    {
        return [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => $triggerValue,
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'text_format' => 'plain_text',
                    'next' => 'ask_name',
                ],
                'ask_name' => [
                    'type' => 'question',
                    'text' => 'Как вас зовут?',
                    'text_format' => 'plain_text',
                    'expects' => 'text',
                    'save_to' => 'run.first_name',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ];
    }
}
