<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioBuilderCondition;
use App\Models\ScenarioRun;
use App\Models\ScenarioV3ScheduledTransition;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Scenarios\CreateScenarioAction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScenarioBuilderV3StateTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_state_returns_empty_v3_builder_for_editable_version(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_empty',
            'name' => 'V3 Empty',
        ]);

        $this->actingAs($admin)
            ->getJson($this->stateUrl($scenario))
            ->assertOk()
            ->assertJsonPath('scenario.id', $scenario->id)
            ->assertJsonPath('scenario.draft_version_id', $scenario->draftVersion()->firstOrFail()->id)
            ->assertJsonPath('builder.schema_version', 3)
            ->assertJsonPath('builder.active_sheet_id', 'main')
            ->assertJsonPath('builder.blocks', [])
            ->assertJsonPath('builder.edges', [])
            ->assertJsonPath('builder.visible_scope.block_ids', [])
            ->assertJsonPath('server.timezone', config('app.timezone', 'UTC'));
    }

    public function test_get_state_adapts_existing_start_block_without_writing_database(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['name' => 'Telegram Test']);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_existing_start',
            'name' => 'V3 Existing Start',
        ]);
        $draft = $scenario->draftVersion()->firstOrFail();
        $block = ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $draft->id,
            'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
            'title' => 'Старт',
            'position_x' => 120,
            'position_y' => 160,
            'settings_payload' => ['message_text' => 'Привет'],
        ]);
        $block->channels()->sync([(int) $channel->id]);
        ScenarioBuilderCondition::query()->create([
            'scenario_builder_block_id' => $block->id,
            'type' => ScenarioBuilderCondition::TYPE_MESSAGE_PARAMETER,
            'match_operator' => 'strict',
            'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
            'value' => '/start',
            'sort_order' => 1,
        ]);
        $updatedAt = $draft->fresh()->updated_at;

        $this->actingAs($admin)
            ->getJson($this->stateUrl($scenario))
            ->assertOk()
            ->assertJsonPath('builder.blocks.0.id', $block->id)
            ->assertJsonPath('builder.blocks.0.settings_payload.modules.0.type', 'start_condition')
            ->assertJsonPath('builder.blocks.0.settings_payload.modules.0.payload.command', '/start')
            ->assertJsonPath('builder.blocks.0.settings_payload.modules.0.payload.channels.ids.0', $channel->id)
            ->assertJsonPath('builder.blocks.0.settings_payload.modules.1.type', 'message')
            ->assertJsonPath('builder.visible_scope.block_ids.0', $block->id);

        $this->assertTrue($updatedAt->equalTo($draft->fresh()->updated_at));
    }

    public function test_put_state_saves_simple_graph_and_returns_normalized_state(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_save_simple',
            'name' => 'V3 Save Simple',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $payload = $this->payloadFromState($state, [
            [
                'id' => null,
                'client_key' => 'tmp_block_1',
                'type' => 'state',
                'title' => 'Приветствие',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->messageSettings('Привет! Чем помочь?'),
            ],
        ]);

        $response = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $payload)
            ->assertOk()
            ->assertJsonPath('builder.blocks.0.title', 'Приветствие')
            ->assertJsonPath('builder.blocks.0.settings_payload.modules.0.payload.text', 'Привет! Чем помочь?');

        $blockId = $response->json('id_map.blocks.tmp_block_1');

        $this->assertIsInt($blockId);
        $this->assertSame((string) $blockId, $response->json('builder.blocks.0.display_id'));
        $this->assertSame((string) $blockId, $response->json('builder.blocks.0.settings_payload.ui.card_id'));
        $this->assertDatabaseHas('scenario_builder_blocks', [
            'id' => $blockId,
            'scenario_version_id' => $scenario->fresh()->draftVersion?->id,
            'type' => 'state',
            'title' => 'Приветствие',
            'position_x' => 120,
            'position_y' => 160,
        ]);
    }

    public function test_put_state_preserves_v3_block_kind_in_settings_payload(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_save_kind',
            'name' => 'V3 Save Kind',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $settings = $this->messageSettings('Технический переход');
        $settings['kind'] = 'non_state';
        $payload = $this->payloadFromState($state, [
            [
                'id' => null,
                'client_key' => 'tmp_block_kind',
                'type' => 'state',
                'title' => 'Переход',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $settings,
            ],
        ]);

        $response = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $payload)
            ->assertOk()
            ->assertJsonPath('builder.blocks.0.settings_payload.kind', 'non_state');

        $blockId = $response->json('id_map.blocks.tmp_block_kind');

        $this->assertDatabaseHas('scenario_builder_blocks', [
            'id' => $blockId,
            'type' => 'state',
        ]);
        $this->assertSame(
            'non_state',
            ScenarioBuilderBlock::query()->findOrFail($blockId)->settings_payload['kind'] ?? null,
        );
    }

    public function test_put_state_assigns_stable_backend_edge_key(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_edge_key',
            'name' => 'V3 Edge Key',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_source',
                'type' => 'state',
                'title' => 'Источник',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->messageSettings('Напишите код'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_target',
                'type' => 'state',
                'title' => 'Цель',
                'position' => ['x' => 480, 'y' => 160],
                'settings_payload' => $this->messageSettings('Принято'),
            ],
        ];
        $edges = [[
            'id' => null,
            'client_key' => 'tmp_edge',
            'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
            'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
            'condition_payload' => $this->edgePayload(null, 'Код'),
        ]];

        $saved = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, $edges))
            ->assertOk()
            ->json();

        $edgeKey = data_get($saved, 'builder.edges.0.condition_payload.edge_key');
        $edgeDisplayId = data_get($saved, 'builder.edges.0.condition_payload.ui.edge_id');

        $this->assertIsString($edgeKey);
        $this->assertMatchesRegularExpression('/^edge_[a-z0-9]{12}$/', $edgeKey);
        $this->assertSame((string) $saved['id_map']['edges']['tmp_edge'], $edgeDisplayId);

        $edgesWithInvalidDisplayId = $saved['builder']['edges'];
        data_set($edgesWithInvalidDisplayId, '0.condition_payload.ui.edge_id', 'j1nvpzsuc4ue');

        $savedAgain = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($saved, $saved['builder']['blocks'], $edgesWithInvalidDisplayId))
            ->assertOk()
            ->json();

        $this->assertSame($edgeKey, data_get($savedAgain, 'builder.edges.0.condition_payload.edge_key'));
        $this->assertSame($edgeDisplayId, data_get($savedAgain, 'builder.edges.0.condition_payload.ui.edge_id'));
    }

    public function test_put_state_allows_multiple_wait_reply_edges_from_one_block(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_multiple_wait_edges',
            'name' => 'V3 Multiple Wait Edges',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_source',
                'type' => 'state',
                'title' => 'Источник',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->messageSettings('Выберите вариант'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_target_a',
                'type' => 'state',
                'title' => 'Цель A',
                'position' => ['x' => 480, 'y' => 120],
                'settings_payload' => $this->messageSettings('Первый вариант'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_target_b',
                'type' => 'state',
                'title' => 'Цель B',
                'position' => ['x' => 480, 'y' => 260],
                'settings_payload' => $this->messageSettings('Второй вариант'),
            ],
        ];
        $firstPayload = $this->edgePayload(null, 'Первый');
        $firstPayload['match'] = ['type' => 'exact_text', 'text' => '1'];
        $secondPayload = $this->edgePayload(null, 'Второй');
        $secondPayload['match'] = ['type' => 'exact_text', 'text' => '2'];
        $edges = [
            [
                'id' => null,
                'client_key' => 'tmp_edge_a',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target_a'],
                'condition_payload' => $firstPayload,
            ],
            [
                'id' => null,
                'client_key' => 'tmp_edge_b',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target_b'],
                'condition_payload' => $secondPayload,
            ],
        ];

        $response = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, $edges))
            ->assertOk();

        $this->assertCount(2, $response->json('builder.edges'));
        $this->assertSame('wait_reply', $response->json('builder.edges.0.condition_payload.mode'));
        $this->assertSame('wait_reply', $response->json('builder.edges.1.condition_payload.mode'));
        $this->assertDatabaseCount('scenario_builder_edges', 2);
    }

    public function test_publish_keeps_extended_wait_reply_match_types_in_runtime_snapshot(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_extended_edge_match',
            'name' => 'V3 Extended Edge Match',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_source',
                'type' => 'state',
                'title' => 'Источник',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->startSettings('/start', [(int) $channel->id]),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_parameter',
                'type' => 'state',
                'title' => 'Параметр',
                'position' => ['x' => 480, 'y' => 80],
                'settings_payload' => $this->messageSettings('Параметр'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_text_or_parameter',
                'type' => 'state',
                'title' => 'Текст или параметр',
                'position' => ['x' => 480, 'y' => 220],
                'settings_payload' => $this->messageSettings('Текст или параметр'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_callback',
                'type' => 'state',
                'title' => 'Callback',
                'position' => ['x' => 480, 'y' => 360],
                'settings_payload' => $this->messageSettings('Callback'),
            ],
        ];
        $parameterPayload = $this->edgePayload(null, 'Параметр');
        $parameterPayload['match'] = ['type' => 'exact_parameter', 'text' => "payload_1\npayload_2"];
        $parameterPayload['contact_phone_condition'] = 'has_phone';
        $parameterPayload['dialog_phone_condition'] = 'missing_phone';
        $textOrParameterPayload = $this->edgePayload(null, 'Текст или параметр');
        $textOrParameterPayload['match'] = ['type' => 'exact_text_or_parameter', 'text' => 'mixed_1'];
        $callbackPayload = $this->edgePayload(null, 'Callback');
        $callbackPayload['match'] = ['type' => 'exact_callback', 'text' => 'callback_1'];
        $callbackPayload['field_condition'] = [
            'enabled' => true,
            'field_scope' => 'dialog',
            'field_key' => 'lead_status',
            'operator' => 'equals',
            'value' => 'hot',
        ];
        $edges = [
            [
                'id' => null,
                'client_key' => 'tmp_edge_parameter',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_parameter'],
                'condition_payload' => $parameterPayload,
            ],
            [
                'id' => null,
                'client_key' => 'tmp_edge_text_or_parameter',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_text_or_parameter'],
                'condition_payload' => $textOrParameterPayload,
            ],
            [
                'id' => null,
                'client_key' => 'tmp_edge_callback',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_callback'],
                'condition_payload' => $callbackPayload,
            ],
        ];

        $saved = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, $edges))
            ->assertOk()
            ->assertJsonPath('builder.edges.0.condition_payload.match.type', 'exact_parameter')
            ->assertJsonPath('builder.edges.0.condition_payload.contact_phone_condition', 'has_phone')
            ->assertJsonPath('builder.edges.0.condition_payload.dialog_phone_condition', 'missing_phone')
            ->assertJsonPath('builder.edges.1.condition_payload.match.type', 'exact_text_or_parameter')
            ->assertJsonPath('builder.edges.2.condition_payload.match.type', 'exact_callback')
            ->assertJsonPath('builder.edges.2.condition_payload.field_condition.field_key', 'lead_status')
            ->assertJsonPath('builder.edges.2.condition_payload.field_condition.operator', 'equals')
            ->json();

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $saved['scenario']['draft_version_id'],
                'base_revision' => $saved['builder']['revision'],
            ])
            ->assertOk();

        $scenario->refresh()->load('publishedVersion');
        $sourceBlockId = (string) $saved['id_map']['blocks']['tmp_source'];
        $waitReplyEdges = data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$sourceBlockId.wait_reply_edges", []);
        $edgesByMatchType = collect($waitReplyEdges)->keyBy('match.type');

        $this->assertSame(
            ['exact_callback', 'exact_parameter', 'exact_text_or_parameter'],
            collect($waitReplyEdges)->pluck('match.type')->sort()->values()->all(),
        );
        $this->assertSame(['payload_1', 'payload_2'], data_get($edgesByMatchType->get('exact_parameter'), 'match.variants'));
        $this->assertSame('has_phone', data_get($edgesByMatchType->get('exact_parameter'), 'contact_phone_condition'));
        $this->assertSame('missing_phone', data_get($edgesByMatchType->get('exact_parameter'), 'dialog_phone_condition'));
        $this->assertSame('lead_status', data_get($edgesByMatchType->get('exact_callback'), 'field_condition.field_key'));
        $this->assertSame('hot', data_get($edgesByMatchType->get('exact_callback'), 'field_condition.value'));
    }

    public function test_put_state_allows_first_name_source_contact_field_condition(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_first_name_source_condition',
            'name' => 'V3 First Name Source Condition',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_source',
                'type' => 'state',
                'title' => 'Источник',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->startSettings('/start', [(int) $channel->id]),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_target',
                'type' => 'state',
                'title' => 'Цель',
                'position' => ['x' => 480, 'y' => 160],
                'settings_payload' => $this->messageSettings('Имя уже известно'),
            ],
        ];
        $edgePayload = $this->edgePayload(null, 'Имя подтверждено');
        $edgePayload['field_condition'] = [
            'enabled' => true,
            'field_scope' => 'contact',
            'field_key' => 'first_name_source',
            'operator' => 'equals',
            'value' => 'contact_confirmed',
        ];
        $edges = [[
            'id' => null,
            'client_key' => 'tmp_edge',
            'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
            'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
            'condition_payload' => $edgePayload,
        ]];

        $saved = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, $edges))
            ->assertOk()
            ->assertJsonPath('builder.edges.0.condition_payload.field_condition.field_scope', 'contact')
            ->assertJsonPath('builder.edges.0.condition_payload.field_condition.field_key', 'first_name_source')
            ->assertJsonPath('builder.edges.0.condition_payload.field_condition.value', 'contact_confirmed')
            ->json();

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $saved['scenario']['draft_version_id'],
                'base_revision' => $saved['builder']['revision'],
            ])
            ->assertOk();

        $scenario->refresh()->load('publishedVersion');
        $sourceBlockId = (string) $saved['id_map']['blocks']['tmp_source'];

        $this->assertSame(
            'first_name_source',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$sourceBlockId.wait_reply_edges.0.field_condition.field_key"),
        );
    }

    public function test_publish_keeps_ai_first_name_analysis_outputs_in_runtime_snapshot(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['name' => 'Telegram AI']);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_ai_first_name_analysis',
            'name' => 'V3 AI First Name Analysis',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_start',
                'type' => 'state',
                'title' => 'Старт',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->startSettings('/start', [$channel->id]),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_ai',
                'type' => 'state',
                'title' => 'ИИ проверяет имя',
                'position' => ['x' => 480, 'y' => 160],
                'settings_payload' => $this->aiAnalysisSettings(),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_action',
                'type' => 'state',
                'title' => 'Записать имя',
                'position' => ['x' => 840, 'y' => 120],
                'settings_payload' => $this->actionSettings('first_name', 'first_name'),
            ],
        ];
        $waitPayload = $this->edgePayload(null, 'Ответ клиента');
        $aiPayload = $this->edgePayload('name_accepted', 'Имя найдено');
        $aiPayload['mode'] = 'ai_analysis';
        $aiPayload['match'] = ['type' => 'any_inbound', 'text' => ''];
        $edges = [
            [
                'id' => null,
                'client_key' => 'tmp_wait',
                'source' => ['block_id' => null, 'client_key' => 'tmp_start', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_ai'],
                'condition_payload' => $waitPayload,
            ],
            [
                'id' => null,
                'client_key' => 'tmp_ai_found',
                'source' => ['block_id' => null, 'client_key' => 'tmp_ai', 'output_id' => 'name_accepted'],
                'target' => ['block_id' => null, 'client_key' => 'tmp_action'],
                'condition_payload' => $aiPayload,
            ],
        ];

        $saved = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, $edges))
            ->assertOk()
            ->assertJsonPath('builder.blocks.1.settings_payload.modules.0.type', 'ai')
            ->assertJsonPath('builder.blocks.1.settings_payload.outputs.0.id', 'name_accepted')
            ->assertJsonPath('builder.edges.1.condition_payload.mode', 'ai_analysis')
            ->json();

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $saved['scenario']['draft_version_id'],
                'base_revision' => $saved['builder']['revision'],
            ])
            ->assertOk();

        $scenario->refresh()->load('publishedVersion');
        $aiBlockId = (string) $saved['id_map']['blocks']['tmp_ai'];
        $actionBlockId = (string) $saved['id_map']['blocks']['tmp_action'];

        $this->assertSame(
            'Определи, есть ли в ответе клиента имя.',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$aiBlockId.ai_analysis.prompt"),
        );
        $this->assertSame(
            'name_accepted',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$aiBlockId.ai_analysis.outputs.0.id"),
        );
        $this->assertSame(
            10,
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$aiBlockId.ai_analysis.outputs.1.delay_seconds"),
        );
        $this->assertSame(
            'first_name',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$aiBlockId.ai_analysis.extract_fields.0.key"),
        );
        $this->assertSame(
            'ai_analysis',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$aiBlockId.ai_analysis.outputs.0.edge.mode"),
        );
        $this->assertSame(
            '',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$actionBlockId.actions.0.source_block_id"),
        );
        $this->assertSame(
            'first_name',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$actionBlockId.actions.0.source_field_key"),
        );
        $this->assertSame(
            'first_name',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$actionBlockId.actions.0.target_field"),
        );
    }

    public function test_publish_keeps_check_data_action_outputs_in_runtime_snapshot(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['name' => 'Telegram Data Check']);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_check_data_action',
            'name' => 'V3 Check Data Action',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_start',
                'type' => 'state',
                'title' => 'Старт',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->startSettings('/start', [$channel->id]),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_check',
                'type' => 'state',
                'title' => 'Проверить имя',
                'position' => ['x' => 480, 'y' => 160],
                'settings_payload' => $this->checkDataActionSettings('first_name'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_found',
                'type' => 'state',
                'title' => 'Имя найдено',
                'position' => ['x' => 840, 'y' => 120],
                'settings_payload' => $this->messageSettings('Имя найдено'),
            ],
        ];
        $startPayload = $this->edgePayload(null, 'Дальше');
        $foundPayload = $this->edgePayload('data_found', 'Найдено');
        $foundPayload['mode'] = 'action_result';
        $foundPayload['match'] = ['type' => 'any_inbound', 'text' => ''];
        $edges = [
            [
                'id' => null,
                'client_key' => 'tmp_start_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_start', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_check'],
                'condition_payload' => $startPayload,
            ],
            [
                'id' => null,
                'client_key' => 'tmp_found_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_check', 'output_id' => 'data_found'],
                'target' => ['block_id' => null, 'client_key' => 'tmp_found'],
                'condition_payload' => $foundPayload,
            ],
        ];

        $saved = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, $edges))
            ->assertOk()
            ->assertJsonPath('builder.blocks.1.settings_payload.outputs.0.id', 'data_found')
            ->assertJsonPath('builder.blocks.1.settings_payload.outputs.1.id', 'data_not_found')
            ->json();

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $saved['scenario']['draft_version_id'],
                'base_revision' => $saved['builder']['revision'],
            ])
            ->assertOk();

        $scenario->refresh()->load('publishedVersion');
        $checkBlockId = (string) $saved['id_map']['blocks']['tmp_check'];

        $this->assertSame(
            'check_data',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$checkBlockId.actions.0.type"),
        );
        $this->assertSame(
            'first_name',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$checkBlockId.actions.0.target_variable_key"),
        );
        $this->assertSame(
            'action_result',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$checkBlockId.action_result_edges.0.mode"),
        );
        $this->assertSame(
            'data_found',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$checkBlockId.action_result_edges.0.from_output_id"),
        );
    }

    public function test_publish_keeps_edit_message_action_in_runtime_snapshot(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['name' => 'Telegram Edit Message']);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_edit_message_action',
            'name' => 'V3 Edit Message Action',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_start',
                'type' => 'state',
                'title' => 'Старт',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->startSettings('/start', [$channel->id]),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_edit',
                'type' => 'state',
                'title' => 'Убрать кнопки',
                'position' => ['x' => 480, 'y' => 160],
                'settings_payload' => $this->editMessageActionSettings(),
            ],
        ];
        $edges = [
            [
                'id' => null,
                'client_key' => 'tmp_start_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_start', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_edit'],
                'condition_payload' => $this->edgePayload(null, 'Дальше'),
            ],
        ];

        $saved = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, $edges))
            ->assertOk()
            ->assertJsonPath('builder.blocks.1.settings_payload.modules.0.payload.actions.0.type', 'edit_message')
            ->json();

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $saved['scenario']['draft_version_id'],
                'base_revision' => $saved['builder']['revision'],
            ])
            ->assertOk();

        $scenario->refresh()->load('publishedVersion');
        $editBlockId = (string) $saved['id_map']['blocks']['tmp_edit'];

        $this->assertSame(
            'edit_message',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$editBlockId.actions.0.type"),
        );
        $this->assertSame(
            'remove_buttons',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$editBlockId.actions.0.operation"),
        );
        $this->assertSame(
            'last_current_run_outbound_with_inline_buttons',
            data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$editBlockId.actions.0.target"),
        );
    }

    public function test_put_state_rejects_first_name_source_contact_input_capture(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_first_name_source_capture_rejected',
            'name' => 'V3 First Name Source Capture Rejected',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_source',
                'type' => 'state',
                'title' => 'Источник',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->messageSettings('Напишите ответ'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_target',
                'type' => 'state',
                'title' => 'Цель',
                'position' => ['x' => 480, 'y' => 160],
                'settings_payload' => $this->messageSettings('Дальше'),
            ],
        ];
        $edgePayload = $this->edgePayload(null, 'Дальше');
        $edgePayload['input_capture'] = [
            'enabled' => true,
            'field_scope' => 'contact',
            'field_key' => 'first_name_source',
            'data_type' => 'any_text',
        ];
        $edges = [[
            'id' => null,
            'client_key' => 'tmp_edge',
            'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
            'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
            'condition_payload' => $edgePayload,
        ]];

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, $edges))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['builder.edges.0.condition_payload.input_capture.field_key']);
    }

    public function test_put_state_normalizes_disabled_edge_input_capture_without_requiring_fields(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_disabled_capture',
            'name' => 'V3 Disabled Capture',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_source',
                'type' => 'state',
                'title' => 'Источник',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->messageSettings('Напишите код'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_target',
                'type' => 'state',
                'title' => 'Цель',
                'position' => ['x' => 480, 'y' => 160],
                'settings_payload' => $this->messageSettings('Принято'),
            ],
        ];
        $conditionPayload = $this->edgePayload(null, 'Код');
        $conditionPayload['input_capture'] = [
            'enabled' => false,
            'field_scope' => 'contact',
            'field_key' => '',
            'data_type' => 'unknown',
        ];

        $response = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertOk();

        $this->assertSame(false, $response->json('builder.edges.0.condition_payload.input_capture.enabled'));
        $this->assertSame('dialog', $response->json('builder.edges.0.condition_payload.input_capture.field_scope'));
        $this->assertSame('', $response->json('builder.edges.0.condition_payload.input_capture.field_key'));
        $this->assertSame('any_text', $response->json('builder.edges.0.condition_payload.input_capture.data_type'));
    }

    public function test_put_state_rejects_invalid_enabled_edge_input_capture_and_transition_limit(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_invalid_capture',
            'name' => 'V3 Invalid Capture',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_source',
                'type' => 'state',
                'title' => 'Источник',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->messageSettings('Напишите код'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_target',
                'type' => 'state',
                'title' => 'Цель',
                'position' => ['x' => 480, 'y' => 160],
                'settings_payload' => $this->messageSettings('Принято'),
            ],
        ];
        $conditionPayload = $this->edgePayload(null, 'Код');
        $conditionPayload['transition_limit'] = 100001;
        $conditionPayload['input_capture'] = [
            'enabled' => true,
            'field_scope' => 'contact',
            'field_key' => 'client_code',
            'data_type' => 'any_text',
        ];

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'builder.edges.0.condition_payload.transition_limit',
            ]);

        $conditionPayload['transition_limit'] = 0;

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'builder.edges.0.condition_payload.input_capture.field_key',
            ]);
    }

    public function test_put_state_accepts_contact_edge_input_capture(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_contact_capture',
            'name' => 'V3 Contact Capture',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_source',
                'type' => 'state',
                'title' => 'Источник',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->messageSettings('Напишите телефон'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_target',
                'type' => 'state',
                'title' => 'Цель',
                'position' => ['x' => 480, 'y' => 160],
                'settings_payload' => $this->messageSettings('Принято'),
            ],
        ];
        $conditionPayload = $this->edgePayload(null, 'Телефон');
        $conditionPayload['input_capture'] = [
            'enabled' => true,
            'field_scope' => 'contact',
            'field_key' => 'phone',
            'data_type' => 'phone',
        ];

        $response = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertOk();

        $this->assertSame(true, $response->json('builder.edges.0.condition_payload.input_capture.enabled'));
        $this->assertSame('contact', $response->json('builder.edges.0.condition_payload.input_capture.field_scope'));
        $this->assertSame('phone', $response->json('builder.edges.0.condition_payload.input_capture.field_key'));
        $this->assertSame('phone', $response->json('builder.edges.0.condition_payload.input_capture.data_type'));

        $conditionPayload['input_capture']['data_type'] = 'any_text';

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'builder.edges.0.condition_payload.input_capture.data_type',
            ]);
    }

    public function test_put_state_normalizes_automatic_edge_delay_settings(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_edge_delay',
            'name' => 'V3 Edge Delay',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_source',
                'type' => 'state',
                'title' => 'Источник',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->messageSettings('Источник'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_target',
                'type' => 'state',
                'title' => 'Цель',
                'position' => ['x' => 480, 'y' => 160],
                'settings_payload' => $this->messageSettings('Цель'),
            ],
        ];
        $conditionPayload = $this->edgePayload(null, 'Авто');
        $conditionPayload['mode'] = 'automatic';
        $conditionPayload['delay'] = [
            'value' => 30,
            'unit' => 'min',
            'cancel_if_left_source_block' => false,
        ];

        $response = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertOk();

        $this->assertSame('relative', $response->json('builder.edges.0.condition_payload.delay.type'));
        $this->assertSame(30, $response->json('builder.edges.0.condition_payload.delay.value'));
        $this->assertSame('min', $response->json('builder.edges.0.condition_payload.delay.unit'));
        $this->assertFalse($response->json('builder.edges.0.condition_payload.delay.cancel_if_left_source_block'));

        $saved = $response->json();
        $saved['builder']['edges'][0]['condition_payload']['delay'] = [
            'value' => 0,
            'unit' => 'min',
            'cancel_if_left_source_block' => true,
        ];

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($saved, $saved['builder']['blocks'], $saved['builder']['edges']))
            ->assertOk()
            ->assertJsonPath('builder.edges.0.condition_payload.delay.type', 'immediate')
            ->assertJsonPath('builder.edges.0.condition_payload.delay.value', 0)
            ->assertJsonPath('builder.edges.0.condition_payload.delay.unit', 'sec');
    }

    public function test_put_state_rejects_invalid_automatic_edge_delay(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_invalid_edge_delay',
            'name' => 'V3 Invalid Edge Delay',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_source',
                'type' => 'state',
                'title' => 'Источник',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->messageSettings('Источник'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_target',
                'type' => 'state',
                'title' => 'Цель',
                'position' => ['x' => 480, 'y' => 160],
                'settings_payload' => $this->messageSettings('Цель'),
            ],
        ];
        $conditionPayload = $this->edgePayload(null, 'Авто');
        $conditionPayload['mode'] = 'automatic';
        $conditionPayload['delay'] = [
            'value' => 100001,
            'unit' => 'sec',
            'cancel_if_left_source_block' => true,
        ];

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'builder.edges.0.condition_payload.delay.value',
            ]);

        $conditionPayload['delay'] = [
            'value' => 1,
            'unit' => 'hour',
            'cancel_if_left_source_block' => true,
        ];

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'builder.edges.0.condition_payload.delay.unit',
            ]);
    }

    public function test_put_state_normalizes_scheduled_automatic_edge_delay_settings(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_scheduled_edge_delay',
            'name' => 'V3 Scheduled Edge Delay',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $scheduledAt = CarbonImmutable::now()->addDay()->startOfMinute();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_source',
                'type' => 'state',
                'title' => 'Источник',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->messageSettings('Источник'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_target',
                'type' => 'state',
                'title' => 'Цель',
                'position' => ['x' => 480, 'y' => 160],
                'settings_payload' => $this->messageSettings('Цель'),
            ],
        ];
        $conditionPayload = $this->edgePayload(null, 'Авто');
        $conditionPayload['mode'] = 'automatic';
        $conditionPayload['delay'] = [
            'type' => 'scheduled',
            'scheduled_at' => $scheduledAt->toIso8601String(),
            'cancel_if_left_source_block' => false,
        ];

        $response = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertOk();

        $this->assertSame('scheduled', $response->json('builder.edges.0.condition_payload.delay.type'));
        $this->assertSame(0, $response->json('builder.edges.0.condition_payload.delay.value'));
        $this->assertSame('sec', $response->json('builder.edges.0.condition_payload.delay.unit'));
        $this->assertTrue(CarbonImmutable::parse($response->json('builder.edges.0.condition_payload.delay.scheduled_at'))->equalTo($scheduledAt));
        $this->assertFalse($response->json('builder.edges.0.condition_payload.delay.cancel_if_left_source_block'));
    }

    public function test_put_state_rejects_invalid_scheduled_automatic_edge_delay(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_invalid_scheduled_edge_delay',
            'name' => 'V3 Invalid Scheduled Edge Delay',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = [
            [
                'id' => null,
                'client_key' => 'tmp_source',
                'type' => 'state',
                'title' => 'Источник',
                'position' => ['x' => 120, 'y' => 160],
                'settings_payload' => $this->messageSettings('Источник'),
            ],
            [
                'id' => null,
                'client_key' => 'tmp_target',
                'type' => 'state',
                'title' => 'Цель',
                'position' => ['x' => 480, 'y' => 160],
                'settings_payload' => $this->messageSettings('Цель'),
            ],
        ];
        $conditionPayload = $this->edgePayload(null, 'Авто');
        $conditionPayload['mode'] = 'automatic';
        $conditionPayload['delay'] = [
            'type' => 'scheduled',
            'scheduled_at' => 'not-a-date',
            'cancel_if_left_source_block' => true,
        ];

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks, [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_source', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'builder.edges.0.condition_payload.delay.scheduled_at',
            ]);
    }

    public function test_publish_includes_relative_automatic_delay_in_runtime_snapshot(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_publish_delayed_edge',
            'name' => 'V3 Publish Delayed Edge',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $conditionPayload = $this->edgePayload(null, 'Авто');
        $conditionPayload['mode'] = 'automatic';
        $conditionPayload['delay'] = [
            'value' => 5,
            'unit' => 'min',
            'cancel_if_left_source_block' => true,
        ];
        $savedState = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_start',
                    'type' => 'state',
                    'title' => 'Старт',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->startSettings('/start', [(int) $channel->id]),
                ],
                [
                    'id' => null,
                    'client_key' => 'tmp_target',
                    'type' => 'state',
                    'title' => 'Цель',
                    'position' => ['x' => 460, 'y' => 64],
                    'settings_payload' => $this->messageSettings('Цель'),
                ],
            ], [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_start', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertOk()
            ->json();

        $publishedState = $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $savedState['scenario']['draft_version_id'],
                'base_revision' => $savedState['builder']['revision'],
            ])
            ->assertOk()
            ->json();

        $scenario->refresh()->load('publishedVersion');
        $startBlockId = (string) $savedState['id_map']['blocks']['tmp_start'];
        $runtimeDelay = data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$startBlockId.automatic_edges.0.delay");

        $this->assertSame(1, $publishedState['published']['version_number']);
        $this->assertSame('relative', $runtimeDelay['type'] ?? null);
        $this->assertSame(5, $runtimeDelay['value'] ?? null);
        $this->assertSame('min', $runtimeDelay['unit'] ?? null);
        $this->assertTrue($runtimeDelay['cancel_if_left_source_block'] ?? false);
    }

    public function test_publish_includes_scheduled_automatic_delay_in_runtime_snapshot(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_publish_scheduled_edge',
            'name' => 'V3 Publish Scheduled Edge',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $scheduledAt = CarbonImmutable::now()->addDay()->startOfMinute();
        $conditionPayload = $this->edgePayload(null, 'Авто');
        $conditionPayload['mode'] = 'automatic';
        $conditionPayload['delay'] = [
            'type' => 'scheduled',
            'scheduled_at' => $scheduledAt->toIso8601String(),
            'cancel_if_left_source_block' => true,
        ];
        $savedState = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_start',
                    'type' => 'state',
                    'title' => 'Старт',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->startSettings('/start', [(int) $channel->id]),
                ],
                [
                    'id' => null,
                    'client_key' => 'tmp_target',
                    'type' => 'state',
                    'title' => 'Цель',
                    'position' => ['x' => 460, 'y' => 64],
                    'settings_payload' => $this->messageSettings('Цель'),
                ],
            ], [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_start', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertOk()
            ->json();

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $savedState['scenario']['draft_version_id'],
                'base_revision' => $savedState['builder']['revision'],
            ])
            ->assertOk();

        $scenario->refresh()->load('publishedVersion');
        $startBlockId = (string) $savedState['id_map']['blocks']['tmp_start'];
        $runtimeDelay = data_get($scenario->publishedVersion?->schema_payload, "builder_v3_runtime.blocks.$startBlockId.automatic_edges.0.delay");

        $this->assertSame('scheduled', $runtimeDelay['type'] ?? null);
        $this->assertSame(0, $runtimeDelay['value'] ?? null);
        $this->assertSame('sec', $runtimeDelay['unit'] ?? null);
        $this->assertTrue(CarbonImmutable::parse($runtimeDelay['scheduled_at'] ?? null)->equalTo($scheduledAt));
        $this->assertTrue($runtimeDelay['cancel_if_left_source_block'] ?? false);
    }

    public function test_publish_rejects_past_scheduled_automatic_delay(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_publish_past_scheduled_edge',
            'name' => 'V3 Publish Past Scheduled Edge',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $conditionPayload = $this->edgePayload(null, 'Авто');
        $conditionPayload['mode'] = 'automatic';
        $conditionPayload['delay'] = [
            'type' => 'scheduled',
            'scheduled_at' => CarbonImmutable::now()->subMinute()->toIso8601String(),
            'cancel_if_left_source_block' => true,
        ];
        $savedState = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_start',
                    'type' => 'state',
                    'title' => 'Старт',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->startSettings('/start', [(int) $channel->id]),
                ],
                [
                    'id' => null,
                    'client_key' => 'tmp_target',
                    'type' => 'state',
                    'title' => 'Цель',
                    'position' => ['x' => 460, 'y' => 64],
                    'settings_payload' => $this->messageSettings('Цель'),
                ],
            ], [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_start', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertOk()
            ->json();

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $savedState['scenario']['draft_version_id'],
                'base_revision' => $savedState['builder']['revision'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['builder.edges']);
    }

    public function test_get_state_returns_delayed_transition_diagnostics_for_edge(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_edge_diagnostics',
            'name' => 'V3 Edge Diagnostics',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $conditionPayload = $this->edgePayload(null, 'Авто');
        $conditionPayload['mode'] = 'automatic';
        $conditionPayload['delay'] = [
            'value' => 15,
            'unit' => 'sec',
            'cancel_if_left_source_block' => true,
        ];
        $savedState = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_start',
                    'type' => 'state',
                    'title' => 'Старт',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->startSettings('/start', [(int) $channel->id]),
                ],
                [
                    'id' => null,
                    'client_key' => 'tmp_target',
                    'type' => 'state',
                    'title' => 'Цель',
                    'position' => ['x' => 460, 'y' => 64],
                    'settings_payload' => $this->messageSettings('Цель'),
                ],
            ], [[
                'id' => null,
                'client_key' => 'tmp_edge',
                'source' => ['block_id' => null, 'client_key' => 'tmp_start', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'tmp_target'],
                'condition_payload' => $conditionPayload,
            ]]))
            ->assertOk()
            ->json();

        $publishedState = $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $savedState['scenario']['draft_version_id'],
                'base_revision' => $savedState['builder']['revision'],
            ])
            ->assertOk()
            ->json();

        $scenario->refresh()->load('publishedVersion');
        $publishedVersion = $scenario->publishedVersion;
        $publishedEdge = $publishedVersion?->builderEdges()->firstOrFail();
        $edgeKey = (string) data_get($publishedState, 'builder.edges.0.condition_payload.edge_key');
        $dialog = Dialog::factory()->create(['channel_id' => $channel->id]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
        ]);
        $run = ScenarioRun::query()->create([
            'scenario_code' => $scenario->code,
            'dialog_id' => $dialog->id,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => (string) $publishedEdge->from_scenario_builder_block_id,
            'state_payload' => [
                'v3' => [
                    'published_version_id' => $publishedVersion?->id,
                    'current_block_id' => (string) $publishedEdge->from_scenario_builder_block_id,
                ],
            ],
            'started_at' => now(),
        ]);
        $transition = ScenarioV3ScheduledTransition::query()->create([
            'scenario_run_id' => $run->id,
            'dialog_id' => $dialog->id,
            'inbound_message_id' => $message->id,
            'scenario_code' => $scenario->code,
            'published_version_id' => $publishedVersion?->id,
            'edge_key' => $edgeKey,
            'edge_id' => (string) $publishedEdge->id,
            'source_block_id' => (string) $publishedEdge->from_scenario_builder_block_id,
            'target_block_id' => (string) $publishedEdge->to_scenario_builder_block_id,
            'delay_payload' => ['type' => 'relative', 'value' => 15, 'unit' => 'sec', 'cancel_if_left_source_block' => true],
            'scheduled_for' => now()->addSeconds(15),
            'status' => ScenarioV3ScheduledTransition::STATUS_SCHEDULED,
        ]);

        $this->actingAs($admin)
            ->getJson($this->stateUrl($scenario))
            ->assertOk()
            ->assertJsonPath('builder.diagnostics.scheduled_transitions.0.id', $transition->id)
            ->assertJsonPath('builder.diagnostics.scheduled_transitions.0.status_label', 'Запланирован')
            ->assertJsonPath('builder.diagnostics.scheduled_transitions.0.edge_key', $edgeKey)
            ->assertJsonPath('builder.diagnostics.scheduled_transitions.0.edge_id', (string) $publishedEdge->id)
            ->assertJsonPath('builder.edges.0.diagnostics.scheduled_transitions.0.id', $transition->id)
            ->assertJsonPath('builder.edges.0.diagnostics.scheduled_transitions.0.status', ScenarioV3ScheduledTransition::STATUS_SCHEDULED)
            ->assertJsonPath('builder.edges.0.diagnostics.scheduled_transitions.0.status_label', 'Запланирован')
            ->assertJsonPath('builder.edges.0.diagnostics.scheduled_transitions.0.dialog_id', $dialog->id);
    }

    public function test_put_state_syncs_start_condition_channels_and_conditions(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_save_start',
            'name' => 'V3 Save Start',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $payload = $this->payloadFromState($state, [
            [
                'id' => null,
                'client_key' => 'tmp_start',
                'type' => 'state',
                'title' => 'Старт',
                'position' => ['x' => 80, 'y' => 96],
                'settings_payload' => $this->startSettings('/start', [(int) $channel->id]),
            ],
        ]);

        $response = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $payload)
            ->assertOk();

        $blockId = $response->json('id_map.blocks.tmp_start');

        $this->assertDatabaseHas('scenario_builder_block_channels', [
            'scenario_builder_block_id' => $blockId,
            'channel_id' => $channel->id,
        ]);
        $this->assertDatabaseHas('scenario_builder_conditions', [
            'scenario_builder_block_id' => $blockId,
            'type' => ScenarioBuilderCondition::TYPE_MESSAGE_PARAMETER,
            'match_operator' => 'strict',
            'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
            'value' => '/start',
        ]);
        $this->assertDatabaseHas('scenario_builder_blocks', [
            'id' => $blockId,
            'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
        ]);
    }

    public function test_put_state_uses_visible_start_command_instead_of_hidden_values(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_start_command_canonical',
            'name' => 'V3 Start Command Canonical',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $settings = $this->startSettings('123', [(int) $channel->id]);
        $settings['modules'][0]['payload']['values'] = ['12', 'старт', '/start'];

        $payload = $this->payloadFromState($state, [
            [
                'id' => null,
                'client_key' => 'tmp_start',
                'type' => 'state',
                'title' => 'Старт',
                'position' => ['x' => 80, 'y' => 96],
                'settings_payload' => $settings,
            ],
        ]);

        $response = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $payload)
            ->assertOk();

        $blockId = $response->json('id_map.blocks.tmp_start');
        $block = ScenarioBuilderBlock::query()->findOrFail($blockId);

        $this->assertSame([], data_get($block->settings_payload, 'modules.0.payload.values'));
        $this->assertSame(['123'], ScenarioBuilderCondition::query()
            ->where('scenario_builder_block_id', $blockId)
            ->orderBy('id')
            ->pluck('value')
            ->all());
        $this->assertSame(['123'], $response->json('builder.blocks.0.settings_payload.modules.0.payload.values'));
    }

    public function test_put_state_rejects_stale_revision(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_stale',
            'name' => 'V3 Stale',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $draft = $scenario->draftVersion()->firstOrFail();
        $changedAt = CarbonImmutable::parse('2099-01-01 00:00:00 UTC');
        $draft->forceFill([
            'schema_payload' => [
                'builder_v3' => [
                    'revision' => 'v3:'.$changedAt->format('Y-m-d\TH:i:s.u\Z'),
                    'visible_scope' => ['block_ids' => [], 'edge_ids' => []],
                ],
            ],
        ])->save();

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, []))
            ->assertStatus(409);
    }

    public function test_put_state_rejects_when_legacy_builder_row_changed_after_get(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_legacy_stale',
            'name' => 'V3 Legacy Stale',
        ]);
        $draft = $scenario->draftVersion()->firstOrFail();
        $oldTimestamp = CarbonImmutable::parse('2026-05-16 10:00:00');
        $newTimestamp = CarbonImmutable::parse('2026-05-16 10:05:00');
        $block = ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $draft->id,
            'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
            'title' => 'Старт',
            'position_x' => 64,
            'position_y' => 64,
            'settings_payload' => $this->startSettings('/start', []),
            'created_at' => $oldTimestamp,
            'updated_at' => $oldTimestamp,
        ]);
        $draft->forceFill([
            'schema_payload' => [
                'builder_v3' => [
                    'revision' => 'v3:2026-05-16T10:00:00.000000Z',
                    'visible_scope' => ['block_ids' => [$block->id], 'edge_ids' => []],
                ],
            ],
        ])->save();
        DB::table('scenario_versions')
            ->where('id', $draft->id)
            ->update(['updated_at' => $oldTimestamp]);

        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();

        DB::table('scenario_builder_blocks')
            ->where('id', $block->id)
            ->update([
                'title' => 'Старт изменён старым конструктором',
                'updated_at' => $newTimestamp,
            ]);

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $state['builder']['blocks']))
            ->assertStatus(409);
    }

    public function test_put_state_rejects_visible_scope_tampering(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_visible_tamper',
            'name' => 'V3 Visible Tamper',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $payload = $this->payloadFromState($state, []);
        $payload['builder']['visible_scope']['block_ids'] = [999999];

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $payload)
            ->assertUnprocessable();
    }

    public function test_put_state_accepts_current_visible_scope_after_legacy_block_was_added(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_scope_recomputed',
            'name' => 'V3 Scope Recomputed',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $firstSave = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_block_1',
                    'type' => 'state',
                    'title' => 'Первый блок',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->messageSettings('Первое сообщение'),
                ],
            ]))
            ->assertOk()
            ->json();
        $draft = $scenario->fresh()->draftVersion()->firstOrFail();
        $legacyBlock = ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $draft->id,
            'type' => 'state',
            'title' => 'Блок из старого конструктора',
            'position_x' => 160,
            'position_y' => 160,
            'settings_payload' => $this->messageSettings('Legacy message'),
        ]);

        $this->assertSame([$firstSave['builder']['blocks'][0]['id']], $firstSave['builder']['visible_scope']['block_ids']);

        $currentState = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();

        $this->assertContains($legacyBlock->id, $currentState['builder']['visible_scope']['block_ids']);

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($currentState, $currentState['builder']['blocks']))
            ->assertOk();
    }

    public function test_put_state_rejects_unknown_module_type(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_unknown_module',
            'name' => 'V3 Unknown Module',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $settings = $this->messageSettings('Привет');
        $settings['modules'][0]['type'] = 'unknown_module';
        $payload = $this->payloadFromState($state, [
            [
                'id' => null,
                'client_key' => 'tmp_block_1',
                'type' => 'state',
                'title' => 'Блок',
                'position' => ['x' => 64, 'y' => 64],
                'settings_payload' => $settings,
            ],
        ]);

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $payload)
            ->assertUnprocessable();
    }

    public function test_put_state_rejects_regex_start_condition_match(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['name' => 'Telegram Test']);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_regex_start_disabled',
            'name' => 'V3 Regex Start Disabled',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $settings = $this->startSettings('^start.*$', [(int) $channel->id]);
        $settings['modules'][0]['payload']['match'] = 'regex';

        $payload = $this->payloadFromState($state, [
            [
                'id' => null,
                'client_key' => 'tmp_start',
                'type' => 'state',
                'title' => 'Старт',
                'position' => ['x' => 64, 'y' => 64],
                'settings_payload' => $settings,
            ],
        ]);

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'builder.blocks.0.settings_payload.modules.0.payload.match',
            ]);
    }

    public function test_put_state_rejects_unknown_channel_id(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_unknown_channel',
            'name' => 'V3 Unknown Channel',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $payload = $this->payloadFromState($state, [
            [
                'id' => null,
                'client_key' => 'tmp_start',
                'type' => 'state',
                'title' => 'Старт',
                'position' => ['x' => 64, 'y' => 64],
                'settings_payload' => $this->startSettings('/start', [999999]),
            ],
        ]);

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $payload)
            ->assertUnprocessable();
    }

    public function test_put_state_rejects_missing_edge_endpoint(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_bad_edge',
            'name' => 'V3 Bad Edge',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $payload = $this->payloadFromState($state, [
            [
                'id' => null,
                'client_key' => 'tmp_block_1',
                'type' => 'state',
                'title' => 'Блок',
                'position' => ['x' => 64, 'y' => 64],
                'settings_payload' => $this->messageSettings('Привет'),
            ],
        ], [
            [
                'id' => null,
                'client_key' => 'tmp_edge_1',
                'source' => ['block_id' => null, 'client_key' => 'tmp_block_1', 'output_id' => null],
                'target' => ['block_id' => null, 'client_key' => 'missing_block'],
                'condition_payload' => [],
            ],
        ]);

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $payload)
            ->assertUnprocessable();
    }

    public function test_put_state_preserves_existing_start_condition_db_type(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_preserve_type',
            'name' => 'V3 Preserve Type',
        ]);
        $draft = $scenario->draftVersion()->firstOrFail();
        $block = ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $draft->id,
            'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
            'title' => 'Старт',
            'position_x' => 64,
            'position_y' => 64,
            'settings_payload' => $this->startSettings('/start', []),
        ]);
        ScenarioBuilderCondition::query()->create([
            'scenario_builder_block_id' => $block->id,
            'type' => ScenarioBuilderCondition::TYPE_MESSAGE_PARAMETER,
            'match_operator' => 'strict',
            'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
            'value' => '/start',
            'sort_order' => 1,
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $state['builder']['blocks']))
            ->assertOk();

        $this->assertDatabaseHas('scenario_builder_blocks', [
            'id' => $block->id,
            'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
        ]);
    }

    public function test_put_state_promotes_existing_state_block_to_start_condition_when_module_added(): void
    {
        $admin = $this->adminUser();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_promote_type',
            'name' => 'V3 Promote Type',
        ]);
        $draft = $scenario->draftVersion()->firstOrFail();
        $block = ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $draft->id,
            'type' => 'state',
            'title' => 'Обычный блок',
            'position_x' => 64,
            'position_y' => 64,
            'settings_payload' => $this->messageSettings('Привет'),
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = $state['builder']['blocks'];
        $blocks[0]['settings_payload'] = $this->startSettings('/start', []);

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks))
            ->assertOk();

        $this->assertDatabaseHas('scenario_builder_blocks', [
            'id' => $block->id,
            'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
        ]);
        $this->assertDatabaseHas('scenario_builder_conditions', [
            'scenario_builder_block_id' => $block->id,
            'value' => '/start',
        ]);
    }

    public function test_put_state_demotes_existing_start_condition_to_state_when_module_removed(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create();
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_demote_type',
            'name' => 'V3 Demote Type',
        ]);
        $draft = $scenario->draftVersion()->firstOrFail();
        $block = ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $draft->id,
            'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
            'title' => 'Старт',
            'position_x' => 64,
            'position_y' => 64,
            'settings_payload' => $this->startSettings('/start', [(int) $channel->id]),
        ]);
        $block->channels()->sync([(int) $channel->id]);
        ScenarioBuilderCondition::query()->create([
            'scenario_builder_block_id' => $block->id,
            'type' => ScenarioBuilderCondition::TYPE_MESSAGE_PARAMETER,
            'match_operator' => 'strict',
            'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
            'value' => '/start',
            'sort_order' => 1,
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $blocks = $state['builder']['blocks'];
        $blocks[0]['settings_payload'] = $this->messageSettings('Теперь это сообщение');

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, $blocks))
            ->assertOk();

        $this->assertDatabaseHas('scenario_builder_blocks', [
            'id' => $block->id,
            'type' => 'state',
        ]);
        $this->assertDatabaseMissing('scenario_builder_conditions', [
            'scenario_builder_block_id' => $block->id,
            'value' => '/start',
        ]);
        $this->assertDatabaseMissing('scenario_builder_block_channels', [
            'scenario_builder_block_id' => $block->id,
            'channel_id' => $channel->id,
        ]);
    }

    public function test_put_state_does_not_touch_published_version_rows(): void
    {
        $admin = $this->adminUser();
        $scenario = Scenario::query()->create([
            'code' => 'v3_published_guard',
            'name' => 'V3 Published Guard',
            'is_active' => true,
            'is_archived' => false,
        ]);
        $published = ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
            'schema_payload' => ['version' => 1, 'triggers' => [], 'blocks' => ['welcome' => ['text' => 'Published']]],
        ]);
        $draft = ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 2,
            'status' => ScenarioVersion::STATUS_DRAFT,
            'schema_payload' => [],
        ]);
        $publishedBlock = ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $published->id,
            'type' => 'state',
            'title' => 'Published block',
            'position_x' => 64,
            'position_y' => 64,
            'settings_payload' => $this->messageSettings('Published'),
        ]);
        $draftBlock = ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $draft->id,
            'type' => 'state',
            'title' => 'Draft block',
            'position_x' => 64,
            'position_y' => 64,
            'settings_payload' => $this->messageSettings('Draft'),
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();

        $this->assertSame([$draftBlock->id], $state['builder']['visible_scope']['block_ids']);

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, []))
            ->assertOk();

        $this->assertDatabaseHas('scenario_builder_blocks', [
            'id' => $publishedBlock->id,
            'scenario_version_id' => $published->id,
            'title' => 'Published block',
        ]);
        $this->assertDatabaseMissing('scenario_builder_blocks', [
            'id' => $draftBlock->id,
            'scenario_version_id' => $draft->id,
        ]);
    }

    public function test_publish_v3_graph_compiles_runtime_snapshot_and_creates_next_draft(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_publish',
            'name' => 'V3 Publish',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $catalogSettings = $this->messageSettings('Вот каталог');
        $catalogSettings['kind'] = 'non_state';
        $savedState = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_start',
                    'type' => 'state',
                    'title' => 'Старт',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->startMessageButtonsSettings(
                        '/start',
                        [(int) $channel->id],
                        'Выберите действие',
                        'Получить каталог',
                        'has_phone',
                    ),
                ],
                [
                    'id' => null,
                    'client_key' => 'tmp_catalog',
                    'type' => 'state',
                    'title' => 'Каталог',
                    'position' => ['x' => 460, 'y' => 64],
                    'settings_payload' => $catalogSettings,
                ],
            ], [
                [
                    'id' => null,
                    'client_key' => 'tmp_edge_catalog',
                    'source' => ['block_id' => null, 'client_key' => 'tmp_start', 'output_id' => 'btn_catalog'],
                    'target' => ['block_id' => null, 'client_key' => 'tmp_catalog'],
                    'condition_payload' => $this->edgePayload('btn_catalog', 'Получить каталог'),
                ],
            ]))
            ->assertOk()
            ->json();

        $publishedResponse = $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $savedState['scenario']['draft_version_id'],
                'base_revision' => $savedState['builder']['revision'],
            ])
            ->assertOk()
            ->assertJsonPath('published.version_number', 1)
            ->assertJsonPath('scenario.draft_version_number', 2)
            ->assertJsonPath('scenario.published_version_number', 1)
            ->json();

        $scenario->refresh()->load(['draftVersion', 'publishedVersion']);
        $published = $scenario->publishedVersion;
        $draft = $scenario->draftVersion;

        $this->assertInstanceOf(ScenarioVersion::class, $published);
        $this->assertInstanceOf(ScenarioVersion::class, $draft);
        $this->assertSame($publishedResponse['published']['version_id'], $published->id);
        $this->assertSame(3, $published->schema_payload['version'] ?? null);

        $runtime = $published->schema_payload['builder_v3_runtime'] ?? [];
        $startBlockId = (string) $savedState['id_map']['blocks']['tmp_start'];
        $catalogBlockId = (string) $savedState['id_map']['blocks']['tmp_catalog'];

        $this->assertSame(3, $runtime['schema_version'] ?? null);
        $this->assertSame($startBlockId, $runtime['entrypoints'][0]['block_id'] ?? null);
        $this->assertSame([$channel->id], $runtime['entrypoints'][0]['channel_ids'] ?? null);
        $this->assertSame(['/start'], $runtime['entrypoints'][0]['values'] ?? null);
        $this->assertSame('has_phone', $runtime['entrypoints'][0]['contact_phone_condition'] ?? null);
        $this->assertSame('', $runtime['entrypoints'][0]['dialog_phone_condition'] ?? null);
        $this->assertSame('Выберите действие', data_get($runtime, "blocks.$startBlockId.message.text"));
        $this->assertSame('state', data_get($runtime, "blocks.$startBlockId.kind"));
        $this->assertSame('non_state', data_get($runtime, "blocks.$catalogBlockId.kind"));
        $this->assertSame('text', data_get($runtime, "blocks.$startBlockId.buttons.rows.0.0.type"));
        $this->assertSame(
            $catalogBlockId,
            data_get($runtime, "blocks.$startBlockId.buttons.rows.0.0.target_block_id"),
        );

        $this->assertDatabaseHas('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);
        $this->assertSame(2, $draft->builderBlocks()->count());
        $this->assertSame(1, $draft->builderEdges()->count());

        $publishedBlocks = $published->builderBlocks()->orderBy('id')->get();
        $draftBlocks = $draft->builderBlocks()->orderBy('id')->get();
        $publishedBlockIds = $publishedBlocks->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $draftBlockIds = $draftBlocks->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $publishedCardIds = $publishedBlocks
            ->map(fn (ScenarioBuilderBlock $block): string => (string) data_get($block->settings_payload, 'ui.card_id'))
            ->all();
        $draftCardIds = $draftBlocks
            ->map(fn (ScenarioBuilderBlock $block): string => (string) data_get($block->settings_payload, 'ui.card_id'))
            ->all();

        $this->assertNotSame($publishedBlockIds, $draftBlockIds);
        $this->assertSame($publishedCardIds, $draftCardIds);
        $this->assertSame($draftCardIds[0], $publishedResponse['builder']['blocks'][0]['display_id'] ?? null);
    }

    public function test_publish_v3_graph_rolls_back_when_selected_channel_becomes_unavailable(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_publish_channel_rollback',
            'name' => 'V3 Publish Channel Rollback',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $savedState = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_start',
                    'type' => 'state',
                    'title' => 'Старт',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->startMessageButtonsSettings('/start', [(int) $channel->id], 'Привет', 'Далее'),
                ],
            ]))
            ->assertOk()
            ->json();

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $savedState['scenario']['draft_version_id'],
                'base_revision' => $savedState['builder']['revision'],
            ])
            ->assertOk();

        $scenario->refresh()->load(['publishedVersion', 'draftVersion']);
        $publishedVersionId = $scenario->publishedVersion?->id;
        $draftVersionId = $scenario->draftVersion?->id;
        $secondState = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();

        $channel->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $secondState['scenario']['draft_version_id'],
                'base_revision' => $secondState['builder']['revision'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['builder.start_condition.channels']);

        $scenario->refresh()->load(['publishedVersion', 'draftVersion']);

        $this->assertSame($publishedVersionId, $scenario->publishedVersion?->id);
        $this->assertSame($draftVersionId, $scenario->draftVersion?->id);
        $this->assertSame(2, $scenario->versions()->count());
        $this->assertDatabaseHas('scenario_versions', [
            'id' => $publishedVersionId,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
        ]);
        $this->assertDatabaseHas('scenario_versions', [
            'id' => $draftVersionId,
            'status' => ScenarioVersion::STATUS_DRAFT,
        ]);
        $this->assertDatabaseHas('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);
    }

    public function test_publish_v3_graph_warns_about_pending_scheduled_transitions(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_publish_pending_warning',
            'name' => 'V3 Publish Pending Warning',
        ]);
        $savedState = $this->saveSingleStartBlockState($admin, $scenario, $channel);

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $savedState['scenario']['draft_version_id'],
                'base_revision' => $savedState['builder']['revision'],
            ])
            ->assertOk();

        $scenario->refresh()->load(['publishedVersion', 'draftVersion']);
        $publishedVersionId = $scenario->publishedVersion?->id;
        $draftVersionId = $scenario->draftVersion?->id;
        $transition = $this->createScheduledTransitionForScenario($scenario, $channel, $scenario->publishedVersion);
        $secondState = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $secondState['scenario']['draft_version_id'],
                'base_revision' => $secondState['builder']['revision'],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'scheduled_transitions_pending')
            ->assertJsonPath('warning.scheduled_transitions.count', 1)
            ->assertJsonPath('warning.scheduled_transitions.items.0.id', $transition->id);

        $scenario->refresh()->load(['publishedVersion', 'draftVersion']);
        $transition->refresh();

        $this->assertSame($publishedVersionId, $scenario->publishedVersion?->id);
        $this->assertSame($draftVersionId, $scenario->draftVersion?->id);
        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_SCHEDULED, $transition->status);
    }

    public function test_publish_v3_graph_can_keep_pending_scheduled_transitions(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_publish_pending_keep',
            'name' => 'V3 Publish Pending Keep',
        ]);
        $savedState = $this->saveSingleStartBlockState($admin, $scenario, $channel);

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $savedState['scenario']['draft_version_id'],
                'base_revision' => $savedState['builder']['revision'],
            ])
            ->assertOk();

        $scenario->refresh()->load(['publishedVersion']);
        $transition = $this->createScheduledTransitionForScenario($scenario, $channel, $scenario->publishedVersion);
        $secondState = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $secondState['scenario']['draft_version_id'],
                'base_revision' => $secondState['builder']['revision'],
                'scheduled_transition_policy' => 'keep',
            ])
            ->assertOk()
            ->assertJsonPath('published.cancelled_scheduled_transitions', 0);

        $transition->refresh();

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_SCHEDULED, $transition->status);
    }

    public function test_publish_v3_graph_can_cancel_pending_scheduled_transitions(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_publish_pending_cancel',
            'name' => 'V3 Publish Pending Cancel',
        ]);
        $savedState = $this->saveSingleStartBlockState($admin, $scenario, $channel);

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $savedState['scenario']['draft_version_id'],
                'base_revision' => $savedState['builder']['revision'],
            ])
            ->assertOk();

        $scenario->refresh()->load(['publishedVersion']);
        $transition = $this->createScheduledTransitionForScenario($scenario, $channel, $scenario->publishedVersion);
        $secondState = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $secondState['scenario']['draft_version_id'],
                'base_revision' => $secondState['builder']['revision'],
                'scheduled_transition_policy' => 'cancel',
            ])
            ->assertOk()
            ->assertJsonPath('published.cancelled_scheduled_transitions', 1);

        $transition->refresh();

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_CANCELLED, $transition->status);
        $this->assertSame('Отменено при публикации новой версии сценария.', $transition->error_message);
    }

    public function test_put_state_rejects_empty_button_text(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_save_empty_button',
            'name' => 'V3 Save Empty Button',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_start',
                    'type' => 'state',
                    'title' => 'Старт',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->startMessageButtonsSettings('/start', [(int) $channel->id], 'Выберите действие', ''),
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'builder.blocks.0.settings_payload.modules.2.payload.rows.0.0.text',
            ]);
    }

    public function test_put_state_preserves_request_phone_button_type(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_save_request_phone_button',
            'name' => 'V3 Save Request Phone Button',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();

        $response = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_start',
                    'type' => 'state',
                    'title' => 'Старт',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->startMessageButtonsSettings(
                        '/start',
                        [(int) $channel->id],
                        'Поделитесь телефоном',
                        'Поделиться номером телефона',
                        '',
                        'request_phone',
                    ),
                ],
            ]))
            ->assertOk()
            ->json();

        $buttonsModule = collect($response['builder']['blocks'][0]['settings_payload']['modules'] ?? [])
            ->firstWhere('type', 'buttons');

        $this->assertSame('request_phone', data_get($buttonsModule, 'payload.rows.0.0.type'));
        $this->assertSame('request_phone', data_get($response, 'builder.blocks.0.settings_payload.outputs.0.button_type'));
    }

    public function test_put_state_preserves_link_button_type_and_url(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_save_link_button',
            'name' => 'V3 Save Link Button',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();

        $response = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_start',
                    'type' => 'state',
                    'title' => 'Старт',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->startMessageButtonsSettings(
                        '/start',
                        [(int) $channel->id],
                        'Откройте ссылку',
                        'Открыть сайт',
                        '',
                        'link',
                        'https://example.com/form',
                    ),
                ],
            ]))
            ->assertOk()
            ->json();

        $buttonsModule = collect($response['builder']['blocks'][0]['settings_payload']['modules'] ?? [])
            ->firstWhere('type', 'buttons');

        $this->assertSame('link', data_get($buttonsModule, 'payload.rows.0.0.type'));
        $this->assertSame('https://example.com/form', data_get($buttonsModule, 'payload.rows.0.0.url'));
        $this->assertSame([], data_get($response, 'builder.blocks.0.settings_payload.outputs'));
    }

    public function test_put_state_rejects_link_button_without_valid_url(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_save_invalid_link_button',
            'name' => 'V3 Save Invalid Link Button',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();

        $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_start',
                    'type' => 'state',
                    'title' => 'Старт',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->startMessageButtonsSettings(
                        '/start',
                        [(int) $channel->id],
                        'Откройте ссылку',
                        'Открыть сайт',
                        '',
                        'link',
                        'not-a-url',
                    ),
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'builder.blocks.0.settings_payload.modules.2.payload.rows.0.0.url',
            ]);
    }

    public function test_publish_v3_graph_rejects_stale_revision(): void
    {
        $admin = $this->adminUser();
        $channel = Channel::factory()->create(['is_active' => true]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_publish_stale',
            'name' => 'V3 Publish Stale',
        ]);
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();
        $savedState = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_start',
                    'type' => 'state',
                    'title' => 'Старт',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->startSettings('/start', [(int) $channel->id]),
                ],
            ]))
            ->assertOk()
            ->json();
        $draft = $scenario->fresh()->draftVersion()->firstOrFail();

        $draft->forceFill([
            'schema_payload' => [
                'builder_v3' => [
                    'revision' => 'v3:2099-01-01T00:00:00.000000Z',
                    'visible_scope' => $savedState['builder']['visible_scope'],
                ],
            ],
        ])->save();

        $this->actingAs($admin)
            ->postJson($this->publishUrl($scenario), [
                'draft_version_id' => $savedState['scenario']['draft_version_id'],
                'base_revision' => $savedState['builder']['revision'],
            ])
            ->assertStatus(409);

        $this->assertDatabaseMissing('scenario_versions', [
            'scenario_id' => $scenario->id,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
        ]);
        $this->assertDatabaseMissing('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
        ]);
    }

    public function test_employee_without_scenarios_edit_cannot_access_state_api(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_forbidden',
            'name' => 'V3 Forbidden',
        ]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'scenarios.edit')
            ->update(['granted' => false]);

        $this->actingAs($employee->fresh())
            ->getJson($this->stateUrl($scenario))
            ->assertForbidden();
    }

    public function test_employee_without_channel_edit_cannot_see_or_save_v3_start_channels(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Канал без права',
            'is_active' => true,
        ]);
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'v3_channel_permissions',
            'name' => 'V3 Channel Permissions',
        ]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'scenarios.edit')
            ->update(['granted' => true]);
        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'channels.edit')
            ->update(['granted' => false]);

        $state = $this->actingAs($employee->fresh())
            ->getJson($this->stateUrl($scenario))
            ->assertOk()
            ->json();

        $this->assertNotContains(
            $channel->id,
            collect($state['catalogs']['channels'])->pluck('id')->all(),
        );

        $this->actingAs($employee->fresh())
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_start',
                    'type' => 'state',
                    'title' => 'Старт',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->startMessageButtonsSettings('/start', [(int) $channel->id], 'Привет', 'Далее'),
                ],
            ]))
            ->assertJsonValidationErrors(['builder.start_condition.channels']);

        $this->assertDatabaseMissing('scenario_builder_block_channels', [
            'channel_id' => $channel->id,
        ]);
    }

    private function saveSingleStartBlockState(User $admin, Scenario $scenario, Channel $channel): array
    {
        $state = $this->actingAs($admin)->getJson($this->stateUrl($scenario))->json();

        return $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($state, [
                [
                    'id' => null,
                    'client_key' => 'tmp_start',
                    'type' => 'state',
                    'title' => 'Старт',
                    'position' => ['x' => 64, 'y' => 64],
                    'settings_payload' => $this->startMessageButtonsSettings('/start', [(int) $channel->id], 'Привет', 'Далее'),
                ],
            ]))
            ->assertOk()
            ->json();
    }

    private function createScheduledTransitionForScenario(
        Scenario $scenario,
        Channel $channel,
        ?ScenarioVersion $publishedVersion,
    ): ScenarioV3ScheduledTransition {
        $dialog = Dialog::factory()->create(['channel_id' => $channel->id]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
        ]);
        $run = ScenarioRun::query()->create([
            'scenario_code' => $scenario->code,
            'dialog_id' => $dialog->id,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'source',
            'state_payload' => [
                'v3' => [
                    'published_version_id' => $publishedVersion?->id,
                    'current_block_id' => 'source',
                ],
            ],
            'started_at' => now(),
        ]);

        return ScenarioV3ScheduledTransition::query()->create([
            'scenario_run_id' => $run->id,
            'dialog_id' => $dialog->id,
            'inbound_message_id' => $message->id,
            'scenario_code' => $scenario->code,
            'published_version_id' => $publishedVersion?->id,
            'edge_key' => 'edge_pending',
            'edge_id' => 'edge_pending',
            'source_block_id' => 'source',
            'target_block_id' => 'target',
            'delay_payload' => ['type' => 'relative', 'value' => 15, 'unit' => 'sec', 'cancel_if_left_source_block' => true],
            'scheduled_for' => now()->addSeconds(15),
            'status' => ScenarioV3ScheduledTransition::STATUS_SCHEDULED,
        ]);
    }

    private function adminUser(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
    }

    private function stateUrl(Scenario $scenario): string
    {
        return route('admin.scenario-constructor.v3.state.show', ['scenario' => $scenario]);
    }

    private function publishUrl(Scenario $scenario): string
    {
        return route('admin.scenario-constructor.v3.publish', ['scenario' => $scenario]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $edges
     * @return array<string, mixed>
     */
    private function payloadFromState(array $state, array $blocks, array $edges = []): array
    {
        return [
            'draft_version_id' => $state['scenario']['draft_version_id'],
            'base_revision' => $state['builder']['revision'],
            'builder' => [
                'schema_version' => 3,
                'active_sheet_id' => 'main',
                'sheets' => $state['builder']['sheets'],
                'blocks' => $blocks,
                'edges' => $edges,
                'visible_scope' => $state['builder']['visible_scope'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messageSettings(string $text): array
    {
        return [
            'schema_version' => 3,
            'kind' => 'state',
            'ui' => ['sheet_id' => 'main', 'width' => 320, 'collapsed' => false],
            'modules' => [
                [
                    'id' => 'mod_message',
                    'type' => 'message',
                    'enabled' => true,
                    'payload' => ['text' => $text, 'text_format' => 'plain_text'],
                ],
            ],
            'outputs' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aiAnalysisSettings(): array
    {
        return [
            'schema_version' => 3,
            'kind' => 'state',
            'ui' => ['sheet_id' => 'main', 'width' => 320, 'collapsed' => false],
            'modules' => [
                [
                    'id' => 'mod_ai',
                    'type' => 'ai',
                    'enabled' => true,
                    'payload' => [
                        'prompt' => 'Определи, есть ли в ответе клиента имя.',
                        'source' => 'current_inbound_message',
                        'variants' => [
                            ['id' => 'name_accepted', 'label' => 'Имя найдено', 'delay_seconds' => 0],
                            ['id' => 'name_retry', 'label' => 'Имя не найдено', 'delay_seconds' => 10],
                        ],
                        'extract_fields' => [
                            [
                                'key' => 'first_name',
                                'label' => 'Имя клиента',
                                'type' => 'text',
                            ],
                        ],
                    ],
                ],
            ],
            'outputs' => [
                [
                    'id' => 'name_accepted',
                    'label' => 'Имя найдено',
                    'source' => 'ai',
                    'module_id' => 'mod_ai',
                    'ai_variant_id' => 'name_accepted',
                ],
                [
                    'id' => 'name_retry',
                    'label' => 'Имя не найдено',
                    'source' => 'ai',
                    'module_id' => 'mod_ai',
                    'ai_variant_id' => 'name_retry',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actionSettings(string $sourceFieldKey, string $targetField): array
    {
        return [
            'schema_version' => 3,
            'kind' => 'state',
            'ui' => ['sheet_id' => 'main', 'width' => 320, 'collapsed' => false],
            'modules' => [
                [
                    'id' => 'mod_action',
                    'type' => 'action',
                    'enabled' => true,
                    'payload' => [
                        'actions' => [
                            [
                                'type' => 'write_contact_field',
                                'source_type' => 'ai_data',
                                'source_block_client_key' => '',
                                'source_block_id' => '',
                                'source_field_key' => $sourceFieldKey,
                                'target_scope' => 'contact',
                                'target_field' => $targetField,
                            ],
                        ],
                    ],
                ],
            ],
            'outputs' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDataActionSettings(string $targetVariableKey): array
    {
        return [
            'schema_version' => 3,
            'kind' => 'state',
            'ui' => ['sheet_id' => 'main', 'width' => 320, 'collapsed' => false],
            'modules' => [
                [
                    'id' => 'mod_action',
                    'type' => 'action',
                    'enabled' => true,
                    'payload' => [
                        'actions' => [
                            [
                                'type' => 'check_data',
                                'source_type' => 'inbound_message',
                                'check_source' => 'current_inbound_message',
                                'dictionary_key' => 'names',
                                'lookup_field' => 'lookup_value',
                                'result_field' => 'result_value',
                                'target_variable_key' => $targetVariableKey,
                            ],
                        ],
                    ],
                ],
            ],
            'outputs' => [
                [
                    'id' => 'data_found',
                    'label' => 'Найдено',
                    'source' => 'action',
                    'module_id' => 'mod_action',
                    'action_result_id' => 'data_found',
                ],
                [
                    'id' => 'data_not_found',
                    'label' => 'Не найдено',
                    'source' => 'action',
                    'module_id' => 'mod_action',
                    'action_result_id' => 'data_not_found',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function editMessageActionSettings(): array
    {
        return [
            'schema_version' => 3,
            'kind' => 'state',
            'ui' => ['sheet_id' => 'main', 'width' => 320, 'collapsed' => false],
            'modules' => [
                [
                    'id' => 'mod_action',
                    'type' => 'action',
                    'enabled' => true,
                    'payload' => [
                        'actions' => [
                            [
                                'type' => 'edit_message',
                                'operation' => 'remove_buttons',
                                'target' => 'last_current_run_outbound_with_inline_buttons',
                            ],
                        ],
                    ],
                ],
            ],
            'outputs' => [],
        ];
    }

    /**
     * @param  list<int>  $channelIds
     * @return array<string, mixed>
     */
    private function startSettings(string $command, array $channelIds): array
    {
        return [
            'schema_version' => 3,
            'kind' => 'state',
            'ui' => ['sheet_id' => 'main', 'width' => 320, 'collapsed' => false],
            'modules' => [
                [
                    'id' => 'mod_start',
                    'type' => 'start_condition',
                    'enabled' => true,
                    'payload' => [
                        'command' => $command,
                        'match' => 'strict',
                        'variable' => '',
                        'exclude' => '',
                        'priority' => 10,
                        'once' => false,
                        'channels' => ['mode' => 'selected', 'ids' => $channelIds],
                    ],
                ],
            ],
            'outputs' => [],
        ];
    }

    /**
     * @param  list<int>  $channelIds
     * @return array<string, mixed>
     */
    private function startMessageButtonsSettings(
        string $command,
        array $channelIds,
        string $message,
        string $buttonText,
        string $contactPhoneCondition = '',
        string $buttonType = 'text',
        ?string $buttonUrl = null,
    ): array {
        return [
            'schema_version' => 3,
            'kind' => 'state',
            'ui' => ['sheet_id' => 'main', 'width' => 320, 'collapsed' => false],
            'modules' => [
                [
                    'id' => 'mod_start',
                    'type' => 'start_condition',
                    'enabled' => true,
                    'payload' => [
                        'command' => $command,
                        'match' => 'strict',
                        'variable' => '',
                        'exclude' => '',
                        'contact_phone_condition' => $contactPhoneCondition,
                        'dialog_phone_condition' => '',
                        'priority' => 10,
                        'once' => false,
                        'channels' => ['mode' => 'selected', 'ids' => $channelIds],
                    ],
                ],
                [
                    'id' => 'mod_message',
                    'type' => 'message',
                    'enabled' => true,
                    'payload' => ['text' => $message, 'text_format' => 'plain_text'],
                ],
                [
                    'id' => 'mod_buttons',
                    'type' => 'buttons',
                    'enabled' => true,
                    'payload' => [
                        'placement' => 'auto',
                        'rows' => [[
                            ['id' => 'btn_catalog', 'text' => $buttonText, 'type' => $buttonType, 'fn' => 'default', 'url' => $buttonUrl, 'color' => null],
                        ]],
                    ],
                ],
            ],
            'outputs' => $buttonType === 'link' ? [] : [
                [
                    'id' => 'btn_catalog',
                    'label' => $buttonText,
                    'source' => 'button',
                    'module_id' => 'mod_buttons',
                    'button_id' => 'btn_catalog',
                    'button_type' => $buttonType,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function edgePayload(?string $outputId, string $label): array
    {
        $isButton = $outputId !== null;

        return [
            'schema_version' => 3,
            'edge_schema_version' => 3,
            'edge_key' => null,
            'from_output_id' => $outputId,
            'label' => $label,
            'mode' => $isButton ? 'button' : 'wait_reply',
            'priority' => 10,
            'transition_limit' => 0,
            'contact_phone_condition' => '',
            'dialog_phone_condition' => '',
            'field_condition' => [
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'operator' => 'filled',
                'value' => '',
            ],
            'match' => [
                'type' => $isButton ? 'exact_text' : 'any_inbound',
                'text' => $isButton ? $label : '',
            ],
            'input_capture' => [
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'data_type' => 'any_text',
            ],
            'delay' => [
                'type' => 'immediate',
                'value' => 0,
                'unit' => 'sec',
                'cancel_if_left_source_block' => true,
            ],
        ];
    }
}
