<?php

namespace App\Services\Scenarios;

use App\Models\Channel;
use App\Models\Scenario;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioBuilderCondition;
use App\Models\ScenarioBuilderEdge;
use App\Models\ScenarioVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BuildScenarioBuilderV3StateAction
{
    public const SCHEMA_VERSION = 3;

    private const DEFAULT_SHEET_ID = 'main';

    private const DEFAULT_SHEET_NAME = 'Главный';

    /**
     * @param  array<string, mixed>  $idMap
     * @return array<string, mixed>
     */
    public function handle(Scenario $scenario, ?User $user = null, array $idMap = []): array
    {
        $scenario->loadMissing(['draftVersion', 'publishedVersion']);
        $draftVersion = $scenario->draftVersion;
        $builder = $draftVersion instanceof ScenarioVersion
            ? $this->buildBuilder($draftVersion)
            : $this->emptyBuilder(null);

        return [
            'scenario' => [
                'id' => (int) $scenario->id,
                'name' => (string) $scenario->name,
                'editable_version_id' => $draftVersion instanceof ScenarioVersion ? (int) $draftVersion->id : null,
                'editable_version_number' => $draftVersion instanceof ScenarioVersion ? (int) $draftVersion->version_number : null,
                'draft_version_id' => $draftVersion instanceof ScenarioVersion ? (int) $draftVersion->id : null,
                'draft_version_number' => $draftVersion instanceof ScenarioVersion ? (int) $draftVersion->version_number : null,
            ],
            'builder' => $builder,
            'catalogs' => [
                'channels' => $this->channelsCatalog(),
                'module_types' => ['start_condition', 'message', 'buttons'],
            ],
            'permissions' => [
                'can_update' => $user instanceof User && $user->hasRolePermission('scenarios.edit') && $user->can('update', $scenario),
                'can_publish' => $user instanceof User && $user->hasRolePermission('scenarios.edit') && $user->can('update', $scenario),
            ],
            'warnings' => [],
            'id_map' => $idMap,
        ];
    }

    public function revisionFor(ScenarioVersion $version): string
    {
        $timestamps = collect([$version->updated_at]);
        $storedRevisionTimestamp = $this->timestampFromRevision(data_get($this->schemaPayload($version), 'builder_v3.revision'));

        if ($storedRevisionTimestamp instanceof CarbonImmutable) {
            $timestamps->push($storedRevisionTimestamp);
        }

        $timestamps->push($version->builderBlocks()->max('updated_at'));
        $timestamps->push($version->builderEdges()->max('updated_at'));

        $blockIds = $version->builderBlocks()->pluck('id');

        if ($blockIds->isNotEmpty()) {
            $timestamps->push(ScenarioBuilderCondition::query()
                ->whereIn('scenario_builder_block_id', $blockIds)
                ->max('updated_at'));
            $timestamps->push(DB::table('scenario_builder_block_channels')
                ->whereIn('scenario_builder_block_id', $blockIds)
                ->max('updated_at'));
        }

        $latest = $timestamps
            ->filter()
            ->map(fn (mixed $timestamp): CarbonImmutable => CarbonImmutable::parse($timestamp))
            ->sort()
            ->last();

        return 'v3:'.($latest instanceof CarbonImmutable ? $latest : CarbonImmutable::now())->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function timestampFromRevision(mixed $revision): ?CarbonImmutable
    {
        if (! is_string($revision) || ! str_starts_with($revision, 'v3:')) {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr($revision, 3));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{block_ids: list<int>, edge_ids: list<int>}
     */
    public function visibleScopeFor(ScenarioVersion $version): array
    {
        $blockIds = $version->builderBlocks()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $edgeIds = $version->builderEdges()
            ->whereNotNull('to_scenario_builder_block_id')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return [
            'block_ids' => $blockIds,
            'edge_ids' => $edgeIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBuilder(ScenarioVersion $version): array
    {
        $schemaPayload = $this->schemaPayload($version);
        $builderProjection = is_array($schemaPayload['builder_v3'] ?? null) ? $schemaPayload['builder_v3'] : [];
        $visibleScope = $this->visibleScopeFor($version);
        $sheets = $this->normalizeSheets(data_get($builderProjection, 'sheets'));

        $blocks = $version->builderBlocks()
            ->with(['channels', 'conditions', 'outgoingEdges'])
            ->orderBy('id')
            ->get()
            ->map(fn (ScenarioBuilderBlock $block): array => $this->blockToBuilderState($block))
            ->values()
            ->all();

        $visibleEdgeIds = $visibleScope['edge_ids'];
        $edges = $version->builderEdges()
            ->whereIn('id', $visibleEdgeIds === [] ? [0] : $visibleEdgeIds)
            ->with(['fromBuilderBlock', 'toBuilderBlock'])
            ->orderBy('id')
            ->get()
            ->map(fn (ScenarioBuilderEdge $edge): array => $this->edgeToBuilderState($edge))
            ->values()
            ->all();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'editable',
            'revision' => $this->revisionFor($version),
            'active_sheet_id' => (string) (data_get($builderProjection, 'active_sheet_id') ?: self::DEFAULT_SHEET_ID),
            'sheets' => $sheets,
            'blocks' => $blocks,
            'edges' => $edges,
            'visible_scope' => $visibleScope,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBuilder(?ScenarioVersion $version): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'editable',
            'revision' => $version instanceof ScenarioVersion ? $this->revisionFor($version) : 'v3:empty',
            'active_sheet_id' => self::DEFAULT_SHEET_ID,
            'sheets' => $this->normalizeSheets(null),
            'blocks' => [],
            'edges' => [],
            'visible_scope' => [
                'block_ids' => [],
                'edge_ids' => [],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeSheets(mixed $sheets): array
    {
        if (! is_array($sheets) || $sheets === []) {
            return [
                [
                    'id' => self::DEFAULT_SHEET_ID,
                    'name' => self::DEFAULT_SHEET_NAME,
                    'color' => 'none',
                    'view' => ['tx' => 0, 'ty' => 0, 'zoom' => 1],
                ],
            ];
        }

        return collect($sheets)
            ->filter(fn (mixed $sheet): bool => is_array($sheet))
            ->map(fn (array $sheet): array => [
                'id' => (string) ($sheet['id'] ?? self::DEFAULT_SHEET_ID),
                'name' => (string) ($sheet['name'] ?? self::DEFAULT_SHEET_NAME),
                'color' => (string) ($sheet['color'] ?? 'none'),
                'view' => [
                    'tx' => (float) data_get($sheet, 'view.tx', 0),
                    'ty' => (float) data_get($sheet, 'view.ty', 0),
                    'zoom' => (float) data_get($sheet, 'view.zoom', 1),
                ],
            ])
            ->values()
            ->all() ?: $this->normalizeSheets(null);
    }

    /**
     * @return array<string, mixed>
     */
    private function blockToBuilderState(ScenarioBuilderBlock $block): array
    {
        return [
            'id' => (int) $block->id,
            'client_key' => 'block_'.$block->id,
            'type' => 'state',
            'title' => (string) $block->title,
            'position' => [
                'x' => (int) $block->position_x,
                'y' => (int) $block->position_y,
            ],
            'settings_payload' => $this->settingsPayloadForBlock($block),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayloadForBlock(ScenarioBuilderBlock $block): array
    {
        $settingsPayload = is_array($block->settings_payload) ? $block->settings_payload : [];

        if ((int) ($settingsPayload['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            $settingsPayload = $this->legacySettingsPayload($block, $settingsPayload);
        }

        $settingsPayload['schema_version'] = self::SCHEMA_VERSION;
        $settingsPayload['kind'] = 'state';
        $settingsPayload['ui'] = is_array($settingsPayload['ui'] ?? null) ? $settingsPayload['ui'] : [
            'sheet_id' => self::DEFAULT_SHEET_ID,
            'width' => 320,
            'collapsed' => false,
        ];
        $settingsPayload['modules'] = $this->modulesWithCanonicalStartCondition($block, $settingsPayload['modules'] ?? []);
        $settingsPayload['outputs'] = is_array($settingsPayload['outputs'] ?? null) ? array_values($settingsPayload['outputs']) : [];

        return $settingsPayload;
    }

    /**
     * @param  array<string, mixed>  $settingsPayload
     * @return array<string, mixed>
     */
    private function legacySettingsPayload(ScenarioBuilderBlock $block, array $settingsPayload): array
    {
        $modules = [];

        if ($block->type === ScenarioBuilderBlock::TYPE_START_CONDITION || $block->conditions->isNotEmpty()) {
            $modules[] = [
                'id' => 'mod_start',
                'type' => 'start_condition',
                'enabled' => true,
                'payload' => [
                    'command' => (string) ($block->conditions->first()?->value ?? ''),
                    'match' => (string) ($block->conditions->first()?->match_operator ?? 'strict'),
                    'variable' => '',
                    'exclude' => '',
                    'priority' => 10,
                    'once' => false,
                    'channels' => [
                        'mode' => 'selected',
                        'ids' => $this->channelIds($block),
                    ],
                ],
            ];
        }

        $messageText = (string) data_get($settingsPayload, 'message_text', '');

        if ($messageText !== '') {
            $modules[] = [
                'id' => 'mod_message',
                'type' => 'message',
                'enabled' => true,
                'payload' => [
                    'text' => $messageText,
                    'text_format' => 'plain_text',
                ],
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'kind' => 'state',
            'ui' => [
                'sheet_id' => self::DEFAULT_SHEET_ID,
                'width' => 320,
                'collapsed' => false,
            ],
            'modules' => $modules,
            'outputs' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function modulesWithCanonicalStartCondition(ScenarioBuilderBlock $block, mixed $modules): array
    {
        $normalizedModules = is_array($modules) ? array_values(array_filter($modules, is_array(...))) : [];
        $startModuleIndex = null;

        foreach ($normalizedModules as $index => $module) {
            if (($module['type'] ?? null) === 'start_condition') {
                $startModuleIndex = $index;
                break;
            }
        }

        if ($startModuleIndex === null) {
            return $normalizedModules;
        }

        $conditions = $block->conditions;
        $payload = is_array(data_get($normalizedModules[$startModuleIndex], 'payload'))
            ? data_get($normalizedModules[$startModuleIndex], 'payload')
            : [];

        $payload['command'] = (string) ($conditions->first()?->value ?? ($payload['command'] ?? ''));
        $payload['values'] = $conditions
            ->map(fn (ScenarioBuilderCondition $condition): string => (string) $condition->value)
            ->values()
            ->all();
        $payload['match'] = (string) ($conditions->first()?->match_operator ?? ($payload['match'] ?? 'strict'));
        $payload['channels'] = [
            'mode' => 'selected',
            'ids' => $this->channelIds($block),
        ];

        $normalizedModules[$startModuleIndex]['payload'] = $payload;

        return $normalizedModules;
    }

    /**
     * @return list<int>
     */
    private function channelIds(ScenarioBuilderBlock $block): array
    {
        return $block->channels
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function edgeToBuilderState(ScenarioBuilderEdge $edge): array
    {
        $conditionPayload = is_array($edge->condition_payload) ? $edge->condition_payload : [];
        $fromOutputId = $conditionPayload['from_output_id'] ?? null;

        return [
            'id' => (int) $edge->id,
            'client_key' => 'edge_'.$edge->id,
            'source' => [
                'block_id' => (int) $edge->from_scenario_builder_block_id,
                'client_key' => 'block_'.$edge->from_scenario_builder_block_id,
                'output_id' => is_string($fromOutputId) && $fromOutputId !== '' ? $fromOutputId : null,
            ],
            'target' => [
                'block_id' => $edge->to_scenario_builder_block_id !== null ? (int) $edge->to_scenario_builder_block_id : null,
                'client_key' => $edge->to_scenario_builder_block_id !== null ? 'block_'.$edge->to_scenario_builder_block_id : null,
            ],
            'condition_payload' => $conditionPayload,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channelsCatalog(): array
    {
        return Channel::query()
            ->orderBy('id')
            ->get()
            ->map(fn (Channel $channel): array => [
                'id' => (int) $channel->id,
                'name' => (string) $channel->name,
                'platform' => (string) $channel->platform,
                'connection_type' => (string) $channel->connection_type,
                'is_active' => (bool) $channel->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaPayload(ScenarioVersion $version): array
    {
        return is_array($version->schema_payload) ? $version->schema_payload : [];
    }
}
