<?php

namespace App\Services\Scenarios;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioBuilderCondition;
use App\Models\ScenarioBuilderEdge;
use App\Models\ScenarioVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncScenarioBuilderStartBlockAction
{
    private const BUILDER_SCHEMA_VERSION = 1;

    private const DEFAULT_START_BLOCK_ID = 'welcome';

    private const DEFAULT_START_NODE_TITLE = 'Название блока';

    private const DEFAULT_START_REPLY_TEXT = 'Старт сценария';

    private const DEFAULT_NEW_START_NODE_TITLE = 'Новое стартовое условие';

    private const DEFAULT_START_NODE_POSITION = [
        'x' => 64,
        'y' => 64,
    ];

    private const START_BUILDER_BLOCK_SECTIONS = [
        'message_text' => null,
        'calculator' => [],
        'actions' => [],
        'buttons' => [],
        'analytics_events' => [],
        'attachments' => [],
    ];

    public function ensureStartBlock(ScenarioVersion $version): ScenarioBuilderBlock
    {
        $existingBlock = $version->builderBlocks()
            ->where('type', ScenarioBuilderBlock::TYPE_START_CONDITION)
            ->with(['channels', 'conditions', 'outgoingEdges'])
            ->orderBy('id')
            ->first();

        if ($existingBlock instanceof ScenarioBuilderBlock) {
            return $existingBlock;
        }

        return DB::transaction(function () use ($version): ScenarioBuilderBlock {
            $schemaPayload = $this->schemaPayload($version);
            $legacyBlock = $this->legacyStartBuilderBlock($schemaPayload);
            $position = $this->normalizePosition(data_get($legacyBlock, 'position'));
            $startBlockId = $this->normalizeStartBlockId(
                data_get($legacyBlock, 'settings.start_block_id', $schemaPayload['start_block_id'] ?? null),
            );
            $rawConditionMatch = data_get(
                $legacyBlock,
                'settings.condition.match',
                data_get($schemaPayload, 'triggers.0.match_scope', data_get($schemaPayload, 'triggers.0.match')),
            );
            $conditionMatch = $this->normalizeConditionMatch($rawConditionMatch
                ?? (is_array($schemaPayload['triggers'] ?? null) && ($schemaPayload['triggers'] ?? []) !== []
                    ? AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER
                    : null));
            $replyText = $this->extractReplyText($schemaPayload, $legacyBlock, $startBlockId);

            $block = ScenarioBuilderBlock::query()->create([
                'scenario_version_id' => $version->id,
                'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
                'title' => $this->normalizeTitle(data_get($legacyBlock, 'title')),
                'position_x' => $position['x'],
                'position_y' => $position['y'],
                'settings_payload' => $this->defaultSettingsPayload($startBlockId, $conditionMatch, $replyText),
            ]);

            $this->replaceConditions($block, $this->extractTriggerValues($schemaPayload, $legacyBlock), $conditionMatch);
            $this->replaceStartEdge($version, $block, $startBlockId);

            return $block->fresh(['channels', 'conditions', 'outgoingEdges']);
        });
    }

    public function createStartBlock(ScenarioVersion $version): ScenarioBuilderBlock
    {
        return DB::transaction(function () use ($version): ScenarioBuilderBlock {
            $lockedVersion = ScenarioVersion::query()
                ->whereKey($version->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureStartBlock($lockedVersion);

            $startBlockCount = $lockedVersion->builderBlocks()
                ->where('type', ScenarioBuilderBlock::TYPE_START_CONDITION)
                ->count();
            $position = $this->normalizePosition([
                'x' => self::DEFAULT_START_NODE_POSITION['x'] + ($startBlockCount * 96),
                'y' => self::DEFAULT_START_NODE_POSITION['y'] + ($startBlockCount * 64),
            ]);

            $block = ScenarioBuilderBlock::query()->create([
                'scenario_version_id' => $lockedVersion->id,
                'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
                'title' => $startBlockCount > 0
                    ? self::DEFAULT_NEW_START_NODE_TITLE.' '.($startBlockCount + 1)
                    : self::DEFAULT_NEW_START_NODE_TITLE,
                'position_x' => $position['x'],
                'position_y' => $position['y'],
                'settings_payload' => $this->defaultSettingsPayload(
                    self::DEFAULT_START_BLOCK_ID,
                    AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                    self::DEFAULT_START_REPLY_TEXT,
                ),
            ]);

            $this->replaceConditions($block, ['green_start_'.$block->id], AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD);
            $this->replaceStartEdge($lockedVersion, $block, self::DEFAULT_START_BLOCK_ID);
            $this->persistBuilderSchema($lockedVersion);

            return $block->fresh(['channels', 'conditions', 'outgoingEdges']);
        });
    }

    public function deleteStartBlock(ScenarioVersion $version, int $blockId): void
    {
        DB::transaction(function () use ($version, $blockId): void {
            $lockedVersion = ScenarioVersion::query()
                ->whereKey($version->id)
                ->lockForUpdate()
                ->firstOrFail();

            $primaryBlock = $this->ensureStartBlock($lockedVersion);
            $block = $this->findStartBlock($lockedVersion, $blockId);

            if ((int) $block->id === (int) $primaryBlock->id) {
                throw ValidationException::withMessages([
                    'scenario_builder_block' => 'Основное стартовое условие нельзя удалить.',
                ]);
            }

            if ($block->incomingEdges()->exists()) {
                throw ValidationException::withMessages([
                    'scenario_builder_block' => 'Нельзя удалить блок, на который уже ведут связи.',
                ]);
            }

            $block->delete();
            $this->persistBuilderSchema($lockedVersion);
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ScenarioBuilderBlock>
     */
    public function startBlocks(ScenarioVersion $version): \Illuminate\Database\Eloquent\Collection
    {
        $this->ensureStartBlock($version);

        return $version->builderBlocks()
            ->where('type', ScenarioBuilderBlock::TYPE_START_CONDITION)
            ->with(['channels', 'conditions', 'outgoingEdges'])
            ->orderBy('id')
            ->get();
    }

    public function findStartBlock(ScenarioVersion $version, int $blockId): ScenarioBuilderBlock
    {
        return $version->builderBlocks()
            ->whereKey($blockId)
            ->where('type', ScenarioBuilderBlock::TYPE_START_CONDITION)
            ->with(['channels', 'conditions', 'outgoingEdges'])
            ->firstOrFail();
    }

    public function isPrimaryStartBlock(ScenarioVersion $version, int $blockId): bool
    {
        return (int) $this->ensureStartBlock($version)->id === $blockId;
    }

    /**
     * @param  array{x?: int|float|string, y?: int|float|string}  $position
     */
    public function moveStartBlock(ScenarioVersion $version, int $blockId, array $position): ScenarioBuilderBlock
    {
        return DB::transaction(function () use ($version, $blockId, $position): ScenarioBuilderBlock {
            $lockedVersion = ScenarioVersion::query()
                ->whereKey($version->id)
                ->lockForUpdate()
                ->firstOrFail();

            $block = $this->findStartBlock($lockedVersion, $blockId);
            $normalizedPosition = $this->normalizePosition($position);

            $block->forceFill([
                'position_x' => $normalizedPosition['x'],
                'position_y' => $normalizedPosition['y'],
            ])->save();

            $this->persistBuilderSchema($lockedVersion);

            return $block->fresh(['channels', 'conditions', 'outgoingEdges']);
        });
    }

    /**
     * @param  list<array{value: string}>  $triggerRows
     * @param  array{x?: int|float|string, y?: int|float|string}  $position
     */
    public function saveStartBlock(
        ScenarioVersion $version,
        string $title,
        array $channelIds,
        array $position,
        array $triggerRows,
        string $conditionMatch,
        string $replyText,
        string $startBlockId,
        ?int $blockId = null,
    ): ScenarioVersion {
        return DB::transaction(function () use ($version, $title, $channelIds, $position, $triggerRows, $conditionMatch, $replyText, $startBlockId, $blockId): ScenarioVersion {
            $lockedVersion = ScenarioVersion::query()
                ->whereKey($version->id)
                ->lockForUpdate()
                ->firstOrFail();

            $block = $blockId === null
                ? $this->ensureStartBlock($lockedVersion)
                : $this->findStartBlock($lockedVersion, $blockId);
            $normalizedConditionMatch = $this->normalizeConditionMatch($conditionMatch);
            $normalizedTriggerValues = $this->normalizeTriggerRows($triggerRows, $normalizedConditionMatch);

            $this->guardUniqueTriggerValuesForVersion($lockedVersion, $block, $normalizedTriggerValues);

            $normalizedPosition = $this->normalizePosition($position);
            $normalizedReplyText = $this->normalizeReplyText($replyText);
            $normalizedStartBlockId = $this->normalizeStartBlockId($startBlockId);
            $normalizedChannelIds = $this->normalizeChannelIds($channelIds);

            $block->forceFill([
                'title' => $this->normalizeTitle($title),
                'position_x' => $normalizedPosition['x'],
                'position_y' => $normalizedPosition['y'],
                'settings_payload' => $this->defaultSettingsPayload(
                    $normalizedStartBlockId,
                    $normalizedConditionMatch,
                    $normalizedReplyText,
                ),
            ])->save();

            $block->channels()->sync($normalizedChannelIds);
            $this->replaceConditions($block, $normalizedTriggerValues, $normalizedConditionMatch);
            $this->replaceStartEdge($lockedVersion, $block, $normalizedStartBlockId);

            return $this->persistBuilderSchema($lockedVersion);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schemaPayloadWithBuilderProjection(ScenarioVersion $version): array
    {
        $schemaPayload = $this->schemaPayload($version);
        data_set($schemaPayload, 'builder_schema', $this->buildBuilderSchema($version));

        return $schemaPayload;
    }

    public function copyBuilderVersion(ScenarioVersion $sourceVersion, ScenarioVersion $targetVersion): void
    {
        if ($targetVersion->builderBlocks()->exists()) {
            return;
        }

        $sourceBlocks = $sourceVersion->builderBlocks()
            ->with(['channels', 'conditions', 'outgoingEdges'])
            ->get();

        if ($sourceBlocks->isEmpty()) {
            return;
        }

        $blockIdMap = [];

        foreach ($sourceBlocks as $sourceBlock) {
            /** @var ScenarioBuilderBlock $sourceBlock */
            $targetBlock = ScenarioBuilderBlock::query()->create([
                'scenario_version_id' => $targetVersion->id,
                'type' => $sourceBlock->type,
                'title' => $sourceBlock->title,
                'position_x' => $sourceBlock->position_x,
                'position_y' => $sourceBlock->position_y,
                'settings_payload' => $sourceBlock->settings_payload ?? [],
            ]);

            $blockIdMap[$sourceBlock->id] = $targetBlock->id;
            $targetBlock->channels()->sync($sourceBlock->channels->pluck('id')->map(fn ($id): int => (int) $id)->all());

            foreach ($sourceBlock->conditions as $sourceCondition) {
                /** @var ScenarioBuilderCondition $sourceCondition */
                ScenarioBuilderCondition::query()->create([
                    'scenario_builder_block_id' => $targetBlock->id,
                    'type' => $sourceCondition->type,
                    'match_operator' => $sourceCondition->match_operator,
                    'variable' => $sourceCondition->variable,
                    'value' => $sourceCondition->value,
                    'sort_order' => $sourceCondition->sort_order,
                ]);
            }
        }

        foreach ($sourceVersion->builderEdges()->get() as $sourceEdge) {
            /** @var ScenarioBuilderEdge $sourceEdge */
            $fromBlockId = $blockIdMap[$sourceEdge->from_scenario_builder_block_id] ?? null;

            if ($fromBlockId === null) {
                continue;
            }

            ScenarioBuilderEdge::query()->create([
                'scenario_version_id' => $targetVersion->id,
                'from_scenario_builder_block_id' => $fromBlockId,
                'to_scenario_builder_block_id' => $sourceEdge->to_scenario_builder_block_id !== null
                    ? ($blockIdMap[$sourceEdge->to_scenario_builder_block_id] ?? null)
                    : null,
                'to_runtime_block_id' => $sourceEdge->to_runtime_block_id,
                'condition_payload' => $sourceEdge->condition_payload ?? [],
                'sort_order' => $sourceEdge->sort_order,
            ]);
        }
    }

    /**
     * @return array{
     *     version: int,
     *     blocks: array<int, array<string, mixed>>,
     *     edges: list<array<string, mixed>>
     * }
     */
    public function buildBuilderSchema(ScenarioVersion $version): array
    {
        $startBlocks = $this->startBlocks($version);
        $primaryBlockId = (int) ($startBlocks->first()?->id ?? 0);
        $blocks = [];
        $edges = [];

        foreach ($startBlocks as $block) {
            /** @var ScenarioBuilderBlock $block */
            $conditions = $block->conditions
                ->map(fn (ScenarioBuilderCondition $condition): array => [
                    'id' => $condition->id,
                    'type' => $condition->type,
                    'match' => $condition->match_operator,
                    'variable' => $condition->variable,
                    'value' => $condition->value,
                ])
                ->values()
                ->all();

            $conditionValues = array_map(
                fn (array $condition): string => (string) $condition['value'],
                $conditions,
            );
            $conditionMatch = $this->conditionMatch($block);
            $startEdge = $block->outgoingEdges->first();
            $startBlockId = $startEdge instanceof ScenarioBuilderEdge && filled($startEdge->to_runtime_block_id)
                ? (string) $startEdge->to_runtime_block_id
                : $this->normalizeStartBlockId(data_get($block->settings_payload, 'start_block_id'));
            $replyText = $this->replyText($block);

            $blocks[$block->id] = [
                'id' => $block->id,
                'type' => $block->type,
                'title' => $block->title,
                'channel_ids' => $this->channelIds($block),
                'is_primary' => (int) $block->id === $primaryBlockId,
                'position' => [
                    'x' => $block->position_x,
                    'y' => $block->position_y,
                ],
                'conditions' => $conditions,
                'settings' => [
                    'condition' => [
                        'type' => ScenarioBuilderCondition::TYPE_MESSAGE_PARAMETER,
                        'match' => $conditionMatch,
                        'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
                        'values' => $conditionValues,
                    ],
                    'start_block_id' => $startBlockId,
                    'message_text' => $replyText,
                ],
                ...self::START_BUILDER_BLOCK_SECTIONS,
                'message_text' => $replyText,
            ];

            $edges[] = [
                'id' => $startEdge instanceof ScenarioBuilderEdge ? $startEdge->id : null,
                'from' => $block->id,
                'to' => $startEdge instanceof ScenarioBuilderEdge && $startEdge->to_scenario_builder_block_id !== null
                    ? $startEdge->to_scenario_builder_block_id
                    : $startBlockId,
                'condition' => null,
                'delay' => null,
            ];
        }

        return [
            'version' => self::BUILDER_SCHEMA_VERSION,
            'blocks' => $blocks,
            'edges' => $edges,
        ];
    }

    /**
     * @return list<array{value: string}>
     */
    public function triggerRows(ScenarioBuilderBlock $block): array
    {
        $block->loadMissing('conditions');

        $rows = $block->conditions
            ->map(fn (ScenarioBuilderCondition $condition): array => [
                'value' => (string) $condition->value,
            ])
            ->values()
            ->all();

        return $rows === [] ? [['value' => '']] : $rows;
    }

    public function startBlockId(ScenarioBuilderBlock $block): string
    {
        $block->loadMissing('outgoingEdges');

        $edge = $block->outgoingEdges->first();

        if ($edge instanceof ScenarioBuilderEdge && filled($edge->to_runtime_block_id)) {
            return (string) $edge->to_runtime_block_id;
        }

        return $this->normalizeStartBlockId(data_get($block->settings_payload, 'start_block_id'));
    }

    public function conditionMatch(ScenarioBuilderBlock $block): string
    {
        $block->loadMissing('conditions');

        return $this->normalizeConditionMatch(
            $block->conditions->first()?->match_operator
                ?? data_get($block->settings_payload, 'condition.match'),
        );
    }

    public function replyText(ScenarioBuilderBlock $block): string
    {
        return $this->normalizeReplyText(data_get($block->settings_payload, 'message_text'));
    }

    /**
     * @return list<int>
     */
    public function channelIds(ScenarioBuilderBlock $block): array
    {
        $block->loadMissing('channels');

        return $block->channels
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array{x: int, y: int}
     */
    public function position(ScenarioBuilderBlock $block): array
    {
        return [
            'x' => (int) $block->position_x,
            'y' => (int) $block->position_y,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaPayload(ScenarioVersion $version): array
    {
        return is_array($version->schema_payload) ? $version->schema_payload : [];
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     * @return array<string, mixed>
     */
    private function legacyStartBuilderBlock(array $schemaPayload): array
    {
        $blocks = data_get($schemaPayload, 'builder_schema.blocks');

        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (
                    is_array($block)
                    && ($block['type'] ?? null) === ScenarioBuilderBlock::TYPE_START_CONDITION
                ) {
                    return $block;
                }
            }
        }

        $legacyBlock = data_get($schemaPayload, 'builder_schema.blocks.start');

        return is_array($legacyBlock) ? $legacyBlock : [];
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     * @param  array<string, mixed>  $legacyBlock
     * @return list<string>
     */
    private function extractTriggerValues(array $schemaPayload, array $legacyBlock): array
    {
        $builderValues = data_get($legacyBlock, 'settings.condition.values');

        if (is_array($builderValues)) {
            return array_values(array_filter(array_map(
                fn (mixed $value): string => trim((string) $value),
                $builderValues,
            )));
        }

        $triggers = $schemaPayload['triggers'] ?? null;

        if (! is_array($triggers)) {
            return [];
        }

        $values = [];

        foreach ($triggers as $trigger) {
            if (
                is_array($trigger)
                && ($trigger['type'] ?? null) === 'parameter'
                && is_string($trigger['value'] ?? null)
                && trim((string) $trigger['value']) !== ''
            ) {
                $values[] = trim((string) $trigger['value']);
            }
        }

        return $values;
    }

    /**
     * @param  list<array{value: string}>  $rows
     * @return list<string>
     */
    private function normalizeTriggerRows(array $rows, string $conditionMatch): array
    {
        if ($this->normalizeConditionMatch($conditionMatch) === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND) {
            return [];
        }

        $values = [];
        $seen = [];

        foreach (array_values($rows) as $row) {
            $value = trim((string) ($row['value'] ?? ''));

            if ($value === '') {
                throw ValidationException::withMessages([
                    'draft_start_triggers' => 'Значение trigger-а не может быть пустым.',
                ]);
            }

            if (array_key_exists($value, $seen)) {
                throw ValidationException::withMessages([
                    'draft_start_triggers' => 'Trigger-ы не должны повторяться.',
                ]);
            }

            $seen[$value] = true;
            $values[] = $value;
        }

        if ($values === []) {
            throw ValidationException::withMessages([
                'draft_start_triggers' => 'Добавьте хотя бы один trigger запуска.',
            ]);
        }

        return $values;
    }

    /**
     * @param  list<string>  $values
     */
    private function guardUniqueTriggerValuesForVersion(
        ScenarioVersion $version,
        ScenarioBuilderBlock $block,
        array $values,
    ): void {
        if ($values === []) {
            return;
        }

        $duplicateExists = ScenarioBuilderCondition::query()
            ->whereIn('value', $values)
            ->whereHas('builderBlock', function ($query) use ($version, $block): void {
                $query
                    ->where('scenario_version_id', $version->id)
                    ->whereKeyNot($block->id);
            })
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'draft_start_triggers' => 'Trigger-ы должны быть уникальны внутри сценария.',
            ]);
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function replaceConditions(ScenarioBuilderBlock $block, array $values, string $conditionMatch): void
    {
        $block->conditions()->delete();
        $normalizedConditionMatch = $this->normalizeConditionMatch($conditionMatch);

        foreach (array_values($values) as $index => $value) {
            ScenarioBuilderCondition::query()->create([
                'scenario_builder_block_id' => $block->id,
                'type' => ScenarioBuilderCondition::TYPE_MESSAGE_PARAMETER,
                'match_operator' => $normalizedConditionMatch,
                'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
                'value' => $value,
                'sort_order' => $index + 1,
            ]);
        }
    }

    private function replaceStartEdge(ScenarioVersion $version, ScenarioBuilderBlock $block, string $startBlockId): void
    {
        $block->outgoingEdges()->delete();

        ScenarioBuilderEdge::query()->create([
            'scenario_version_id' => $version->id,
            'from_scenario_builder_block_id' => $block->id,
            'to_scenario_builder_block_id' => null,
            'to_runtime_block_id' => $startBlockId,
            'condition_payload' => [],
            'sort_order' => 1,
        ]);
    }

    private function persistBuilderSchema(ScenarioVersion $version): ScenarioVersion
    {
        $freshVersion = $version->fresh(['builderBlocks.channels', 'builderBlocks.conditions', 'builderEdges']);
        $schemaPayload = $this->schemaPayload($freshVersion);
        data_set($schemaPayload, 'builder_schema', $this->buildBuilderSchema($freshVersion));

        $freshVersion->forceFill([
            'schema_payload' => $schemaPayload,
        ])->save();

        return $freshVersion->fresh(['builderBlocks.channels', 'builderBlocks.conditions', 'builderEdges']);
    }

    /**
     * @return array{x: int, y: int}
     */
    private function normalizePosition(mixed $position): array
    {
        if (! is_array($position)) {
            return self::DEFAULT_START_NODE_POSITION;
        }

        $x = is_numeric($position['x'] ?? null)
            ? (int) round((float) $position['x'])
            : self::DEFAULT_START_NODE_POSITION['x'];
        $y = is_numeric($position['y'] ?? null)
            ? (int) round((float) $position['y'])
            : self::DEFAULT_START_NODE_POSITION['y'];

        return [
            'x' => min(max($x, 24), 1400),
            'y' => min(max($y, 24), 1000),
        ];
    }

    private function normalizeTitle(mixed $title): string
    {
        $normalizedTitle = is_string($title) ? trim($title) : '';

        if ($normalizedTitle === '') {
            return self::DEFAULT_START_NODE_TITLE;
        }

        return mb_substr($normalizedTitle, 0, 80);
    }

    private function normalizeConditionMatch(mixed $value): string
    {
        $normalizedMatch = is_string($value) ? trim($value) : '';

        return match ($normalizedMatch) {
            'exact' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'contains' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
            'starts_with', 'ends_with' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            default => array_key_exists($normalizedMatch, AutoReplyRule::matchScopeOptions())
                ? $normalizedMatch
                : AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
        };
    }

    private function normalizeReplyText(mixed $value): string
    {
        $replyText = is_string($value) ? trim($value) : '';

        return $replyText !== '' ? $replyText : self::DEFAULT_START_REPLY_TEXT;
    }

    /**
     * @param  list<int|string>  $channelIds
     * @return list<int>
     */
    private function normalizeChannelIds(array $channelIds): array
    {
        $normalizedChannelIds = collect($channelIds)
            ->map(fn (mixed $channelId): int => (int) $channelId)
            ->filter(fn (int $channelId): bool => $channelId > 0)
            ->unique()
            ->values()
            ->all();

        if ($normalizedChannelIds === []) {
            throw ValidationException::withMessages([
                'draft_start_channel_ids' => 'Выберите хотя бы один канал, для которого работает стартовое условие.',
            ]);
        }

        $availableChannelCount = Channel::query()
            ->whereIn('id', $normalizedChannelIds)
            ->where('is_active', true)
            ->count();

        if ($availableChannelCount !== count($normalizedChannelIds)) {
            throw ValidationException::withMessages([
                'draft_start_channel_ids' => 'Один или несколько выбранных каналов недоступны.',
            ]);
        }

        return $normalizedChannelIds;
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     * @param  array<string, mixed>  $legacyBlock
     */
    private function extractReplyText(array $schemaPayload, array $legacyBlock, string $startBlockId): string
    {
        $builderReplyText = data_get($legacyBlock, 'settings.message_text', data_get($legacyBlock, 'message_text'));

        if (is_string($builderReplyText) && trim($builderReplyText) !== '') {
            return $this->normalizeReplyText($builderReplyText);
        }

        return $this->normalizeReplyText(data_get($schemaPayload, "blocks.{$startBlockId}.text"));
    }

    private function normalizeStartBlockId(mixed $value): string
    {
        $startBlockId = is_string($value) ? trim($value) : '';

        return $startBlockId !== '' ? $startBlockId : self::DEFAULT_START_BLOCK_ID;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSettingsPayload(
        string $startBlockId,
        string $conditionMatch,
        string $replyText,
    ): array
    {
        $normalizedReplyText = $this->normalizeReplyText($replyText);

        return [
            'start_block_id' => $startBlockId,
            'condition' => [
                'type' => ScenarioBuilderCondition::TYPE_MESSAGE_PARAMETER,
                'match' => $this->normalizeConditionMatch($conditionMatch),
                'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
            ],
            ...self::START_BUILDER_BLOCK_SECTIONS,
            'message_text' => $normalizedReplyText,
        ];
    }
}
