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
        $this->assertDatabaseHas('scenario_builder_blocks', [
            'id' => $blockId,
            'scenario_version_id' => $scenario->fresh()->draftVersion?->id,
            'type' => 'state',
            'title' => 'Приветствие',
            'position_x' => 120,
            'position_y' => 160,
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
}
