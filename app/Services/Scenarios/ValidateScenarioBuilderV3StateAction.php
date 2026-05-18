<?php

namespace App\Services\Scenarios;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class ValidateScenarioBuilderV3StateAction
{
    private const MAX_BLOCKS = 100;

    private const MAX_EDGES = 200;

    private const MAX_MODULES_PER_BLOCK = 3;

    private const MAX_BUTTONS_PER_BLOCK = 20;

    private const MAX_MESSAGE_LENGTH = 4000;

    private const MAX_PAYLOAD_BYTES = 1048576;

    private const MODULE_TYPES = ['start_condition', 'message', 'buttons'];

    private const BLOCK_KINDS = ['state', 'non_state'];

    private const MATCH_EXACT_CALLBACK = 'exact_callback';

    private const START_MATCH_OPERATORS = [
        AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
        AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
        AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
        AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
        AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
        self::MATCH_EXACT_CALLBACK,
        'strict',
        'contains',
        'starts',
        'starts_with',
        'regex',
    ];

    private const CONTACT_PHONE_CONDITIONS = [
        '',
        AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
        AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
    ];

    private const BUTTON_TYPES = ['text', 'request_phone'];

    private const BUTTON_PLACEMENTS = ['auto', 'reply_keyboard', 'inline_message'];

    private const EDGE_MODES = ['wait_reply', 'automatic', 'button'];

    private const EDGE_MATCH_TYPES = [
        'any_inbound',
        'exact_text',
        'contains_text',
        AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
        AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
        self::MATCH_EXACT_CALLBACK,
    ];

    private const EDGE_CAPTURE_DATA_TYPES = ['any_text', 'phone', 'email', 'number'];

    private const EDGE_CAPTURE_FIELD_SCOPES = ['dialog', 'contact'];

    private const EDGE_CONTACT_CAPTURE_DATA_TYPES = [
        'phone' => 'phone',
        'first_name' => 'any_text',
        'last_name' => 'any_text',
        'country' => 'any_text',
        'city' => 'any_text',
        'gender' => 'any_text',
        'age_years' => 'number',
        'age_range' => 'any_text',
    ];

    private const MAX_TRANSITION_LIMIT = 100000;

    private const EDGE_DELAY_UNITS = ['sec', 'min'];

    private const EDGE_DELAY_TYPES = ['immediate', 'relative', 'scheduled'];

    private const MAX_EDGE_DELAY_VALUE = 100000;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        $this->guardPayloadSize($input);

        $builder = $this->arrayValue($input['builder'] ?? null, 'builder');

        if ((int) ($builder['schema_version'] ?? 0) !== BuildScenarioBuilderV3StateAction::SCHEMA_VERSION) {
            $this->fail('builder.schema_version', 'V3 builder must use schema_version = 3.');
        }

        $blocks = $this->normalizeBlocks($builder['blocks'] ?? []);
        $edges = $this->normalizeEdges($builder['edges'] ?? [], $blocks);

        return [
            'draft_version_id' => $this->integerValue($input['draft_version_id'] ?? $input['editable_version_id'] ?? null, 'draft_version_id'),
            'base_revision' => $this->stringValue($input['base_revision'] ?? null, 'base_revision'),
            'builder' => [
                'schema_version' => BuildScenarioBuilderV3StateAction::SCHEMA_VERSION,
                'active_sheet_id' => (string) ($builder['active_sheet_id'] ?? 'main'),
                'sheets' => $this->normalizeSheets($builder['sheets'] ?? []),
                'blocks' => $blocks,
                'edges' => $edges,
                'visible_scope' => $this->normalizeVisibleScope($builder['visible_scope'] ?? []),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeBlocks(mixed $blocks): array
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            $this->fail('builder.blocks', 'Builder blocks must be a list.');
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            $this->fail('builder.blocks', 'Builder blocks limit exceeded.');
        }

        $clientKeys = [];
        $normalizedBlocks = [];

        foreach ($blocks as $index => $block) {
            $block = $this->arrayValue($block, "builder.blocks.$index");
            $clientKey = $this->stringValue($block['client_key'] ?? null, "builder.blocks.$index.client_key");

            if (isset($clientKeys[$clientKey])) {
                $this->fail("builder.blocks.$index.client_key", 'Block client_key must be unique.');
            }

            $clientKeys[$clientKey] = true;

            $settingsPayload = $this->normalizeSettingsPayload(
                $this->arrayValue($block['settings_payload'] ?? null, "builder.blocks.$index.settings_payload"),
                $index,
            );

            $normalizedBlocks[] = [
                'id' => $this->nullableIntegerValue($block['id'] ?? null, "builder.blocks.$index.id"),
                'client_key' => $clientKey,
                'type' => 'state',
                'title' => $this->optionalStringValue($block['title'] ?? 'Блок', "builder.blocks.$index.title", 'Блок'),
                'position' => $this->normalizePosition($block['position'] ?? [], $index),
                'settings_payload' => $settingsPayload,
            ];
        }

        return $normalizedBlocks;
    }

