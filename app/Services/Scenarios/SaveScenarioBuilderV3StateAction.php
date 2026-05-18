<?php

namespace App\Services\Scenarios;

use App\Models\AutoReplyRule;
use App\Models\Scenario;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioBuilderCondition;
use App\Models\ScenarioBuilderEdge;
use App\Models\ScenarioVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SaveScenarioBuilderV3StateAction
{
    public function __construct(
        private readonly ValidateScenarioBuilderV3StateAction $validateScenarioBuilderV3StateAction,
        private readonly BuildScenarioBuilderV3StateAction $buildScenarioBuilderV3StateAction,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(Scenario $scenario, array $input): array
    {
        $validated = $this->validateScenarioBuilderV3StateAction->handle($input);
        $idMap = [
            'blocks' => [],
            'edges' => [],
        ];

        DB::transaction(function () use ($scenario, $validated, &$idMap): void {
            $version = ScenarioVersion::query()
                ->whereKey($validated['draft_version_id'])
                ->where('scenario_id', $scenario->id)
                ->where('status', ScenarioVersion::STATUS_DRAFT)
                ->lockForUpdate()
                ->first();

            if (! $version instanceof ScenarioVersion) {
                throw ValidationException::withMessages([
                    'draft_version_id' => 'Editable scenario version was not found.',
                ]);
            }

            $currentRevision = $this->buildScenarioBuilderV3StateAction->revisionFor($version);

            if ($validated['base_revision'] !== $currentRevision) {
                throw new HttpException(409, 'Scenario builder state was changed. Reload state before saving.');
            }

            $serverVisibleScope = $this->serverVisibleScope($version);
            $this->guardVisibleScope($validated['builder']['visible_scope'], $serverVisibleScope);

            $blockMap = $this->saveBlocks($version, $validated['builder']['blocks'], $serverVisibleScope, $idMap);
            $this->deleteRemovedEdges($version, $validated['builder']['edges'], $serverVisibleScope, $blockMap['deleted_block_ids']);
            $this->saveEdges($version, $validated['builder']['edges'], $serverVisibleScope, $blockMap['block_ids_by_client_key'], $idMap);
            $this->deleteRemovedBlocks($version, $blockMap['deleted_block_ids']);
            $this->persistBuilderProjection($version, $validated['builder']);
        });

        return $this->buildScenarioBuilderV3StateAction->handle($scenario->fresh(['draftVersion']), auth()->user(), $idMap);
    }

    /**
     * @return array{block_ids: list<int>, edge_ids: list<int>}
     */
    private function serverVisibleScope(ScenarioVersion $version): array
    {
        return $this->buildScenarioBuilderV3StateAction->visibleScopeFor($version);
    }

    /**
     * @param  array{block_ids: list<int>, edge_ids: list<int>}  $clientVisibleScope
     * @param  array{block_ids: list<int>, edge_ids: list<int>}  $serverVisibleScope
     */
    private function guardVisibleScope(array $clientVisibleScope, array $serverVisibleScope): void
    {
        $unknownBlockIds = array_diff($clientVisibleScope['block_ids'], $serverVisibleScope['block_ids']);
        $unknownEdgeIds = array_diff($clientVisibleScope['edge_ids'], $serverVisibleScope['edge_ids']);

        if ($unknownBlockIds !== [] || $unknownEdgeIds !== []) {
            throw ValidationException::withMessages([
                'visible_scope' => 'Client visible_scope cannot contain ids that were not shown by backend.',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array{block_ids: list<int>, edge_ids: list<int>}  $serverVisibleScope
     * @param  array{blocks: array<string, int>, edges: array<string, int>}  $idMap
     * @return array{block_ids_by_client_key: array<string, int>, deleted_block_ids: list<int>}
     */
    private function saveBlocks(ScenarioVersion $version, array $blocks, array $serverVisibleScope, array &$idMap): array
    {
        $existingBlocks = $version->builderBlocks()
            ->whereIn('id', collect($blocks)->pluck('id')->filter()->all() ?: [0])
            ->get()
            ->keyBy('id');
        $incomingExistingIds = [];
        $blockIdsByClientKey = [];

        foreach ($blocks as $block) {
            $blockId = $block['id'];

            if ($blockId !== null) {
                if (! in_array($blockId, $serverVisibleScope['block_ids'], true)) {
                    throw ValidationException::withMessages([
                        'builder.blocks' => 'Block does not belong to visible builder scope.',
                    ]);
                }

                $model = $existingBlocks->get($blockId);

                if (! $model instanceof ScenarioBuilderBlock) {
                    throw ValidationException::withMessages([
                        'builder.blocks' => 'Builder block was not found in editable version.',
                    ]);
                }

                $incomingExistingIds[] = (int) $blockId;
            } else {
                $model = new ScenarioBuilderBlock([
                    'scenario_version_id' => $version->id,
                ]);
            }

            $settingsPayload = $this->settingsPayloadWithCanonicalStartCommand($block['settings_payload']);
            $blockType = $this->dbTypeForBlock($model, $settingsPayload);

            $model->forceFill([
                'scenario_version_id' => $version->id,
                'type' => $blockType,
                'title' => $block['title'],
                'position_x' => $block['position']['x'],
                'position_y' => $block['position']['y'],
                'settings_payload' => $settingsPayload,
            ])->save();

            $settingsPayload = $this->settingsPayloadWithStableCardId($model, $settingsPayload);

            $model->forceFill([
                'settings_payload' => $settingsPayload,
            ])->save();

            $this->syncStartConditionTables($model, $settingsPayload);

            $blockIdsByClientKey[$block['client_key']] = (int) $model->id;

            if ($blockId === null) {
                $idMap['blocks'][$block['client_key']] = (int) $model->id;
            }
        }

        return [
            'block_ids_by_client_key' => $blockIdsByClientKey,
            'deleted_block_ids' => array_values(array_diff($serverVisibleScope['block_ids'], $incomingExistingIds)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @param  array{block_ids: list<int>, edge_ids: list<int>}  $serverVisibleScope
     * @param  list<int>  $deletedBlockIds
     */
    private function deleteRemovedEdges(ScenarioVersion $version, array $edges, array $serverVisibleScope, array $deletedBlockIds): void
    {
        $incomingExistingEdgeIds = collect($edges)
            ->pluck('id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($serverVisibleScope['edge_ids'] !== []) {
            $version->builderEdges()
                ->whereIn('id', $serverVisibleScope['edge_ids'])
                ->whereNotIn('id', $incomingExistingEdgeIds === [] ? [0] : $incomingExistingEdgeIds)
                ->delete();
        }

        if ($deletedBlockIds !== []) {
            $version->builderEdges()
                ->where(function ($query) use ($deletedBlockIds): void {
                    $query
                        ->whereIn('from_scenario_builder_block_id', $deletedBlockIds)
                        ->orWhereIn('to_scenario_builder_block_id', $deletedBlockIds);
                })
                ->delete();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @param  array{block_ids: list<int>, edge_ids: list<int>}  $serverVisibleScope
     * @param  array<string, int>  $blockIdsByClientKey
     * @param  array{blocks: array<string, int>, edges: array<string, int>}  $idMap
     */
    private function saveEdges(ScenarioVersion $version, array $edges, array $serverVisibleScope, array $blockIdsByClientKey, array &$idMap): void
    {
        $existingEdges = $version->builderEdges()
            ->whereIn('id', collect($edges)->pluck('id')->filter()->all() ?: [0])
            ->get()
            ->keyBy('id');
        $usedEdgeKeys = [];

        foreach ($edges as $edge) {
            $edgeId = $edge['id'];

            if ($edgeId !== null) {
                if (! in_array($edgeId, $serverVisibleScope['edge_ids'], true)) {
                    throw ValidationException::withMessages([
                        'builder.edges' => 'Edge does not belong to visible builder scope.',
                    ]);
                }

                $model = $existingEdges->get($edgeId);

                if (! $model instanceof ScenarioBuilderEdge) {
                    throw ValidationException::withMessages([
                        'builder.edges' => 'Builder edge was not found in editable version.',
                    ]);
                }
            } else {
                $model = new ScenarioBuilderEdge([
                    'scenario_version_id' => $version->id,
                ]);
            }

            $fromBlockId = $blockIdsByClientKey[$edge['source']['client_key']] ?? null;
            $toBlockId = $blockIdsByClientKey[$edge['target']['client_key']] ?? null;

            if ($fromBlockId === null || $toBlockId === null) {
                throw ValidationException::withMessages([
                    'builder.edges' => 'Edge endpoint cannot be resolved.',
                ]);
            }

            $conditionPayload = $this->conditionPayloadWithStableEdgeKey($model, $edge['condition_payload'], $usedEdgeKeys);

            $model->forceFill([
                'scenario_version_id' => $version->id,
                'from_scenario_builder_block_id' => $fromBlockId,
                'to_scenario_builder_block_id' => $toBlockId,
                'to_runtime_block_id' => null,
                'condition_payload' => $conditionPayload,
                'sort_order' => 1,
            ])->save();

            $conditionPayload = $this->conditionPayloadWithStableDisplayId($model, $conditionPayload);

            $model->forceFill([
                'condition_payload' => $conditionPayload,
            ])->save();

            if ($edgeId === null) {
                $idMap['edges'][$edge['client_key']] = (int) $model->id;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $conditionPayload
     * @param  array<string, true>  $usedEdgeKeys
     * @return array<string, mixed>
     */
    private function conditionPayloadWithStableEdgeKey(ScenarioBuilderEdge $edge, array $conditionPayload, array &$usedEdgeKeys): array
    {
        $existingPayload = is_array($edge->condition_payload) ? $edge->condition_payload : [];
        $edgeKey = $this->validEdgeKey($existingPayload['edge_key'] ?? null);

        if ($edgeKey === null && $edge->exists) {
            $edgeKey = $this->validEdgeKey($conditionPayload['edge_key'] ?? null);
        }

        $edgeKey ??= $this->newEdgeKey($usedEdgeKeys);

        if (isset($usedEdgeKeys[$edgeKey])) {
            throw ValidationException::withMessages([
                'builder.edges' => 'Edge key must be unique.',
            ]);
        }

        $usedEdgeKeys[$edgeKey] = true;
        $conditionPayload['edge_key'] = $edgeKey;
        $conditionPayload['edge_schema_version'] = BuildScenarioBuilderV3StateAction::SCHEMA_VERSION;
        $conditionPayload['schema_version'] = BuildScenarioBuilderV3StateAction::SCHEMA_VERSION;

        return $conditionPayload;
    }

    /**
     * @param  array<string, mixed>  $conditionPayload
     * @return array<string, mixed>
     */
    private function conditionPayloadWithStableDisplayId(ScenarioBuilderEdge $edge, array $conditionPayload): array
    {
        $displayId = trim((string) data_get($conditionPayload, 'ui.edge_id', ''));

        data_set($conditionPayload, 'ui.edge_id', ctype_digit($displayId) ? $displayId : (string) $edge->id);

        return $conditionPayload;
    }

    private function validEdgeKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) ? $value : null;
    }

    /**
     * @param  array<string, true>  $usedEdgeKeys
     */
    private function newEdgeKey(array $usedEdgeKeys): string
    {
        do {
            $edgeKey = 'edge_'.Str::lower(Str::random(12));
        } while (isset($usedEdgeKeys[$edgeKey]));

        return $edgeKey;
    }

    /**
     * @param  list<int>  $deletedBlockIds
     */
    private function deleteRemovedBlocks(ScenarioVersion $version, array $deletedBlockIds): void
    {
        if ($deletedBlockIds === []) {
            return;
        }

        $version->builderBlocks()
            ->whereIn('id', $deletedBlockIds)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $settingsPayload
     */
    private function settingsPayloadWithStableCardId(ScenarioBuilderBlock $block, array $settingsPayload): array
    {
        $cardId = trim((string) data_get($settingsPayload, 'ui.card_id', ''));

        data_set($settingsPayload, 'ui.card_id', $cardId !== '' ? $cardId : (string) $block->id);

        return $settingsPayload;
    }

    /**
     * @param  array<string, mixed>  $settingsPayload
     * @return array<string, mixed>
     */
    private function settingsPayloadWithCanonicalStartCommand(array $settingsPayload): array
    {
        $modules = is_array($settingsPayload['modules'] ?? null) ? $settingsPayload['modules'] : [];

        foreach ($modules as $index => $module) {
            if (! is_array($module) || ($module['type'] ?? null) !== 'start_condition') {
                continue;
            }

            $payload = is_array($module['payload'] ?? null) ? $module['payload'] : [];
            $matchOperator = (string) ($payload['match'] ?? AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD);

            if ($matchOperator === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND) {
                $payload['command'] = '';
            } else {
                $payload['command'] = trim((string) ($payload['command'] ?? ''));
            }

            $payload['values'] = [];
            $module['payload'] = $payload;
            $modules[$index] = $module;

            break;
        }

        $settingsPayload['modules'] = $modules;

        return $settingsPayload;
    }

    /**
     * @param  array<string, mixed>  $settingsPayload
     */
    private function syncStartConditionTables(ScenarioBuilderBlock $block, array $settingsPayload): void
    {
        $startModule = collect($settingsPayload['modules'] ?? [])->firstWhere('type', 'start_condition');

        if (! is_array($startModule)) {
            $block->channels()->sync([]);
            $block->conditions()->delete();

            return;
        }

        $payload = is_array($startModule['payload'] ?? null) ? $startModule['payload'] : [];
        $channelIds = $this->normalizeIdList(data_get($payload, 'channels.ids', []));
        $matchOperator = (string) ($payload['match'] ?? 'strict');
        $conditionValues = collect([$payload['command'] ?? null])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        if ($matchOperator === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND && $conditionValues === []) {
            $conditionValues = [''];
        }

        $block->channels()->sync($channelIds);
        $block->conditions()->delete();

        foreach ($conditionValues as $index => $conditionValue) {
            ScenarioBuilderCondition::query()->create([
                'scenario_builder_block_id' => $block->id,
                'type' => ScenarioBuilderCondition::TYPE_MESSAGE_PARAMETER,
                'match_operator' => $matchOperator,
                'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
                'value' => $conditionValue,
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $builder
     */
    private function persistBuilderProjection(ScenarioVersion $version, array $builder): void
    {
        $schemaPayload = $this->schemaPayload($version);
        $revision = 'v3:'.CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s.u\Z');

        data_set($schemaPayload, 'builder_v3', [
            'revision' => $revision,
            'active_sheet_id' => $builder['active_sheet_id'],
            'sheets' => $builder['sheets'],
            'visible_scope' => $this->buildScenarioBuilderV3StateAction->visibleScopeFor($version),
            'warnings' => [],
        ]);

        $version->forceFill([
            'schema_payload' => $schemaPayload,
        ])->save();
    }

    /**
     * @return list<int>
     */
    private function normalizeIdList(mixed $ids): array
    {
        return collect(is_array($ids) ? $ids : [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $settingsPayload
     */
    private function dbTypeForBlock(ScenarioBuilderBlock $block, array $settingsPayload): string
    {
        $hasStartCondition = collect($settingsPayload['modules'] ?? [])
            ->contains(fn (mixed $module): bool => is_array($module) && ($module['type'] ?? null) === 'start_condition');

        if ($hasStartCondition) {
            return ScenarioBuilderBlock::TYPE_START_CONDITION;
        }

        if ($block->exists && $block->type !== ScenarioBuilderBlock::TYPE_START_CONDITION) {
            return (string) $block->type;
        }

        return 'state';
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaPayload(ScenarioVersion $version): array
    {
        return is_array($version->schema_payload) ? $version->schema_payload : [];
    }
}
