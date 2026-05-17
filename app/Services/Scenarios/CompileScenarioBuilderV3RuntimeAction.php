<?php

namespace App\Services\Scenarios;

use App\Models\AutoReplyRule;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioBuilderCondition;
use App\Models\ScenarioBuilderEdge;
use App\Models\ScenarioVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CompileScenarioBuilderV3RuntimeAction
{
    private const BUTTON_PLACEMENTS = ['auto', 'reply_keyboard', 'inline_message'];

    private const EDGE_MODE_WAIT_REPLY = 'wait_reply';

    /**
     * @return array<string, mixed>
     */
    public function handle(ScenarioVersion $version, string $sourceRevision): array
    {
        $blocks = $version->builderBlocks()
            ->with(['channels', 'conditions'])
            ->orderBy('id')
            ->get();

        if ($blocks->isEmpty()) {
            $this->fail('builder.blocks', 'Нельзя опубликовать пустой V3-конструктор.');
        }

        $edges = $version->builderEdges()
            ->whereNotNull('to_scenario_builder_block_id')
            ->with(['fromBuilderBlock', 'toBuilderBlock'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $runtimeBlockIdsByDbId = $blocks
            ->mapWithKeys(fn (ScenarioBuilderBlock $block): array => [
                (int) $block->id => $this->runtimeBlockId($block),
            ])
            ->all();

        $this->guardUniqueRuntimeBlockIds($runtimeBlockIdsByDbId);

        $edgesBySource = $edges->groupBy(fn (ScenarioBuilderEdge $edge): int => (int) $edge->from_scenario_builder_block_id);
        $runtimeBlocks = [];

        foreach ($blocks as $block) {
            /** @var ScenarioBuilderBlock $block */
            $runtimeBlockId = $runtimeBlockIdsByDbId[(int) $block->id];
            $runtimeBlocks[$runtimeBlockId] = $this->compileBlock(
                $block,
                $runtimeBlockId,
                $edgesBySource->get((int) $block->id, collect()),
                $runtimeBlockIdsByDbId,
            );
        }

        $entrypoints = $this->compileEntrypoints($blocks, $runtimeBlockIdsByDbId);

        if ($entrypoints === []) {
            $this->fail('builder.start_condition', 'Для публикации нужен хотя бы один стартовый блок с каналом и фразой.');
        }

        return [
            'schema_version' => BuildScenarioBuilderV3StateAction::SCHEMA_VERSION,
            'source_revision' => $sourceRevision,
            'compiled_at' => CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'entrypoints' => $entrypoints,
            'blocks' => $runtimeBlocks,
            'edges' => $edges
                ->map(fn (ScenarioBuilderEdge $edge): array => $this->compileEdge($edge, $runtimeBlockIdsByDbId))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, ScenarioBuilderEdge>  $outgoingEdges
     * @param  array<int, string>  $runtimeBlockIdsByDbId
     * @return array<string, mixed>
     */
    private function compileBlock(
        ScenarioBuilderBlock $block,
        string $runtimeBlockId,
        Collection $outgoingEdges,
        array $runtimeBlockIdsByDbId,
    ): array {
        $settings = $this->settingsPayload($block);
        $message = $this->module($settings, 'message');
        $buttons = $this->module($settings, 'buttons');
        $defaultEdge = $outgoingEdges->first(
            fn (ScenarioBuilderEdge $edge): bool => $this->edgeOutputId($edge) === null
                && $this->edgeMode($edge) !== self::EDGE_MODE_WAIT_REPLY,
        );

        $compiled = [
            'id' => $runtimeBlockId,
            'card_id' => $runtimeBlockId,
            'db_id' => (int) $block->id,
            'kind' => $this->blockKind($settings),
            'title' => (string) $block->title,
            'message' => $message !== null ? [
                'text' => (string) data_get($message, 'payload.text', ''),
                'text_format' => (string) data_get($message, 'payload.text_format', 'plain_text'),
            ] : null,
            'buttons' => null,
            'wait_reply_edges' => $this->compileWaitReplyEdges($outgoingEdges, $runtimeBlockIdsByDbId),
            'default_target_block_id' => $defaultEdge instanceof ScenarioBuilderEdge
                ? ($runtimeBlockIdsByDbId[(int) $defaultEdge->to_scenario_builder_block_id] ?? null)
                : null,
        ];

        if ($buttons !== null) {
            $compiled['buttons'] = [
                'placement' => $this->buttonPlacement($buttons),
                'rows' => $this->compileButtonRows($buttons, $outgoingEdges, $runtimeBlockIdsByDbId),
            ];
        }

        if (
            $compiled['message'] === null
            && $compiled['buttons'] === null
            && $compiled['wait_reply_edges'] === []
            && $compiled['default_target_block_id'] === null
        ) {
            $this->fail('builder.blocks', "Блок {$block->title} не содержит действия и не ведёт дальше.");
        }

        return $compiled;
    }

    /**
     * @param  Collection<int, ScenarioBuilderEdge>  $outgoingEdges
     * @param  array<int, string>  $runtimeBlockIdsByDbId
     * @return list<array<string, mixed>>
     */
    private function compileWaitReplyEdges(Collection $outgoingEdges, array $runtimeBlockIdsByDbId): array
    {
        return $outgoingEdges
            ->filter(fn (ScenarioBuilderEdge $edge): bool => $this->edgeOutputId($edge) === null
                && $this->edgeMode($edge) === self::EDGE_MODE_WAIT_REPLY)
            ->map(fn (ScenarioBuilderEdge $edge): array => $this->compileEdge($edge, $runtimeBlockIdsByDbId))
            ->sort(fn (array $left, array $right): int => $this->compareWaitReplyEdges($left, $right))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $buttons
     * @param  Collection<int, ScenarioBuilderEdge>  $outgoingEdges
     * @param  array<int, string>  $runtimeBlockIdsByDbId
     * @return list<list<array<string, mixed>>>
     */
    private function compileButtonRows(array $buttons, Collection $outgoingEdges, array $runtimeBlockIdsByDbId): array
    {
        $rows = data_get($buttons, 'payload.rows', []);

        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => collect($row)
                ->filter(fn (mixed $button): bool => is_array($button))
                ->map(function (array $button) use ($outgoingEdges, $runtimeBlockIdsByDbId): array {
                    $buttonId = (string) ($button['id'] ?? '');
                    $edge = $outgoingEdges->first(
                        fn (ScenarioBuilderEdge $edge): bool => $this->edgeOutputId($edge) === $buttonId,
                    );
                    $text = trim((string) ($button['text'] ?? ''));

                    if ($text === '' && $edge instanceof ScenarioBuilderEdge) {
                        $this->fail('builder.buttons', 'У кнопки со связью должен быть текст перед публикацией.');
                    }

                    return [
                        'id' => $buttonId,
                        'text' => $text,
                        'type' => $this->buttonType($button),
                        'normalized_text' => $this->normalizeButtonText($text),
                        'output_id' => $buttonId,
                        'target_block_id' => $edge instanceof ScenarioBuilderEdge
                            ? ($runtimeBlockIdsByDbId[(int) $edge->to_scenario_builder_block_id] ?? null)
                            : null,
                    ];
                })
                ->values()
                ->all())
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $button
     */
    private function buttonType(array $button): string
    {
        return ($button['type'] ?? null) === 'request_phone' ? 'request_phone' : 'text';
    }

    /**
     * @param  array<string, mixed>  $buttons
     */
    private function buttonPlacement(array $buttons): string
    {
        $placement = (string) data_get($buttons, 'payload.placement', 'auto');

        return in_array($placement, self::BUTTON_PLACEMENTS, true) ? $placement : 'auto';
    }

    /**
     * @param  Collection<int, ScenarioBuilderBlock>  $blocks
     * @param  array<int, string>  $runtimeBlockIdsByDbId
     * @return list<array<string, mixed>>
     */
    private function compileEntrypoints(Collection $blocks, array $runtimeBlockIdsByDbId): array
    {
        return $blocks
            ->map(function (ScenarioBuilderBlock $block) use ($runtimeBlockIdsByDbId): ?array {
                $start = $this->module($this->settingsPayload($block), 'start_condition');

                if ($start === null) {
                    return null;
                }

                $channelIds = $block->channels
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all();
                $values = $block->conditions
                    ->map(fn (ScenarioBuilderCondition $condition): string => trim((string) $condition->value))
                    ->filter(fn (string $value): bool => $value !== '')
                    ->unique()
                    ->values()
                    ->all();
                $match = (string) ($block->conditions->first()?->match_operator ?? data_get($start, 'payload.match', 'strict'));

                if ($match === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND && $values === []) {
                    $values = [''];
                }

                if ($channelIds === [] || $values === []) {
                    return null;
                }

                return [
                    'block_id' => $runtimeBlockIdsByDbId[(int) $block->id] ?? $this->runtimeBlockId($block),
                    'db_block_id' => (int) $block->id,
                    'channel_ids' => $channelIds,
                    'match' => $match,
                    'values' => $values,
                    'contact_phone_condition' => $this->normalizeContactPhoneCondition(
                        data_get($start, 'payload.contact_phone_condition', ''),
                    ),
                    'priority' => (int) data_get($start, 'payload.priority', 10),
                ];
            })
            ->filter()
            ->sort(fn (array $left, array $right): int => $this->compareEntrypoints($left, $right))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareEntrypoints(array $left, array $right): int
    {
        return [
            (int) ($right['priority'] ?? 10),
            $this->entrypointBlockOrder($right),
            (string) ($right['block_id'] ?? ''),
        ] <=> [
            (int) ($left['priority'] ?? 10),
            $this->entrypointBlockOrder($left),
            (string) ($left['block_id'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     */
    private function entrypointBlockOrder(array $entrypoint): int
    {
        $blockId = $entrypoint['block_id'] ?? null;

        return is_numeric($blockId) ? (int) $blockId : PHP_INT_MIN;
    }

    private function normalizeContactPhoneCondition(mixed $condition): string
    {
        $condition = is_string($condition) ? trim($condition) : '';

        return in_array($condition, [
            AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
        ], true) ? $condition : '';
    }

    /**
     * @param  array<int, string>  $runtimeBlockIdsByDbId
     * @return array<string, mixed>
     */
    private function compileEdge(ScenarioBuilderEdge $edge, array $runtimeBlockIdsByDbId): array
    {
        $conditionPayload = is_array($edge->condition_payload) ? $edge->condition_payload : [];
        $sourceDbId = (int) $edge->from_scenario_builder_block_id;
        $targetDbId = (int) $edge->to_scenario_builder_block_id;

        return [
            'id' => (string) $edge->id,
            'edge_key' => (string) ($conditionPayload['edge_key'] ?? ''),
            'mode' => $this->edgeMode($edge),
            'priority' => (int) ($conditionPayload['priority'] ?? 10),
            'transition_limit' => max(0, (int) ($conditionPayload['transition_limit'] ?? 0)),
            'source_block_id' => $runtimeBlockIdsByDbId[$sourceDbId] ?? (string) $sourceDbId,
            'target_block_id' => $runtimeBlockIdsByDbId[$targetDbId] ?? (string) $targetDbId,
            'source_db_block_id' => $sourceDbId,
            'target_db_block_id' => $targetDbId,
            'from_output_id' => $this->edgeOutputId($edge),
            'label' => (string) ($conditionPayload['label'] ?? ''),
            'match' => $this->compileEdgeMatch($conditionPayload),
            'input_capture' => $this->compileEdgeInputCapture($conditionPayload),
        ];
    }

    /**
     * @param  array<string, mixed>  $conditionPayload
     * @return array{type: string, text: string, variants: list<string>}
     */
    private function compileEdgeMatch(array $conditionPayload): array
    {
        $match = is_array($conditionPayload['match'] ?? null) ? $conditionPayload['match'] : [];
        $type = (string) ($match['type'] ?? 'any_inbound');
        $text = (string) ($match['text'] ?? '');
        $variants = $this->normalizedMatchVariants($text);

        if (in_array($type, ['exact_text', 'contains_text'], true) && $variants === []) {
            $this->fail('builder.edges', 'У стрелки с текстовым условием должен быть текст условия.');
        }

        return [
            'type' => in_array($type, ['any_inbound', 'exact_text', 'contains_text'], true) ? $type : 'any_inbound',
            'text' => $text,
            'variants' => $variants,
        ];
    }

    /**
     * @param  array<string, mixed>  $conditionPayload
     * @return array{enabled: bool, field_scope: string, field_key: string, data_type: string}
     */
    private function compileEdgeInputCapture(array $conditionPayload): array
    {
        $capture = is_array($conditionPayload['input_capture'] ?? null) ? $conditionPayload['input_capture'] : [];

        return [
            'enabled' => (bool) ($capture['enabled'] ?? false),
            'field_scope' => 'dialog',
            'field_key' => (string) ($capture['field_key'] ?? ''),
            'data_type' => (string) ($capture['data_type'] ?? 'any_text'),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private function module(array $settings, string $type): ?array
    {
        $module = collect($settings['modules'] ?? [])
            ->first(fn (mixed $module): bool => is_array($module)
                && ($module['type'] ?? null) === $type
                && (bool) ($module['enabled'] ?? true));

        return is_array($module) ? $module : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(ScenarioBuilderBlock $block): array
    {
        return is_array($block->settings_payload) ? $block->settings_payload : [];
    }

    private function runtimeBlockId(ScenarioBuilderBlock $block): string
    {
        $cardId = trim((string) data_get($this->settingsPayload($block), 'ui.card_id', ''));

        return $cardId !== '' ? $cardId : (string) $block->id;
    }

    /**
     * @param  array<int, string>  $runtimeBlockIdsByDbId
     */
    private function guardUniqueRuntimeBlockIds(array $runtimeBlockIdsByDbId): void
    {
        $duplicates = collect($runtimeBlockIdsByDbId)
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->values()
            ->all();

        if ($duplicates !== []) {
            $this->fail('builder.blocks', 'В сценарии есть блоки с одинаковым стабильным ID: #'.implode(', #', $duplicates).'.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function blockKind(array $settings): string
    {
        return ($settings['kind'] ?? null) === 'non_state' ? 'non_state' : 'state';
    }

    private function edgeOutputId(ScenarioBuilderEdge $edge): ?string
    {
        $outputId = data_get($edge->condition_payload, 'from_output_id');

        return is_string($outputId) && trim($outputId) !== '' ? trim($outputId) : null;
    }

    private function edgeMode(ScenarioBuilderEdge $edge): string
    {
        $mode = data_get($edge->condition_payload, 'mode');

        return is_string($mode) && trim($mode) !== '' ? trim($mode) : 'automatic';
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareWaitReplyEdges(array $left, array $right): int
    {
        return [
            (int) ($right['priority'] ?? 10),
            (int) ($right['id'] ?? 0),
        ] <=> [
            (int) ($left['priority'] ?? 10),
            (int) ($left['id'] ?? 0),
        ];
    }

    private function normalizeButtonText(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text));
    }

    /**
     * @return list<string>
     */
    private function normalizedMatchVariants(string $text): array
    {
        return collect(preg_split('/\R/u', $text) ?: [])
            ->map(fn (string $line): string => $this->normalizeButtonText($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