    /**
     * @param  array<string, mixed>  $settingsPayload
     * @return array<string, mixed>
     */
    private function normalizeSettingsPayload(array $settingsPayload, int $blockIndex): array
    {
        if ((int) ($settingsPayload['schema_version'] ?? 0) !== BuildScenarioBuilderV3StateAction::SCHEMA_VERSION) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.schema_version", 'Block settings_payload must use schema_version = 3.');
        }

        $modules = $this->normalizeModules($settingsPayload['modules'] ?? [], $blockIndex);
        $outputs = $this->normalizeOutputs($settingsPayload['outputs'] ?? [], $blockIndex);
        $kind = $this->optionalStringValue(
            $settingsPayload['kind'] ?? 'state',
            "builder.blocks.$blockIndex.settings_payload.kind",
            'state',
        );
        $buttonCount = $this->buttonCount($modules);
        $messageText = $this->messageText($modules);

        if (! in_array($kind, self::BLOCK_KINDS, true)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.kind", 'Unknown block kind.');
        }

        if ($buttonCount > 0 && trim($messageText) === '') {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules", 'Buttons require non-empty message text.');
        }

        return [
            'schema_version' => BuildScenarioBuilderV3StateAction::SCHEMA_VERSION,
            'kind' => $kind,
            'ui' => $this->normalizeBlockUi($settingsPayload['ui'] ?? [], $blockIndex),
            'modules' => $modules,
            'outputs' => $outputs,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeModules(mixed $modules, int $blockIndex): array
    {
        if (! is_array($modules) || ! array_is_list($modules)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules", 'Block modules must be a list.');
        }

        if (count($modules) > self::MAX_MODULES_PER_BLOCK) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules", 'Too many modules in block.');
        }

        $types = [];
        $normalizedModules = [];

        foreach ($modules as $moduleIndex => $module) {
            $module = $this->arrayValue($module, "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex");
            $type = $this->stringValue($module['type'] ?? null, "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.type");

            if (! in_array($type, self::MODULE_TYPES, true)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.type", 'Unknown module type.');
            }

            if (isset($types[$type])) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.type", 'Module type must be unique in block.');
            }

            $types[$type] = true;
            $payload = $this->arrayValue($module['payload'] ?? [], "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload");

            $normalizedModules[] = [
                'id' => $this->optionalStringValue($module['id'] ?? 'mod_'.$type, "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.id", 'mod_'.$type),
                'type' => $type,
                'enabled' => (bool) ($module['enabled'] ?? true),
                'payload' => $this->normalizeModulePayload($type, $payload, $blockIndex, $moduleIndex),
            ];
        }

