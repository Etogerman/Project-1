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
use Throwable;

class CompileScenarioBuilderV3RuntimeAction
{
    private const BUTTON_PLACEMENTS = ['auto', 'reply_keyboard', 'inline_message'];

    private const EDGE_MODE_WAIT_REPLY = 'wait_reply';

    private const EDGE_MODE_AUTOMATIC = 'automatic';

    private const EDGE_MODE_AI_ANALYSIS = 'ai_analysis';

    private const EDGE_MODE_ACTION_RESULT = 'action_result';

    private const EDGE_MATCH_EXACT_CALLBACK = 'exact_callback';

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
        $runtimeBlockIdsByClientKey = $blocks
            ->mapWithKeys(fn (ScenarioBuilderBlock $block): array => [
                'block_'.$block->id => $this->runtimeBlockId($block),
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
                $runtimeBlockIdsByClientKey,
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
        array $runtimeBlockIdsByClientKey,
    ): array {
        $settings = $this->settingsPayload($block);
        $message = $this->module($settings, 'message');
        $buttons = $this->module($settings, 'buttons');
        $ai = $this->module($settings, 'ai');
        $action = $this->module($settings, 'action');
        $automaticEdges = $this->compileAutomaticEdges($outgoingEdges, $runtimeBlockIdsByDbId);

        $compiled = [
            'id' => $runtimeBlockId,
            'card_id' => $runtimeBlockId,
            'display_number' => $this->displayNumber($settings),
            'db_id' => (int) $block->id,
            'kind' => $this->blockKind($settings),
            'title' => (string) $block->title,
            'message' => $message !== null ? $this->compileMessage($message) : null,
            'ai_analysis' => $ai !== null
                ? $this->compileAiAnalysis($ai, $outgoingEdges, $runtimeBlockIdsByDbId)
                : null,
            'actions' => $action !== null
                ? $this->compileActions($action, $runtimeBlockIdsByClientKey)
                : [],
            'action_result_edges' => $action !== null
                ? $this->compileActionResultEdges($outgoingEdges, $runtimeBlockIdsByDbId)
                : [],
            'buttons' => null,
            'wait_reply_edges' => $this->compileWaitReplyEdges($outgoingEdges, $runtimeBlockIdsByDbId),
            'automatic_edges' => $automaticEdges,
            'default_target_block_id' => $automaticEdges[0]['target_block_id'] ?? null,
        ];

        if ($buttons !== null) {
            $compiled['buttons'] = [
                'placement' => $this->buttonPlacement($buttons),
                'rows' => $this->compileButtonRows($buttons, $outgoingEdges, $runtimeBlockIdsByDbId),
            ];
        }

        if (
            $compiled['message'] === null
            && $compiled['ai_analysis'] === null
            && $compiled['actions'] === []
            && $compiled['action_result_edges'] === []
            && $compiled['buttons'] === null
            && $compiled['wait_reply_edges'] === []
            && $compiled['automatic_edges'] === []
            && $compiled['default_target_block_id'] === null
        ) {
            $this->fail('builder.blocks', "Блок {$block->title} не содержит действия и не ведёт дальше.");
        }

        return $compiled;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function compileMessage(array $message): array
    {
        return [
            'text' => (string) data_get($message, 'payload.text', ''),
            'text_format' => (string) data_get($message, 'payload.text_format', 'plain_text'),
            'text_mode' => (string) data_get($message, 'payload.text_mode', 'static'),
            'variable_key' => (string) data_get($message, 'payload.variable_key', ''),
            'variable_text_variants' => collect(data_get($message, 'payload.variable_text_variants', []))
                ->filter(fn (mixed $variant): bool => is_array($variant))
                ->map(fn (array $variant): array => [
                    'operator' => (string) ($variant['operator'] ?? 'eq'),
                    'value' => (string) ($variant['value'] ?? ''),
                    'text' => (string) ($variant['text'] ?? ''),
                ])
                ->filter(fn (array $variant): bool => trim($variant['value']) !== '')
                ->values()
                ->all(),
            'fallback_text' => (string) data_get($message, 'payload.fallback_text', ''),
        ];
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
            ->sort(fn (array $left, array $right): int => $this->comparePriorityEdges($left, $right))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ScenarioBuilderEdge>  $outgoingEdges
     * @param  array<int, string>  $runtimeBlockIdsByDbId
     * @return list<array<string, mixed>>
     */
    private function compileAutomaticEdges(Collection $outgoingEdges, array $runtimeBlockIdsByDbId): array
    {
        return $outgoingEdges
            ->filter(fn (ScenarioBuilderEdge $edge): bool => $this->edgeOutputId($edge) === null
                && $this->edgeMode($edge) === self::EDGE_MODE_AUTOMATIC)
            ->map(fn (ScenarioBuilderEdge $edge): array => $this->compileEdge($edge, $runtimeBlockIdsByDbId))
            ->sort(fn (array $left, array $right): int => $this->comparePriorityEdges($left, $right))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $ai
     * @param  Collection<int, ScenarioBuilderEdge>  $outgoingEdges
     * @param  array<int, string>  $runtimeBlockIdsByDbId
     * @return array<string, mixed>
     */
    private function compileAiAnalysis(array $ai, Collection $outgoingEdges, array $runtimeBlockIdsByDbId): array
    {
        $outputs = collect(data_get($ai, 'payload.variants', []))
            ->filter(fn (mixed $output): bool => is_array($output))
            ->map(function (array $output, int $index) use ($outgoingEdges, $runtimeBlockIdsByDbId): array {
                $outputId = (string) ($output['id'] ?? '');
                $edge = $outgoingEdges->first(
                    fn (ScenarioBuilderEdge $edge): bool => $this->edgeOutputId($edge) === $outputId
                        && $this->edgeMode($edge) === self::EDGE_MODE_AI_ANALYSIS,
                );
                $compiledEdge = $edge instanceof ScenarioBuilderEdge
                    ? $this->compileEdge($edge, $runtimeBlockIdsByDbId)
                    : null;

                return [
                    'id' => $outputId,
                    'label' => (string) ($output['label'] ?? $outputId),
                    'variant_id' => (string) ($output['ai_variant_id'] ?? $outputId),
                    'choice_id' => (string) ($output['ai_choice_id'] ?? ($index + 1)),
                    'delay_seconds' => max(0, min(300, (int) ($output['delay_seconds'] ?? 0))),
                    'target_block_id' => $compiledEdge['target_block_id'] ?? null,
                    'edge' => $compiledEdge,
                ];
            })
            ->filter(fn (array $output): bool => $output['id'] !== '')
            ->values()
            ->all();

        return [
            'prompt' => (string) data_get($ai, 'payload.prompt', ''),
            'source' => (string) data_get($ai, 'payload.source', 'current_inbound_message'),
            'outputs' => $outputs,
            'extract_fields' => collect(data_get($ai, 'payload.extract_fields', []))
                ->filter(fn (mixed $field): bool => is_array($field))
                ->map(fn (array $field): array => [
                    'key' => (string) ($field['key'] ?? ''),
                    'label' => (string) ($field['label'] ?? ''),
                    'type' => (string) ($field['type'] ?? 'text'),
                ])
                ->filter(fn (array $field): bool => $field['key'] !== '' && $field['label'] !== '')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, string>  $runtimeBlockIdsByClientKey
     * @return list<array<string, mixed>>
     */
    private function compileActions(array $action, array $runtimeBlockIdsByClientKey): array
    {
        return collect(data_get($action, 'payload.actions', []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item) use ($runtimeBlockIdsByClientKey): array {
                $type = (string) ($item['type'] ?? 'write_contact_field');
                $sourceBlockClientKey = trim((string) ($item['source_block_client_key'] ?? ''));

                if ($type === 'check_data') {
                    return [
                        'type' => 'check_data',
                        'source_type' => 'inbound_message',
                        'check_source' => (string) ($item['check_source'] ?? 'current_inbound_message'),
                        'dictionary_key' => (string) ($item['dictionary_key'] ?? 'names'),
                        'lookup_field' => 'lookup_value',
                        'result_field' => 'result_value',
                        'target_variable_key' => (string) ($item['target_variable_key'] ?? ''),
                    ];
                }

                if ($type === 'edit_message') {
                    return [
                        'type' => 'edit_message',
                        'operation' => (string) ($item['operation'] ?? 'remove_buttons'),
                        'target' => (string) ($item['target'] ?? 'last_current_run_outbound_with_inline_buttons'),
                    ];
                }

                if ($type === 'calculate_distance_to_moscow') {
                    return [
                        'type' => 'calculate_distance_to_moscow',
                    ];
                }

                if ($type === 'resolve_geo_city') {
                    $source = (string) ($item['source'] ?? 'current_inbound_message');

                    if ($source === 'ai_data') {
                        return [
                            'type' => $type,
                            'source' => 'ai_data',
                            'source_block_client_key' => $sourceBlockClientKey,
                            'source_block_id' => $sourceBlockClientKey !== ''
                                ? ($runtimeBlockIdsByClientKey[$sourceBlockClientKey] ?? '')
                                : '',
                            'city_field_key' => (string) ($item['city_field_key'] ?? 'geo_city'),
                            'region_field_key' => (string) ($item['region_field_key'] ?? 'geo_region'),
                            'country_field_key' => (string) ($item['country_field_key'] ?? 'geo_country'),
                        ];
                    }

                    return [
                        'type' => $type,
                        'source' => 'current_inbound_message',
                    ];
                }

                if ($type === 'variables') {
                    return [
                        'type' => 'variables',
                        'operations' => collect($item['operations'] ?? [])
                            ->filter(fn (mixed $operation): bool => is_array($operation))
                            ->map(function (array $operation): array {
                                $compiled = [
                                    'operation' => (string) ($operation['operation'] ?? 'set'),
                                    'field_key' => (string) ($operation['field_key'] ?? ''),
                                ];

                                if (($compiled['operation'] ?? null) === 'increment') {
                                    $compiled['amount'] = (int) ($operation['amount'] ?? 1);
                                } elseif (($compiled['operation'] ?? null) === 'set') {
                                    $compiled['value_source'] = (string) ($operation['value_source'] ?? 'static_value');
                                    $compiled['value'] = $operation['value'] ?? '';
                                }

                                return $compiled;
                            })
                            ->filter(fn (array $operation): bool => ($operation['field_key'] ?? '') !== '')
                            ->values()
                            ->all(),
                    ];
                }

                if ($type === 'simulate_start_parameter') {
                    return [
                        'type' => 'simulate_start_parameter',
                        'source_scope' => 'dialog',
                        'source_field_key' => (string) ($item['source_field_key'] ?? ''),
                    ];
                }

                if ($type === 'tag_effects') {
                    return [
                        'type' => 'tag_effects',
                        'assign_tag_ids' => $this->compileIntegerList($item['assign_tag_ids'] ?? []),
                        'remove_tag_ids' => $this->compileIntegerList($item['remove_tag_ids'] ?? []),
                    ];
                }

                return [
                    'type' => $type,
                    'source_type' => (string) ($item['source_type'] ?? 'ai_data'),
                    'source_block_id' => $sourceBlockClientKey !== ''
                        ? ($runtimeBlockIdsByClientKey[$sourceBlockClientKey] ?? '')
                        : '',
                    'source_field_key' => (string) ($item['source_field_key'] ?? ''),
                    'static_value' => (string) ($item['static_value'] ?? ''),
                    'target_scope' => (string) ($item['target_scope'] ?? 'contact'),
                    'target_field' => (string) ($item['target_field'] ?? ''),
                ];
            })
            ->filter(fn (array $item): bool => match ($item['type']) {
                'check_data' => ($item['target_variable_key'] ?? '') !== '',
                'edit_message' => (
                    (($item['operation'] ?? '') === 'remove_buttons'
                        && ($item['target'] ?? '') === 'last_current_run_outbound_with_inline_buttons')
                    || (($item['operation'] ?? '') === 'delete_message'
                        && ($item['target'] ?? '') === 'last_current_run_outbound')
                ),
                'calculate_distance_to_moscow' => true,
                'resolve_geo_city' => ($item['source'] ?? '') === 'current_inbound_message'
                    || (
                        ($item['source'] ?? '') === 'ai_data'
                        && ($item['source_block_id'] ?? '') !== ''
                        && ($item['city_field_key'] ?? '') !== ''
                    ),
                'variables' => ($item['operations'] ?? []) !== [],
                'simulate_start_parameter' => ($item['source_scope'] ?? '') === 'dialog'
                    && ($item['source_field_key'] ?? '') !== '',
                'tag_effects' => (($item['assign_tag_ids'] ?? []) !== [] || ($item['remove_tag_ids'] ?? []) !== []),
                default => (($item['target_field'] ?? '') !== ''
                    && (($item['source_type'] ?? '') === 'static_value'
                        ? trim((string) ($item['static_value'] ?? '')) !== ''
                        : ($item['source_field_key'] ?? '') !== '')),
            })
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function compileIntegerList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->map(fn (mixed $value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ScenarioBuilderEdge>  $outgoingEdges
     * @param  array<int, string>  $runtimeBlockIdsByDbId
     * @return list<array<string, mixed>>
     */
    private function compileActionResultEdges(Collection $outgoingEdges, array $runtimeBlockIdsByDbId): array
    {
        return $outgoingEdges
            ->filter(fn (ScenarioBuilderEdge $edge): bool => $this->edgeOutputId($edge) !== null)
            ->map(function (ScenarioBuilderEdge $edge) use ($runtimeBlockIdsByDbId): array {
                $compiled = $this->compileEdge($edge, $runtimeBlockIdsByDbId);
                $compiled['mode'] = self::EDGE_MODE_ACTION_RESULT;

                return $compiled;
            })
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
                    $buttonType = $this->buttonType($button);
                    $edge = $outgoingEdges->first(
                        fn (ScenarioBuilderEdge $edge): bool => $this->edgeOutputId($edge) === $buttonId,
                    );
                    $text = trim((string) ($button['text'] ?? ''));

                    if ($text === '' && $edge instanceof ScenarioBuilderEdge) {
                        $this->fail('builder.buttons', 'У кнопки со связью должен быть текст перед публикацией.');
                    }

                    if ($buttonType === 'link' && $edge instanceof ScenarioBuilderEdge) {
                        $this->fail('builder.buttons', 'Кнопка-ссылка не может быть источником перехода.');
                    }

                    $compiledEdge = $edge instanceof ScenarioBuilderEdge
                        ? $this->compileEdge($edge, $runtimeBlockIdsByDbId)
                        : null;

                    return [
                        'id' => $buttonId,
                        'text' => $text,
                        'type' => $buttonType,
                        'url' => $buttonType === 'link' ? (string) ($button['url'] ?? '') : null,
                        'normalized_text' => $this->normalizeButtonText($text),
                        'output_id' => $buttonId,
                        'target_block_id' => $compiledEdge['target_block_id'] ?? null,
                        'edge' => $compiledEdge,
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
        return match ($button['type'] ?? null) {
            'request_phone' => 'request_phone',
            'link' => 'link',
            default => 'text',
        };
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
                    'dialog_phone_condition' => $this->normalizeContactPhoneCondition(
                        data_get($start, 'payload.dialog_phone_condition', ''),
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
        $mode = $this->edgeMode($edge);
        $delay = $this->compileEdgeDelay($conditionPayload);

        return [
            'id' => (string) $edge->id,
            'edge_key' => (string) ($conditionPayload['edge_key'] ?? ''),
            'mode' => $mode,
            'priority' => (int) ($conditionPayload['priority'] ?? 10),
            'transition_limit' => max(0, (int) ($conditionPayload['transition_limit'] ?? 0)),
            'source_block_id' => $runtimeBlockIdsByDbId[$sourceDbId] ?? (string) $sourceDbId,
            'target_block_id' => $runtimeBlockIdsByDbId[$targetDbId] ?? (string) $targetDbId,
            'source_db_block_id' => $sourceDbId,
            'target_db_block_id' => $targetDbId,
            'from_output_id' => $this->edgeOutputId($edge),
            'label' => (string) ($conditionPayload['label'] ?? ''),
            'contact_phone_condition' => $this->normalizeContactPhoneCondition(
                $conditionPayload['contact_phone_condition'] ?? '',
            ),
            'dialog_phone_condition' => $this->normalizeContactPhoneCondition(
                $conditionPayload['dialog_phone_condition'] ?? '',
            ),
            'expression' => trim((string) ($conditionPayload['expression'] ?? '')),
            'field_condition' => $this->compileEdgeFieldCondition($conditionPayload),
            'match' => $this->compileEdgeMatch($conditionPayload),
            'transition_actions' => $this->compileEdgeTransitionActions($conditionPayload),
            'delay' => $delay,
            'input_capture' => $mode === self::EDGE_MODE_WAIT_REPLY
                ? $this->compileEdgeInputCapture($conditionPayload)
                : $this->disabledEdgeInputCapture(),
        ];
    }

    /**
     * @param  array<string, mixed>  $conditionPayload
     * @return list<array{type: string, target_scope: string, target_field: string, value_source: string, value: string}>
     */
    private function compileEdgeTransitionActions(array $conditionPayload): array
    {
        return collect(is_array($conditionPayload['transition_actions'] ?? null) ? $conditionPayload['transition_actions'] : [])
            ->filter(fn (mixed $action): bool => is_array($action))
            ->map(fn (array $action): array => [
                'type' => (string) ($action['type'] ?? 'write_field'),
                'target_scope' => (string) ($action['target_scope'] ?? 'contact'),
                'target_field' => (string) ($action['target_field'] ?? ''),
                'value_source' => (string) ($action['value_source'] ?? 'static'),
                'value' => (string) ($action['value'] ?? ''),
            ])
            ->filter(fn (array $action): bool => $action['type'] === 'write_field'
                && in_array($action['target_scope'], ['contact', 'dialog'], true)
                && $action['target_field'] !== ''
                && $action['value_source'] === 'static'
                && trim($action['value']) !== '')
            ->values()
            ->all();
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
        $allowedTypes = [
            'any_inbound',
            'exact_text',
            'contains_text',
            AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
            self::EDGE_MATCH_EXACT_CALLBACK,
        ];

        if (! in_array($type, $allowedTypes, true)) {
            $type = 'any_inbound';
        }

        if ($type !== 'any_inbound' && $variants === []) {
            $this->fail('builder.edges', 'У стрелки с условием должен быть текст условия.');
        }

        return [
            'type' => $type,
            'text' => $text,
            'variants' => $variants,
        ];
    }

    /**
     * @param  array<string, mixed>  $conditionPayload
     * @return array{enabled: bool, field_scope: string, field_key: string, operator: string, value: string}
     */
    private function compileEdgeFieldCondition(array $conditionPayload): array
    {
        $condition = is_array($conditionPayload['field_condition'] ?? null)
            ? $conditionPayload['field_condition']
            : [];

        return [
            'enabled' => (bool) ($condition['enabled'] ?? false),
            'field_scope' => (string) ($condition['field_scope'] ?? 'dialog'),
            'field_key' => (string) ($condition['field_key'] ?? ''),
            'operator' => (string) ($condition['operator'] ?? 'filled'),
            'value' => (string) ($condition['value'] ?? ''),
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
            'field_scope' => (string) ($capture['field_scope'] ?? 'dialog'),
            'field_key' => (string) ($capture['field_key'] ?? ''),
            'data_type' => (string) ($capture['data_type'] ?? 'any_text'),
        ];
    }

    /**
     * @return array{enabled: bool, field_scope: string, field_key: string, data_type: string}
     */
    private function disabledEdgeInputCapture(): array
    {
        return [
            'enabled' => false,
            'field_scope' => 'dialog',
            'field_key' => '',
            'data_type' => 'any_text',
        ];
    }

    /**
     * @param  array<string, mixed>  $conditionPayload
     * @return array{type: string, value: int, unit: string, scheduled_at: string|null, cancel_if_left_source_block: bool}
     */
    private function compileEdgeDelay(array $conditionPayload): array
    {
        $delay = is_array($conditionPayload['delay'] ?? null) ? $conditionPayload['delay'] : [];
        $type = (string) ($delay['type'] ?? '');
        $value = max(0, (int) ($delay['value'] ?? 0));
        $unit = in_array(($delay['unit'] ?? 'sec'), ['sec', 'min'], true) ? (string) $delay['unit'] : 'sec';

        if ($type === 'scheduled') {
            $scheduledAt = $this->compiledScheduledAt($delay['scheduled_at'] ?? null);

            if ($scheduledAt->lessThanOrEqualTo(CarbonImmutable::now())) {
                $this->fail('builder.edges', 'Дата и время automatic-стрелки должны быть в будущем.');
            }

            return [
                'type' => 'scheduled',
                'value' => 0,
                'unit' => 'sec',
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'cancel_if_left_source_block' => (bool) ($delay['cancel_if_left_source_block'] ?? true),
            ];
        }

        if ($value === 0) {
            $unit = 'sec';
        }

        return [
            'type' => $value > 0 ? 'relative' : 'immediate',
            'value' => $value,
            'unit' => $unit,
            'scheduled_at' => null,
            'cancel_if_left_source_block' => (bool) ($delay['cancel_if_left_source_block'] ?? true),
        ];
    }

    private function compiledScheduledAt(mixed $value): CarbonImmutable
    {
        $scheduledAt = trim((string) $value);

        if ($scheduledAt === '') {
            $this->fail('builder.edges', 'Для automatic-стрелки в дату и время нужно указать дату запуска.');
        }

        try {
            return CarbonImmutable::parse($scheduledAt, config('app.timezone', 'UTC'));
        } catch (Throwable) {
            $this->fail('builder.edges', 'Некорректная дата запуска automatic-стрелки.');
        }
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
     * @param  array<string, mixed>  $settings
     */
    private function displayNumber(array $settings): ?string
    {
        $value = data_get($settings, 'ui.display_number');

        if (is_int($value) && $value > 0) {
            return (string) $value;
        }

        $string = trim((string) $value);

        if ($string === '' || ! ctype_digit($string) || (int) $string < 1) {
            return null;
        }

        return (string) ((int) $string);
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
    private function comparePriorityEdges(array $left, array $right): int
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
