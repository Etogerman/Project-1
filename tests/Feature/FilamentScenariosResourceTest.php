<?php

namespace Tests\Feature;

use App\Filament\Resources\Scenarios\Pages\ManageScenarios;
use App\Filament\Resources\Scenarios\ScenarioResource;
use App\Filament\Pages\ScenarioConstructor;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Scenario;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioBuilderCondition;
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
use Tests\Feature\Concerns\BuildsIbizaMvpSchema;

class FilamentScenariosResourceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsIbizaMvpSchema;

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

    public function test_active_admin_can_open_scenarios_page_with_existing_scenario(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        app(CreateScenarioAction::class)->handle([
            'code' => 'slice3_page_open',
            'name' => 'Проверка страницы сценариев',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(ScenarioResource::getUrl())
            ->assertOk()
            ->assertSee('Сценарии')
            ->assertSee('Проверка страницы сценариев');
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

    public function test_admin_can_configure_green_start_block_without_manual_json(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram локалка',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->callAction('create', [
                'code' => 'green_start_builder',
                'name' => 'Зелёный старт',
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $scenario = Scenario::query()->with('draftVersion')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ScenarioConstructor::class, ['scenario' => $scenario->id])
            ->set('draftSchemaPayloadJson', '{}')
            ->set('draftStartTriggers', [
                ['value' => 'green_start_apply'],
                ['value' => 'green_start_tg1'],
            ])
            ->set('draftStartConditionMatch', 'exact')
            ->set('draftStartReplyText', 'Ответ зелёного старта')
            ->set('draftStartChannelIds', [$channel->id])
            ->set('draftStartBlockId', 'welcome')
            ->set('draftStartNodeTitle', 'Зелёный старт')
            ->set('draftStartNodePosition', ['x' => 180, 'y' => 120])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $scenario->refresh();
        $scenario->load('draftVersion');

        $expectedPublishedSchema = [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'green_start_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'green_start_tg1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Ответ зелёного старта',
                    'text_format' => 'plain_text',
                    'next' => 'done',
                ],
                'done' => [
                    'type' => 'complete',
                ],
            ],
        ];
        $this->assertSame($expectedPublishedSchema, collect($scenario->draftVersion?->schema_payload)->except('builder_schema')->all());

        $builderBlock = ScenarioBuilderBlock::query()
            ->with(['channels', 'conditions', 'outgoingEdges'])
            ->where('scenario_version_id', $scenario->draftVersion?->id)
            ->where('type', ScenarioBuilderBlock::TYPE_START_CONDITION)
            ->firstOrFail();

        $this->assertSame('Зелёный старт', $builderBlock->title);
        $this->assertSame([$channel->id], $builderBlock->channels->pluck('id')->all());
        $this->assertSame(['x' => 180, 'y' => 120], [
            'x' => $builderBlock->position_x,
            'y' => $builderBlock->position_y,
        ]);
        $this->assertSame(
            ['green_start_apply', 'green_start_tg1'],
            $builderBlock->conditions->pluck('value')->all(),
        );
        $this->assertSame('welcome', $builderBlock->outgoingEdges->first()?->to_runtime_block_id);
        $this->assertSame(
            $builderBlock->id,
            data_get($scenario->draftVersion?->schema_payload, "builder_schema.blocks.{$builderBlock->id}.id"),
        );
        $this->assertSame(
            [
                'type' => ScenarioBuilderCondition::TYPE_MESSAGE_PARAMETER,
                'match' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
                'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
                'values' => [
                    'green_start_apply',
                    'green_start_tg1',
                ],
            ],
            data_get($scenario->draftVersion?->schema_payload, "builder_schema.blocks.{$builderBlock->id}.settings.condition"),
        );
        $this->assertSame(
            'Ответ зелёного старта',
            data_get($scenario->draftVersion?->schema_payload, "builder_schema.blocks.{$builderBlock->id}.settings.message_text"),
        );

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->callTableAction('publishDraft', $scenario)
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertNull($scenario->draftVersion);
        $this->assertSame($expectedPublishedSchema, $scenario->publishedVersion?->schema_payload);
    }

    public function test_admin_can_use_scenario_constructor_action_for_green_start_block(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram визуальный',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);

        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'green_start_visual_builder',
            'name' => 'Визуальный старт',
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->assertTableActionVisible('builder', $scenario);

        $response = $this->actingAs($admin)
            ->get(ScenarioConstructor::getUrl(['scenario' => $scenario->id]));

        $this->assertFalse(
            ScenarioBuilderBlock::query()
                ->where('scenario_version_id', $scenario->fresh()->draftVersion?->id)
                ->where('type', ScenarioBuilderBlock::TYPE_START_CONDITION)
                ->exists(),
        );

        $response
            ->assertOk()
            ->assertSee('Конструктор')
            ->assertSee('Полотно конструктора')
            ->assertSee('Настройки элемента')
            ->assertSee('Стартовое условие')
            ->assertSee('Состояние диалога')
            ->assertSee('ID: #')
            ->assertSee('Тип блока')
            ->assertSee('Название блока')
            ->assertSee('Канал')
            ->assertSee('Telegram визуальный')
            ->assertSee('Условия')
            ->assertSee('Область срабатывания')
            ->assertSee('Содержит текст в сообщении')
            ->assertSee('Точное соответствие текста в сообщении')
            ->assertSee('Точное соответствие параметра сообщения')
            ->assertDontSee('Начинается с')
            ->assertDontSee('Заканчивается на')
            ->assertSee('Текст ответа')
            ->assertSee('Калькулятор')
            ->assertSee('Действия')
            ->assertSee('Кнопки')
            ->assertSee('События для аналитики')
            ->assertSee('Вложения')
            ->assertSee('start_condition')
            ->assertSee('Добавить стартовое условие');

        Livewire::actingAs($admin)
            ->test(ScenarioConstructor::class, ['scenario' => $scenario->id])
            ->set('draftSchemaPayloadJson', '{}')
            ->set('draftStartTriggers', [
                ['value' => 'visual_start_1'],
                ['value' => 'visual_start_2'],
            ])
            ->set('draftStartConditionMatch', AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT)
            ->set('draftStartReplyText', 'Ответ из конструктора')
            ->set('draftStartChannelIds', [$channel->id])
            ->set('draftStartBlockId', 'welcome')
            ->set('draftStartNodeTitle', 'Стартовая точка')
            ->set('draftStartNodePosition', ['x' => 240, 'y' => 160])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $scenario->refresh();
        $scenario->load('draftVersion');

        $builderBlock = ScenarioBuilderBlock::query()
            ->where('scenario_version_id', $scenario->draftVersion?->id)
            ->where('type', ScenarioBuilderBlock::TYPE_START_CONDITION)
            ->firstOrFail();

        $this->assertSame(
            [
                [
                    'type' => 'parameter',
                    'value' => 'visual_start_1',
                    'match_scope' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
                ],
                [
                    'type' => 'parameter',
                    'value' => 'visual_start_2',
                    'match_scope' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
                ],
            ],
            $scenario->draftVersion?->schema_payload['triggers'] ?? null,
        );
        $this->assertSame('welcome', $scenario->draftVersion?->schema_payload['start_block_id'] ?? null);
        $this->assertSame('Ответ из конструктора', $scenario->draftVersion?->schema_payload['blocks']['welcome']['text'] ?? null);
        $this->assertSame(
            'Стартовая точка',
            data_get($scenario->draftVersion?->schema_payload, "builder_schema.blocks.{$builderBlock->id}.title"),
        );
        $this->assertSame(
            'start_condition',
            data_get($scenario->draftVersion?->schema_payload, "builder_schema.blocks.{$builderBlock->id}.type"),
        );
        $this->assertSame(
            [
                'x' => 240,
                'y' => 160,
            ],
            data_get($scenario->draftVersion?->schema_payload, "builder_schema.blocks.{$builderBlock->id}.position"),
        );
        $this->assertSame(
            [
                'type' => 'message_parameter',
                'match' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
                'variable' => 'message_parameter',
                'values' => [
                    'visual_start_1',
                    'visual_start_2',
                ],
            ],
            data_get($scenario->draftVersion?->schema_payload, "builder_schema.blocks.{$builderBlock->id}.settings.condition"),
        );

        $builderBlock->refresh();
        $builderBlock->load(['channels', 'conditions', 'outgoingEdges']);

        $this->assertSame('Стартовая точка', $builderBlock->title);
        $this->assertSame([$channel->id], $builderBlock->channels->pluck('id')->all());
        $this->assertSame(['visual_start_1', 'visual_start_2'], $builderBlock->conditions->pluck('value')->all());
        $this->assertSame(
            [AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT, AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT],
            $builderBlock->conditions->pluck('match_operator')->all(),
        );
        $this->assertSame('Ответ из конструктора', $builderBlock->settings_payload['message_text'] ?? null);
        $this->assertSame('welcome', $builderBlock->outgoingEdges->first()?->to_runtime_block_id);
    }

    public function test_admin_can_open_standalone_scenario_constructor(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'standalone_constructor',
            'name' => 'Отдельный конструктор',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)
            ->get(ScenarioConstructor::getUrl(['scenario' => $scenario->id]));

        $response
            ->assertOk()
            ->assertSee('Конструктор')
            ->assertSee('Полотно конструктора')
            ->assertSee('Добавить стартовое условие')
            ->assertSee('ID: #')
            ->assertSee('Тип блока');
    }

    public function test_admin_can_create_select_save_and_delete_multiple_green_start_blocks(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram мульти',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);

        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'green_start_multi_builder',
            'name' => 'Несколько зелёных блоков',
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ScenarioConstructor::class, ['scenario' => $scenario->id])
            ->set('draftSchemaPayloadJson', '{}')
            ->set('draftStartTriggers', [
                ['value' => 'primary_start'],
            ])
            ->set('draftStartChannelIds', [$channel->id])
            ->set('draftStartBlockId', 'welcome')
            ->set('draftStartNodeTitle', 'Основной старт')
            ->set('draftStartNodePosition', ['x' => 140, 'y' => 110])
            ->call('saveDraft')
            ->call('addStartBuilderBlock')
            ->assertHasNoErrors();

        $scenario->refresh();
        $scenario->load('draftVersion');

        $blocks = ScenarioBuilderBlock::query()
            ->with(['conditions', 'outgoingEdges'])
            ->where('scenario_version_id', $scenario->draftVersion?->id)
            ->where('type', ScenarioBuilderBlock::TYPE_START_CONDITION)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $blocks);

        /** @var ScenarioBuilderBlock $primaryBlock */
        $primaryBlock = $blocks[0];
        /** @var ScenarioBuilderBlock $secondaryBlock */
        $secondaryBlock = $blocks[1];

        Livewire::actingAs($admin)
            ->test(ScenarioConstructor::class, ['scenario' => $scenario->id])
            ->call('selectStartBuilderBlock', $secondaryBlock->id)
            ->set('draftStartTriggers', [
                ['value' => 'secondary_start_1'],
                ['value' => 'secondary_start_2'],
            ])
            ->set('draftStartChannelIds', [$channel->id])
            ->set('draftStartBlockId', 'welcome')
            ->set('draftStartNodeTitle', 'Второй старт')
            ->set('draftStartNodePosition', ['x' => 420, 'y' => 260])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $scenario->refresh();
        $scenario->load('draftVersion');
        $secondaryBlock->refresh();
        $secondaryBlock->load(['channels', 'conditions', 'outgoingEdges']);

        $this->assertSame('Второй старт', $secondaryBlock->title);
        $this->assertSame([$channel->id], $secondaryBlock->channels->pluck('id')->all());
        $this->assertSame(['secondary_start_1', 'secondary_start_2'], $secondaryBlock->conditions->pluck('value')->all());
        $this->assertSame(['x' => 420, 'y' => 260], [
            'x' => $secondaryBlock->position_x,
            'y' => $secondaryBlock->position_y,
        ]);
        $this->assertSame('welcome', $secondaryBlock->outgoingEdges->first()?->to_runtime_block_id);
        $this->assertSame(
            [
                [
                    'type' => 'parameter',
                    'value' => 'primary_start',
                ],
            ],
            $scenario->draftVersion?->schema_payload['triggers'] ?? null,
        );
        $this->assertSame(
            'Основной старт',
            data_get($scenario->draftVersion?->schema_payload, "builder_schema.blocks.{$primaryBlock->id}.title"),
        );
        $this->assertSame(
            'Второй старт',
            data_get($scenario->draftVersion?->schema_payload, "builder_schema.blocks.{$secondaryBlock->id}.title"),
        );
        $this->assertTrue((bool) data_get($scenario->draftVersion?->schema_payload, "builder_schema.blocks.{$primaryBlock->id}.is_primary"));
        $this->assertFalse((bool) data_get($scenario->draftVersion?->schema_payload, "builder_schema.blocks.{$secondaryBlock->id}.is_primary"));

        Livewire::actingAs($admin)
            ->test(ScenarioConstructor::class, ['scenario' => $scenario->id])
            ->call('moveStartBuilderBlock', $primaryBlock->id, 640, 360)
            ->assertHasNoErrors();

        $scenario->refresh();
        $scenario->load('draftVersion');
        $primaryBlock->refresh();

        $this->assertSame(['x' => 640, 'y' => 360], [
            'x' => $primaryBlock->position_x,
            'y' => $primaryBlock->position_y,
        ]);
        $this->assertSame(
            [
                'x' => 640,
                'y' => 360,
            ],
            data_get($scenario->draftVersion?->schema_payload, "builder_schema.blocks.{$primaryBlock->id}.position"),
        );

        Livewire::actingAs($admin)
            ->test(ScenarioConstructor::class, ['scenario' => $scenario->id])
            ->call('selectStartBuilderBlock', $secondaryBlock->id)
            ->call('deleteSelectedStartBuilderBlock')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('scenario_builder_blocks', [
            'id' => $secondaryBlock->id,
        ]);
        $this->assertDatabaseHas('scenario_builder_blocks', [
            'id' => $primaryBlock->id,
        ]);
    }

    public function test_next_draft_copies_normalized_builder_blocks_from_published_version(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram копия',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);

        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'green_start_builder_copy',
            'name' => 'Копия конструктора',
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ScenarioConstructor::class, ['scenario' => $scenario->id])
            ->set('draftSchemaPayloadJson', '{}')
            ->set('draftStartTriggers', [
                ['value' => 'copy_start_1'],
                ['value' => 'copy_start_2'],
            ])
            ->set('draftStartChannelIds', [$channel->id])
            ->set('draftStartBlockId', 'welcome')
            ->set('draftStartNodeTitle', 'Блок для копии')
            ->set('draftStartNodePosition', ['x' => 320, 'y' => 210])
            ->call('saveDraft')
            ->assertHasNoErrors();

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->callTableAction('publishDraft', $scenario)
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load('publishedVersion');

        $publishedBlock = ScenarioBuilderBlock::query()
            ->with(['channels', 'conditions', 'outgoingEdges'])
            ->where('scenario_version_id', $scenario->publishedVersion?->id)
            ->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->callTableAction('createNextDraft', $scenario)
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load('draftVersion');

        $draftBlock = ScenarioBuilderBlock::query()
            ->with(['channels', 'conditions', 'outgoingEdges'])
            ->where('scenario_version_id', $scenario->draftVersion?->id)
            ->firstOrFail();

        $this->assertNotSame($publishedBlock->id, $draftBlock->id);
        $this->assertSame(ScenarioBuilderBlock::TYPE_START_CONDITION, $draftBlock->type);
        $this->assertSame('Блок для копии', $draftBlock->title);
        $this->assertSame($publishedBlock->channels->pluck('id')->all(), $draftBlock->channels->pluck('id')->all());
        $this->assertSame(['copy_start_1', 'copy_start_2'], $draftBlock->conditions->pluck('value')->all());
        $this->assertSame('welcome', $draftBlock->outgoingEdges->first()?->to_runtime_block_id);
    }

    public function test_green_start_block_rejects_empty_and_duplicate_triggers(): void
    {
        $emptyScenario = app(CreateScenarioAction::class)->handle([
            'code' => 'green_start_empty',
            'name' => 'Пустой старт',
            'is_active' => true,
        ]);

        try {
            ScenarioResource::saveScenario([
                'name' => $emptyScenario->name,
                'is_active' => true,
                'draft_start_triggers' => [
                    ['value' => ''],
                ],
                'draft_start_block_id' => 'welcome',
            ], $emptyScenario);

            $this->fail('Green start editor should reject empty trigger values.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Значение trigger-а не может быть пустым.',
                $exception->errors()['draft_start_triggers'][0] ?? null,
            );
        }

        $duplicateScenario = app(CreateScenarioAction::class)->handle([
            'code' => 'green_start_duplicate',
            'name' => 'Дубли старт',
            'is_active' => true,
        ]);

        try {
            ScenarioResource::saveScenario([
                'name' => $duplicateScenario->name,
                'is_active' => true,
                'draft_start_triggers' => [
                    ['value' => 'same_trigger'],
                    ['value' => 'same_trigger'],
                ],
                'draft_start_block_id' => 'welcome',
            ], $duplicateScenario);

            $this->fail('Green start editor should reject duplicate trigger values.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Trigger-ы не должны повторяться.',
                $exception->errors()['draft_start_triggers'][0] ?? null,
            );
        }
    }

    public function test_green_start_block_updates_only_draft_and_preserves_existing_blocks(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'green_start_draft_only',
            'name' => 'Draft only start',
            'is_active' => true,
        ]);
        $publishedSchema = $this->sliceOneSchema('original_trigger');

        $scenario->draftVersion()->firstOrFail()->forceFill([
            'schema_payload' => $publishedSchema,
        ])->save();

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->callTableAction('publishDraft', $scenario)
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertNull($scenario->draftVersion);
        $this->assertSame($publishedSchema, $scenario->publishedVersion?->schema_payload);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->callTableAction('createNextDraft', $scenario)
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        ScenarioResource::saveScenario([
            'name' => $scenario->name,
            'is_active' => true,
            'draft_start_triggers' => [
                ['value' => 'updated_trigger'],
                ['value' => 'updated_trigger_alt'],
            ],
            'draft_start_block_id' => 'welcome',
        ], $scenario);

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertSame(
            [
                [
                    'type' => 'parameter',
                    'value' => 'original_trigger',
                ],
            ],
            $scenario->publishedVersion?->schema_payload['triggers'] ?? null,
        );
        $this->assertSame(
            [
                [
                    'type' => 'parameter',
                    'value' => 'updated_trigger',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'updated_trigger_alt',
                ],
            ],
            $scenario->draftVersion?->schema_payload['triggers'] ?? null,
        );
        $this->assertSame(
            $publishedSchema['blocks'],
            $scenario->draftVersion?->schema_payload['blocks'] ?? null,
        );
    }

    public function test_manual_json_fallback_wins_when_json_changes_alongside_green_start_fields(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'green_start_json_fallback',
            'name' => 'JSON fallback',
            'is_active' => true,
        ]);
        $jsonSchema = $this->sliceOneSchema('json_trigger');

        ScenarioResource::saveScenario([
            'name' => $scenario->name,
            'is_active' => true,
            'draft_schema_payload_json' => json_encode(
                $jsonSchema,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'draft_start_triggers' => [
                ['value' => 'stale_visual_trigger'],
            ],
            'draft_start_block_id' => 'welcome',
        ], $scenario);

        $scenario->refresh();
        $scenario->load('draftVersion');

        $this->assertSame($jsonSchema, $scenario->draftVersion?->schema_payload);
    }

    public function test_published_scenario_without_draft_still_shows_green_start_preview_and_draft_hint(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'green_start_published_preview',
            'name' => 'Published preview',
            'is_active' => true,
        ]);

        $scenario->draftVersion()->firstOrFail()->forceFill([
            'schema_payload' => $this->sliceOneSchema('published_preview_trigger'),
        ])->save();

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->callTableAction('publishDraft', $scenario)
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertNull($scenario->draftVersion);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->mountTableAction('edit', $scenario)
            ->assertMountedActionModalSee('Стартовое условие')
            ->assertMountedActionModalSee('Опубликованная версия показана только для просмотра')
            ->assertMountedActionModalSee('published_preview_trigger')
            ->assertMountedActionModalSee('Создать новый черновик');
    }

    public function test_edit_modal_points_green_start_changes_to_constructor_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'green_start_edit_entrypoint',
            'name' => 'Edit entrypoint',
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->mountTableAction('edit', $scenario)
            ->assertMountedActionModalSee('Визуальное редактирование вынесено в конструктор')
            ->assertMountedActionModalSee('Открыть конструктор');
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

    public function test_save_scenario_rejects_default_branch_with_extra_keys(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'slice_two_lite_default_shape',
            'name' => 'Проверка default-ветки',
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
            "value": "slice_two_lite_default_shape"
        }
    ],
    "blocks": {
        "evaluate": {
            "type": "condition",
            "branches": [
                {
                    "if": {
                        "var": "run.city",
                        "equals": "Москва"
                    },
                    "then": "done"
                },
                {
                    "default": "done",
                    "then": "unexpected"
                }
            ]
        },
        "done": {
            "type": "complete"
        }
    }
}
JSON,
            ], $scenario);

            $this->fail('Slice 2 lite schema validation should reject ambiguous default branches.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Ветка #1 блока evaluate содержит неподдерживаемые ключи: then.',
                $exception->errors()['draft_schema_payload_json'][0] ?? null,
            );
        }
    }

    public function test_save_scenario_rejects_condition_with_mixed_operator_shapes(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'slice_two_lite_mixed_condition',
            'name' => 'Проверка mixed-condition',
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
            "value": "slice_two_lite_mixed_condition"
        }
    ],
    "blocks": {
        "evaluate": {
            "type": "condition",
            "branches": [
                {
                    "if": {
                        "not": {
                            "var": "run.city",
                            "equals": "Москва"
                        },
                        "var": "run.country",
                        "equals": "Россия"
                    },
                    "then": "done"
                },
                {
                    "default": "done"
                }
            ]
        },
        "done": {
            "type": "complete"
        }
    }
}
JSON,
            ], $scenario);

            $this->fail('Slice 2 lite schema validation should reject mixed operator shapes.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Блок evaluate, ветка #0 должна содержать ровно один оператор условия.',
                $exception->errors()['draft_schema_payload_json'][0] ?? null,
            );
        }
    }

    public function test_admin_can_save_slice_three_schema_with_phone_capture(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'slice_three_phone_capture',
            'name' => 'Проверка slice 3',
            'is_active' => true,
        ]);

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
            "value": "slice_three_phone_capture"
        }
    ],
    "blocks": {
        "welcome": {
            "type": "message",
            "text": "Старт",
            "next": "capture_phone"
        },
        "capture_phone": {
            "type": "phone_capture",
            "text": "Поделитесь номером телефона.",
            "next": "done"
        },
        "done": {
            "type": "complete"
        }
    }
}
JSON,
        ], $scenario);

        $scenario->refresh();
        $scenario->load('draftVersion');

        $this->assertSame($this->sliceThreeSchema('slice_three_phone_capture'), $scenario->draftVersion?->schema_payload);
    }

    public function test_save_scenario_rejects_phone_capture_with_save_to(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'slice_three_phone_capture_invalid',
            'name' => 'Проверка slice 3 invalid',
            'is_active' => true,
        ]);

        try {
            ScenarioResource::saveScenario([
                'name' => $scenario->name,
                'is_active' => true,
                'draft_schema_payload_json' => <<<'JSON'
{
    "version": 1,
    "start_block_id": "capture_phone",
    "triggers": [
        {
            "type": "parameter",
            "value": "slice_three_phone_capture_invalid"
        }
    ],
    "blocks": {
        "capture_phone": {
            "type": "phone_capture",
            "text": "Поделитесь номером телефона.",
            "save_to": "run.phone",
            "next": "done"
        },
        "done": {
            "type": "complete"
        }
    }

    public function test_admin_can_save_and_publish_ibiza_mvp_schema(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'vip_ibiza',
            'name' => 'VIP Ibiza',
            'is_active' => true,
        ]);
        $schema = $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        );

        ScenarioResource::saveScenario([
            'name' => $scenario->name,
            'is_active' => true,
            'draft_schema_payload_json' => json_encode(
                $schema,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        ], $scenario);

        $scenario->refresh();
        $scenario->load('draftVersion');

        $this->assertSame($schema, $scenario->draftVersion?->schema_payload);

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
        $this->assertSame($schema, $scenario->publishedVersion?->schema_payload);
    }
}
JSON,
            ], $scenario);

            $this->fail('Slice 3 schema validation should reject phone_capture with save_to.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Блок capture_phone использует save_to, это не входит в текущий DB-runtime.',
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
        $this->assertSame(1, $scenario->publishedVersion?->version_number);
        $this->assertSame(ScenarioVersion::STATUS_PUBLISHED, $scenario->publishedVersion?->status);
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

    public function test_admin_can_restore_archived_published_scenario_and_it_remains_inactive(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'restore_published',
            'name' => 'Restore published',
            'is_active' => true,
        ]);

        $scenario->draftVersion()->firstOrFail()->forceFill([
            'schema_payload' => $this->sliceOneSchema('restore_published'),
        ])->save();

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->callTableAction('publishDraft', $scenario)
            ->assertHasNoTableActionErrors();

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->callTableAction('archiveScenario', $scenario)
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertTrue($scenario->is_archived);
        $this->assertFalse($scenario->is_active);
        $this->assertNull($scenario->draftVersion);
        $this->assertSame(1, $scenario->publishedVersion?->version_number);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->assertTableActionVisible('restoreScenario', $scenario)
            ->assertTableActionHidden('edit', $scenario)
            ->callTableAction('restoreScenario', $scenario)
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertFalse($scenario->is_archived);
        $this->assertFalse($scenario->is_active);
        $this->assertNull($scenario->draftVersion);
        $this->assertSame(1, $scenario->publishedVersion?->version_number);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->assertTableActionVisible('edit', $scenario)
            ->assertTableActionVisible('createNextDraft', $scenario);
    }

    public function test_admin_can_restore_archived_draft_only_scenario_and_continue_editing_same_draft(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'restore_draft_only',
            'name' => 'Restore draft only',
            'is_active' => true,
        ]);

        $draftVersion = $scenario->draftVersion()->firstOrFail();

        $draftVersion->forceFill([
            'schema_payload' => $this->sliceOneSchema('restore_draft_only'),
        ])->save();

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->callTableAction('archiveScenario', $scenario)
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertTrue($scenario->is_archived);
        $this->assertSame($draftVersion->id, $scenario->draftVersion?->id);
        $this->assertNull($scenario->publishedVersion);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->assertTableActionVisible('restoreScenario', $scenario)
            ->assertTableActionHidden('edit', $scenario)
            ->callTableAction('restoreScenario', $scenario)
            ->assertHasNoTableActionErrors();

        $scenario->refresh();
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertFalse($scenario->is_archived);
        $this->assertFalse($scenario->is_active);
        $this->assertSame($draftVersion->id, $scenario->draftVersion?->id);
        $this->assertNull($scenario->publishedVersion);

        Livewire::actingAs($admin)
            ->test(ManageScenarios::class)
            ->assertTableActionVisible('edit', $scenario)
            ->assertTableActionHidden('createNextDraft', $scenario)
            ->callTableAction('edit', $scenario, [
                'name' => 'Restore draft only updated',
                'is_active' => false,
                'draft_schema_payload_json' => <<<'JSON'
{
    "version": 1,
    "start_block_id": "welcome",
    "triggers": [
        {
            "type": "parameter",
            "value": "restore_draft_only"
        }
    ],
    "blocks": {
        "welcome": {
            "type": "message",
            "text": "Привет",
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
        $scenario->load(['draftVersion', 'publishedVersion']);

        $this->assertSame('Restore draft only updated', $scenario->name);
        $this->assertSame(
            [
                'version' => 1,
                'start_block_id' => 'welcome',
                'triggers' => [
                    [
                        'type' => 'parameter',
                        'value' => 'restore_draft_only',
                    ],
                ],
                'blocks' => [
                    'welcome' => [
                        'type' => 'message',
                        'text' => 'Привет',
                        'text_format' => 'plain_text',
                        'next' => 'end',
                    ],
                    'end' => [
                        'type' => 'complete',
                    ],
                ],
            ],
            $scenario->draftVersion?->schema_payload,
        );
    }

    public function test_archived_scenario_cannot_be_mutated_via_save_scenario_before_restore(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'archived_save_guard',
            'name' => 'Archived save guard',
            'is_active' => true,
        ]);

        $scenario->forceFill([
            'is_active' => false,
            'is_archived' => true,
        ])->save();

        try {
            ScenarioResource::saveScenario([
                'name' => 'Changed after archive',
                'is_active' => true,
            ], $scenario->fresh());

            $this->fail('Archived scenario save should require restore first.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Архивный сценарий сначала нужно восстановить.',
                $exception->errors()['scenario'][0] ?? null,
            );
        }
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

    /**
     * @return array<string, mixed>
     */
    private function sliceThreeSchema(string $triggerValue): array
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
                    'text' => 'Старт',
                    'text_format' => 'plain_text',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'text_format' => 'plain_text',
                    'next' => 'done',
                ],
                'done' => [
                    'type' => 'complete',
                ],
            ],
        ];
    }
}