        return $normalizedModules;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeModulePayload(string $type, array $payload, int $blockIndex, int $moduleIndex): array
    {
        return match ($type) {
            'start_condition' => $this->normalizeStartConditionPayload($payload, $blockIndex, $moduleIndex),
            'message' => $this->normalizeMessagePayload($payload, $blockIndex, $moduleIndex),
            'buttons' => $this->normalizeButtonsPayload($payload, $blockIndex, $moduleIndex),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeStartConditionPayload(array $payload, int $blockIndex, int $moduleIndex): array
    {
        $channelIds = collect(data_get($payload, 'channels.ids', []))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($channelIds !== []) {
            $existingChannelCount = Channel::query()->whereKey($channelIds)->count();

            if ($existingChannelCount !== count($channelIds)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.channels.ids", 'Unknown channel id.');
            }
        }

        $match = $this->normalizeStartConditionMatch($payload['match'] ?? null, $blockIndex, $moduleIndex);

        return [
            'command' => (string) ($payload['command'] ?? ''),
            'values' => $this->stringList($payload['values'] ?? []),
            'match' => $match,
            'variable' => (string) ($payload['variable'] ?? ''),
            'exclude' => (string) ($payload['exclude'] ?? ''),
            'contact_phone_condition' => $this->normalizeContactPhoneCondition(
                $payload['contact_phone_condition'] ?? '',
                $blockIndex,
                $moduleIndex,
            ),
            'priority' => (int) ($payload['priority'] ?? 10),
            'once' => (bool) ($payload['once'] ?? false),
            'channels' => [
                'mode' => (string) data_get($payload, 'channels.mode', 'selected'),
                'ids' => $channelIds,
            ],
        ];
    }

    private function normalizeStartConditionMatch(mixed $value, int $blockIndex, int $moduleIndex): string
    {
        $match = is_string($value) && trim($value) !== ''
            ? trim($value)
            : AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD;

        if (! in_array($match, self::START_MATCH_OPERATORS, true)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.match", 'Unknown start condition match.');
        }

        return $match;
    }

    private function normalizeContactPhoneCondition(mixed $value, int $blockIndex, int $moduleIndex): string
    {
        $condition = is_string($value) ? trim($value) : '';

        if (! in_array($condition, self::CONTACT_PHONE_CONDITIONS, true)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.contact_phone_condition", 'Unknown phone condition.');
        }

        return $condition;
    }

    private function normalizeEdgeContactPhoneCondition(mixed $value, int $edgeIndex): string
    {
        $condition = is_string($value) ? trim($value) : '';

        if (! in_array($condition, self::CONTACT_PHONE_CONDITIONS, true)) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.contact_phone_condition", 'Unknown phone condition.');
        }

        return $condition;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeMessagePayload(array $payload, int $blockIndex, int $moduleIndex): array
    {
        $text = (string) ($payload['text'] ?? '');

        if (mb_strlen($text) > self::MAX_MESSAGE_LENGTH) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.text", 'Message text is too long.');
        }

        return [
            'text' => $text,
            'text_format' => (string) ($payload['text_format'] ?? 'plain_text'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeButtonsPayload(array $payload, int $blockIndex, int $moduleIndex): array
    {
        $rows = $payload['rows'] ?? [];

        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.rows", 'Button rows must be a list.');
        }

        $buttonIds = [];
        $buttonCount = 0;
        $normalizedRows = [];

        foreach ($rows as $rowIndex => $row) {
            if (! is_array($row) || ! array_is_list($row)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.rows.$rowIndex", 'Button row must be a list.');
            }

            $normalizedRow = [];

            foreach ($row as $buttonIndex => $button) {
                $button = $this->arrayValue($button, "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.rows.$rowIndex.$buttonIndex");
                $buttonId = $this->stringValue($button['id'] ?? null, "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.rows.$rowIndex.$buttonIndex.id");
                $buttonText = trim((string) ($button['text'] ?? ''));

                if ($buttonText === '') {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.rows.$rowIndex.$buttonIndex.text", 'Button text cannot be empty.');
                }

                if (isset($buttonIds[$buttonId])) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.rows.$rowIndex.$buttonIndex.id", 'Button id must be unique in block.');
                }

                $buttonIds[$buttonId] = true;
                $buttonCount++;

                if ($buttonCount > self::MAX_BUTTONS_PER_BLOCK) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.rows", 'Too many buttons in block.');
                }

                $normalizedRow[] = [
                    'id' => $buttonId,
                    'text' => $buttonText,
                    'type' => $this->normalizeButtonType($button['type'] ?? null, $blockIndex, $moduleIndex, $rowIndex, $buttonIndex),
                    'fn' => (string) ($button['fn'] ?? 'default'),
                    'url' => filled($button['url'] ?? null) ? (string) $button['url'] : null,
                    'color' => filled($button['color'] ?? null) ? (string) $button['color'] : null,
                ];
            }

            $normalizedRows[] = $normalizedRow;
        }

        return [
            'placement' => $this->normalizeButtonPlacement($payload['placement'] ?? null, $blockIndex, $moduleIndex),
            'rows' => $normalizedRows,
        ];
    }

    private function normalizeButtonPlacement(mixed $placement, int $blockIndex, int $moduleIndex): string
    {
        $placement = trim((string) ($placement ?: 'auto'));

        if (! in_array($placement, self::BUTTON_PLACEMENTS, true)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.placement", 'Unknown button placement.');
        }

        return $placement;
    }

    private function normalizeButtonType(mixed $type, int $blockIndex, int $moduleIndex, int $rowIndex, int $buttonIndex): string
    {
        $type = trim((string) ($type ?: 'text'));

        if (! in_array($type, self::BUTTON_TYPES, true)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.rows.$rowIndex.$buttonIndex.type", 'Unknown button type.');
        }

        return $type;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeOutputs(mixed $outputs, int $blockIndex): array
    {
        if (! is_array($outputs) || ! array_is_list($outputs)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.outputs", 'Block outputs must be a list.');
        }

        $outputIds = [];
        $normalizedOutputs = [];

        foreach ($outputs as $outputIndex => $output) {
            $output = $this->arrayValue($output, "builder.blocks.$blockIndex.settings_payload.outputs.$outputIndex");
            $outputId = $this->stringValue($output['id'] ?? null, "builder.blocks.$blockIndex.settings_payload.outputs.$outputIndex.id");

            if (strlen($outputId) > 64 || ! preg_match('/^[A-Za-z0-9_-]+$/', $outputId)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.outputs.$outputIndex.id", 'Invalid output id.');
            }

            if (isset($outputIds[$outputId])) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.outputs.$outputIndex.id", 'Output id must be unique in block.');
            }

            $outputIds[$outputId] = true;
            $normalizedOutputs[] = [
                'id' => $outputId,
                'label' => (string) ($output['label'] ?? $outputId),
                'source' => (string) ($output['source'] ?? 'button'),
                'module_id' => filled($output['module_id'] ?? null) ? (string) $output['module_id'] : null,
                'button_id' => filled($output['button_id'] ?? null) ? (string) $output['button_id'] : null,
                'button_type' => in_array(($output['button_type'] ?? null), self::BUTTON_TYPES, true)
                    ? (string) $output['button_type']
                    : 'text',
            ];
        }

        return $normalizedOutputs;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function normalizeEdges(mixed $edges, array $blocks): array
    {
        if (! is_array($edges) || ! array_is_list($edges)) {
            $this->fail('builder.edges', 'Builder edges must be a list.');
        }

        if (count($edges) > self::MAX_EDGES) {
            $this->fail('builder.edges', 'Builder edges limit exceeded.');
        }

        $blockKeys = collect($blocks)->keyBy('client_key');
        $blockIds = collect($blocks)
            ->filter(fn (array $block): bool => $block['id'] !== null)
            ->keyBy(fn (array $block): int => (int) $block['id']);
        $clientKeys = [];
        $edgeKeys = [];
        $sourceOutputs = [];
        $normalizedEdges = [];

        foreach ($edges as $index => $edge) {
            $edge = $this->arrayValue($edge, "builder.edges.$index");
            $clientKey = $this->stringValue($edge['client_key'] ?? null, "builder.edges.$index.client_key");

            if (isset($clientKeys[$clientKey])) {
                $this->fail("builder.edges.$index.client_key", 'Edge client_key must be unique.');
            }

            $clientKeys[$clientKey] = true;

            $source = $this->normalizeEndpoint($edge['source'] ?? null, "builder.edges.$index.source", $blockKeys, $blockIds);
            $target = $this->normalizeEndpoint($edge['target'] ?? null, "builder.edges.$index.target", $blockKeys, $blockIds);
            $sourceOutputId = $this->nullableStringValue(data_get($edge, 'source.output_id'), "builder.edges.$index.source.output_id");

            if ($sourceOutputId !== null) {
                $sourceKey = $source['client_key'].'|'.$sourceOutputId;

                if (isset($sourceOutputs[$sourceKey])) {
                    $this->fail("builder.edges.$index.source.output_id", 'Only one edge is allowed from one source output.');
                }

                $sourceOutputs[$sourceKey] = true;
            }

            if ($sourceOutputId !== null && ! $this->blockHasOutput($source['client_key'], $sourceOutputId, $blockKeys)) {
                $this->fail("builder.edges.$index.source.output_id", 'Source output does not exist.');
            }

            $conditionPayload = $this->normalizeConditionPayload(
                $this->arrayValue($edge['condition_payload'] ?? [], "builder.edges.$index.condition_payload"),
                $sourceOutputId,
                $index,
            );

            $edgeKey = $conditionPayload['edge_key'];

            if (is_string($edgeKey) && $edgeKey !== '') {
                if (isset($edgeKeys[$edgeKey])) {
                    $this->fail("builder.edges.$index.condition_payload.edge_key", 'Edge key must be unique.');
                }

                $edgeKeys[$edgeKey] = true;
            }

            $normalizedEdges[] = [
                'id' => $this->nullableIntegerValue($edge['id'] ?? null, "builder.edges.$index.id"),
                'client_key' => $clientKey,
                'source' => $source + ['output_id' => $sourceOutputId],
                'target' => $target,
                'condition_payload' => $conditionPayload,
            ];
        }

        $this->guardDuplicateButtonTextEdges($blocks, $normalizedEdges);

        return $normalizedEdges;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeConditionPayload(array $payload, ?string $sourceOutputId, int $edgeIndex): array
    {
        $edgeKey = $this->normalizeEdgeKey($payload['edge_key'] ?? null, $edgeIndex);
        $mode = $this->normalizeEdgeMode($payload['mode'] ?? null, $sourceOutputId);
        $priority = (int) ($payload['priority'] ?? 10);
        $transitionLimit = $this->nonNegativeIntegerValue(
            $payload['transition_limit'] ?? 0,
            "builder.edges.$edgeIndex.condition_payload.transition_limit",
            self::MAX_TRANSITION_LIMIT,
        );

        return [
            'schema_version' => BuildScenarioBuilderV3StateAction::SCHEMA_VERSION,
            'edge_schema_version' => BuildScenarioBuilderV3StateAction::SCHEMA_VERSION,
            'edge_key' => $edgeKey,
            'from_output_id' => $sourceOutputId,
            'label' => (string) ($payload['label'] ?? ''),
            'mode' => $mode,
            'priority' => $priority,
            'transition_limit' => $transitionLimit,
            'contact_phone_condition' => $this->normalizeEdgeContactPhoneCondition($payload['contact_phone_condition'] ?? '', $edgeIndex),
            'match' => $this->normalizeEdgeMatch($payload['match'] ?? [], $edgeIndex),
            'input_capture' => $this->normalizeEdgeInputCapture($payload['input_capture'] ?? [], $edgeIndex),
            'delay' => $this->normalizeEdgeDelay($payload['delay'] ?? [], $mode, $edgeIndex),
            'flags' => is_array($payload['flags'] ?? null) ? $payload['flags'] : [],
            'ui' => is_array($payload['ui'] ?? null) ? $payload['ui'] : [],
        ];
    }

    private function normalizeEdgeKey(mixed $value, int $edgeIndex): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $edgeKey = trim((string) $value);

        if (strlen($edgeKey) > 64 || ! preg_match('/^[A-Za-z0-9_-]+$/', $edgeKey)) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.edge_key", 'Invalid edge key.');
        }

        return $edgeKey;
    }

    private function normalizeEdgeMode(mixed $mode, ?string $sourceOutputId): string
    {
        $mode = trim((string) $mode);

        if ($mode === '') {
            return $sourceOutputId !== null ? 'button' : 'automatic';
        }

        return in_array($mode, self::EDGE_MODES, true) ? $mode : 'wait_reply';
    }

    /**
     * @return array{type: string, value: int, unit: string, scheduled_at: string|null, cancel_if_left_source_block: bool}
     */
    private function normalizeEdgeDelay(mixed $delay, string $mode, int $edgeIndex): array
    {
        $delay = is_array($delay) ? $delay : [];

        if ($mode !== 'automatic') {
            return [
                'type' => 'immediate',
                'value' => 0,
                'unit' => 'sec',
                'scheduled_at' => null,
                'cancel_if_left_source_block' => true,
            ];
        }

        $type = trim((string) ($delay['type'] ?? ''));
        $value = $this->nonNegativeIntegerValue(
            $delay['value'] ?? 0,
            "builder.edges.$edgeIndex.condition_payload.delay.value",
            self::MAX_EDGE_DELAY_VALUE,
        );
        $unit = trim((string) ($delay['unit'] ?? 'sec'));
        $type = $type !== '' ? $type : ($value > 0 ? 'relative' : 'immediate');

        if (! in_array($type, self::EDGE_DELAY_TYPES, true)) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.delay.type", 'Invalid delay type.');
        }

        if (! in_array($unit, self::EDGE_DELAY_UNITS, true)) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.delay.unit", 'Invalid delay unit.');
        }

