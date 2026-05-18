<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Scenario;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioBuilderCondition;
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
            ->assertJsonPath('builder.visible_scope.block_ids', []);
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

        $savedAgain = $this->actingAs($admin)
            ->putJson($this->stateUrl($scenario), $this->payloadFromState($saved, $saved['builder']['blocks'], $saved['builder']['edges']))
            ->assertOk()
            ->json();

        $this->assertSame($edgeKey, data_get($savedAgain, 'builder.edges.0.condition_payload.edge_key'));
        $this->assertSame($edgeDisplayId, data_get($savedAgain, 'builder.edges.0.condition_payload.ui.edge_id'));
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
                'builder.edges.0.condition_payload.input_capture.field_scope',
            ]);
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
                            ['id' => 'btn_catalog', 'text' => $buttonText, 'type' => $buttonType, 'fn' => 'default', 'url' => null, 'color' => null],
                        ]],
                    ],
                ],
            ],
            'outputs' => [
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
        ];
    }
}
