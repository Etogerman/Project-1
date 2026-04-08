<?php

namespace Tests\Feature;

use App\Filament\Resources\Scenarios\Pages\ManageScenarios;
use App\Filament\Resources\Scenarios\ScenarioResource;
use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Scenarios\CreateScenarioAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
    "steps": [
        {
            "id": "start",
            "type": "message"
        }
    ]
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
            'steps' => [
                [
                    'id' => 'start',
                    'type' => 'message',
                ],
            ],
        ], $scenario->draftVersion?->schema_payload);
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
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertNull($scenario->draftVersion);
        $this->assertSame(1, $scenario->publishedVersion?->version_number);
        $this->assertSame(ScenarioVersion::STATUS_PUBLISHED, $scenario->publishedVersion?->status);

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
}