        if ($type === 'scheduled') {
            $scheduledAt = $this->normalizeScheduledAt(
                $delay['scheduled_at'] ?? null,
                "builder.edges.$edgeIndex.condition_payload.delay.scheduled_at",
            );

            return [
                'type' => 'scheduled',
                'value' => 0,
                'unit' => 'sec',
                'scheduled_at' => $scheduledAt,
                'cancel_if_left_source_block' => (bool) ($delay['cancel_if_left_source_block'] ?? true),
            ];
        }

        if ($value === 0) {
            $unit = 'sec';
            $type = 'immediate';
        } else {
            $type = 'relative';
        }

        return [
            'type' => $type,
            'value' => $value,
            'unit' => $unit,
            'scheduled_at' => null,
            'cancel_if_left_source_block' => (bool) ($delay['cancel_if_left_source_block'] ?? true),
        ];
    }

    private function normalizeScheduledAt(mixed $value, string $key): string
    {
        $scheduledAt = trim((string) $value);

        if ($scheduledAt === '') {
            $this->fail($key, 'Scheduled date-time is required.');
        }

        try {
            return CarbonImmutable::parse($scheduledAt, config('app.timezone', 'UTC'))->toIso8601String();
        } catch (Throwable) {
            $this->fail($key, 'Invalid scheduled date-time.');
        }
    }

    /**
     * @return array{type: string, text: string}
     */
    private function normalizeEdgeMatch(mixed $match, int $edgeIndex): array
    {
        $match = is_array($match) ? $match : [];
        $type = trim((string) ($match['type'] ?? 'any_inbound'));

        if (! in_array($type, self::EDGE_MATCH_TYPES, true)) {
            $type = match ($type) {
                'strict', AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD => 'exact_text',
                'contains', AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT => 'contains_text',
                'exact' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
                default => 'any_inbound',
            };
        }

        $text = (string) ($match['text'] ?? $match['value'] ?? '');

        if (mb_strlen($text) > self::MAX_MESSAGE_LENGTH) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.match.text", 'Edge match text is too long.');
        }

        return [
            'type' => $type,
            'text' => $text,
        ];
    }

    /**
     * @return array{enabled: bool, field_scope: string, field_key: string, data_type: string}
     */
    private function normalizeEdgeInputCapture(mixed $capture, int $edgeIndex): array
    {
        $capture = is_array($capture) ? $capture : [];
        $enabled = (bool) ($capture['enabled'] ?? false);

        if (! $enabled) {
            return [
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'data_type' => 'any_text',
            ];
        }

        $fieldScope = trim((string) ($capture['field_scope'] ?? 'dialog'));
        $fieldKey = trim((string) ($capture['field_key'] ?? ''));
        $dataType = trim((string) ($capture['data_type'] ?? 'any_text'));

        if (! in_array($fieldScope, self::EDGE_CAPTURE_FIELD_SCOPES, true)) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.input_capture.field_scope", 'Unknown capture field scope.');
        }

        if (! in_array($dataType, self::EDGE_CAPTURE_DATA_TYPES, true)) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.input_capture.data_type", 'Unknown capture data type.');
        }

        if ($fieldScope === 'dialog') {
            if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $fieldKey)) {
                $this->fail("builder.edges.$edgeIndex.condition_payload.input_capture.field_key", 'Invalid dialog field key.');
            }

            return [
                'enabled' => true,
                'field_scope' => $fieldScope,
                'field_key' => $fieldKey,
                'data_type' => $dataType,
            ];
        }

        $expectedDataType = self::EDGE_CONTACT_CAPTURE_DATA_TYPES[$fieldKey] ?? null;

        if ($expectedDataType === null) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.input_capture.field_key", 'Unknown contact field key.');
        }

        if ($dataType !== $expectedDataType) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.input_capture.data_type", 'Capture data type does not match contact field.');
        }

        return [
            'enabled' => true,
            'field_scope' => $fieldScope,
            'field_key' => $fieldKey,
            'data_type' => $dataType,
        ];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $blockKeys
     * @param  Collection<int, array<string, mixed>>  $blockIds
     * @return array{block_id: int|null, client_key: string}
     */
    private function normalizeEndpoint(mixed $endpoint, string $key, $blockKeys, $blockIds): array
    {
        $endpoint = $this->arrayValue($endpoint, $key);
        $clientKey = filled($endpoint['client_key'] ?? null) ? (string) $endpoint['client_key'] : null;
        $blockId = $this->nullableIntegerValue($endpoint['block_id'] ?? null, $key.'.block_id');

        if ($clientKey !== null && $blockKeys->has($clientKey)) {
            $block = $blockKeys->get($clientKey);

            return [
                'block_id' => $block['id'],
                'client_key' => $clientKey,
            ];
        }

        if ($blockId !== null && $blockIds->has($blockId)) {
            $block = $blockIds->get($blockId);

            return [
                'block_id' => $blockId,
                'client_key' => (string) $block['client_key'],
            ];
        }

        $this->fail($key, 'Edge endpoint block does not exist.');
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $blockKeys
     */
    private function blockHasOutput(string $clientKey, string $outputId, $blockKeys): bool
    {
        $block = $blockKeys->get($clientKey);
        $outputs = data_get($block, 'settings_payload.outputs', []);

        return collect(is_array($outputs) ? $outputs : [])
            ->contains(fn (array $output): bool => ($output['id'] ?? null) === $outputId);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $edges
     */
    private function guardDuplicateButtonTextEdges(array $blocks, array $edges): void
    {
        $edgeTargetsByOutput = collect($edges)
            ->filter(fn (array $edge): bool => data_get($edge, 'source.output_id') !== null)
            ->mapWithKeys(fn (array $edge): array => [
                data_get($edge, 'source.client_key').'|'.data_get($edge, 'source.output_id') => data_get($edge, 'target.client_key'),
            ]);

        foreach ($blocks as $blockIndex => $block) {
            $buttonsById = $this->buttonsById(data_get($block, 'settings_payload.modules', []));
            $textTargets = [];

            foreach (data_get($block, 'settings_payload.outputs', []) as $output) {
                $buttonId = $output['button_id'] ?? null;

                if (! is_string($buttonId) || ! isset($buttonsById[$buttonId])) {
                    continue;
                }

                $target = $edgeTargetsByOutput->get($block['client_key'].'|'.$output['id']);

                if ($target === null) {
                    continue;
                }

                $text = $this->normalizeButtonText($buttonsById[$buttonId]['text']);

                if ($text === '') {
                    continue;
                }

                if (isset($textTargets[$text]) && $textTargets[$text] !== $target) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules", 'Same button text cannot lead to different edges in one block.');
                }

                $textTargets[$text] = $target;
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buttonsById(mixed $modules): array
    {
        $buttonsModule = collect(is_array($modules) ? $modules : [])->firstWhere('type', 'buttons');
        $buttons = [];

        foreach (data_get($buttonsModule, 'payload.rows', []) as $row) {
            foreach (is_array($row) ? $row : [] as $button) {
                if (is_array($button) && isset($button['id'])) {
                    $buttons[(string) $button['id']] = $button;
                }
            }
        }

        return $buttons;
    }

    private function normalizeButtonText(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text));
    }

    /**
     * @return array{x: int, y: int}
     */
    private function normalizePosition(mixed $position, int $blockIndex): array
    {
        $position = $this->arrayValue($position, "builder.blocks.$blockIndex.position");
        $x = (int) ($position['x'] ?? 64);
        $y = (int) ($position['y'] ?? 64);

        if ($x < -100000 || $x > 100000 || $y < -100000 || $y > 100000) {
            $this->fail("builder.blocks.$blockIndex.position", 'Block position is out of bounds.');
        }

        return ['x' => $x, 'y' => $y];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeBlockUi(mixed $ui, int $blockIndex): array
    {
        $ui = is_array($ui) ? $ui : [];

        return [
            'sheet_id' => (string) ($ui['sheet_id'] ?? 'main'),
            'width' => (int) ($ui['width'] ?? 320),
            'collapsed' => (bool) ($ui['collapsed'] ?? false),
            'card_id' => $this->optionalStringValue($ui['card_id'] ?? '', "builder.blocks.$blockIndex.settings_payload.ui.card_id", ''),
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
                    'id' => 'main',
                    'name' => 'Главный',
                    'color' => 'none',
                    'view' => ['tx' => 0, 'ty' => 0, 'zoom' => 1],
                ],
            ];
        }

        return collect($sheets)
            ->filter(fn (mixed $sheet): bool => is_array($sheet))
            ->map(fn (array $sheet): array => [
                'id' => (string) ($sheet['id'] ?? 'main'),
                'name' => (string) ($sheet['name'] ?? 'Главный'),
                'color' => (string) ($sheet['color'] ?? 'none'),
                'view' => [
                    'tx' => (float) data_get($sheet, 'view.tx', 0),
                    'ty' => (float) data_get($sheet, 'view.ty', 0),
                    'zoom' => (float) data_get($sheet, 'view.zoom', 1),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{block_ids: list<int>, edge_ids: list<int>}
     */
    private function normalizeVisibleScope(mixed $visibleScope): array
    {
        $visibleScope = is_array($visibleScope) ? $visibleScope : [];

        return [
            'block_ids' => collect($visibleScope['block_ids'] ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all(),
            'edge_ids' => collect($visibleScope['edge_ids'] ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  mixed  $modules
     */
    private function buttonCount($modules): int
    {
        return collect($modules)
            ->where('type', 'buttons')
            ->sum(fn (array $module): int => collect(data_get($module, 'payload.rows', []))
                ->sum(fn (mixed $row): int => is_array($row) ? count($row) : 0));
    }

    /**
     * @param  mixed  $modules
     */
    private function messageText($modules): string
    {
        $module = collect($modules)->firstWhere('type', 'message');

        return (string) data_get($module, 'payload.text', '');
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    private function guardPayloadSize(array $input): void
    {
        $encoded = json_encode($input);

        if ($encoded === false || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            $this->fail('builder', 'Builder snapshot is too large.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            $this->fail($key, 'Value must be an object.');
        }

        return $value;
    }

    private function integerValue(mixed $value, string $key): int
    {
        $integer = (int) $value;

        if ($integer <= 0) {
            $this->fail($key, 'Value must be a positive integer.');
        }

        return $integer;
    }

    private function nullableIntegerValue(mixed $value, string $key): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $integer = (int) $value;

        if ($integer <= 0) {
            $this->fail($key, 'Value must be a positive integer.');
        }

        return $integer;
    }

    private function nonNegativeIntegerValue(mixed $value, string $key, int $max): int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (
            ! is_int($value)
            && ! (is_string($value) && preg_match('/^\d+$/', $value))
        ) {
            $this->fail($key, 'Value must be a non-negative integer.');
        }

        $integer = (int) $value;

        if ($integer < 0 || $integer > $max) {
            $this->fail($key, "Value must be between 0 and {$max}.");
        }

        return $integer;
    }

    private function stringValue(mixed $value, string $key): string
    {
        $string = trim((string) $value);

        if ($string === '') {
            $this->fail($key, 'Value is required.');
        }

        return $string;
    }

    private function optionalStringValue(mixed $value, string $key, string $fallback): string
    {
        $string = trim((string) $value);

        if ($string === '') {
            return $fallback;
        }

        if (mb_strlen($string) > 255) {
            $this->fail($key, 'Value is too long.');
        }

        return $string;
    }

    private function nullableStringValue(mixed $value, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '') {
            return null;
        }

        if (strlen($string) > 64 || ! preg_match('/^[A-Za-z0-9_-]+$/', $string)) {
            $this->fail($key, 'Invalid output id.');
        }

        return $string;
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
