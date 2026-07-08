<?php

namespace App\Services\Scenarios;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Tag;
use App\Services\Colors\ColorRegistry;
use App\Services\Dialogs\DialogStageCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class ValidateScenarioBuilderV3StateAction
{
    public function __construct(
        private readonly FieldDictionaryEngineSupport $fieldDictionaryEngineSupport,
        private readonly ColorRegistry $colorRegistry,
    ) {}

    private const MAX_BLOCKS = 500;

    private const MAX_EDGES = 1000;

    private const MAX_MODULES_PER_BLOCK = 5;

    private const MAX_BUTTONS_PER_BLOCK = 20;

    private const MAX_MESSAGE_LENGTH = 4000;

    private const MAX_PAYLOAD_BYTES = 1048576;

    private const MAX_VARIABLE_ACTION_OPERATIONS = 10;

    private const MAX_MESSAGE_VARIABLE_VARIANTS = 20;

    private const MAX_SHEETS = 80;

    private const MAX_SHEET_NAME_LENGTH = 40;

    private const DEFAULT_SHEET_ID = 'main';

    private const DEFAULT_SHEET_NAME = 'Главный';

    private const MODULE_TYPES = ['start_condition', 'message', 'buttons', 'ai', 'action'];

    private const START_PRIORITY_MIN = 1;

    private const START_PRIORITY_MAX = 100;

    private const START_EVENT_MESSAGE = 'message';

    private const START_EVENT_MESSAGE_IN_STAGE = 'message_in_stage';

    private const START_EVENT_STAGE_CHANGED = 'stage_changed';

    private const START_EVENTS = [
        self::START_EVENT_MESSAGE,
        self::START_EVENT_MESSAGE_IN_STAGE,
        self::START_EVENT_STAGE_CHANGED,
    ];

    private const AI_SOURCES = [
        'current_inbound_message',
        'inbound_messages_after_previous_bot_message',
    ];

    private const MAX_AI_VARIANTS = 20;

    private const MAX_AI_EXTRACT_FIELDS = 20;

    private const AI_EXTRACT_FIELD_TYPES = ['text', 'number'];

    private const MAX_AI_VARIANT_DELAY_SECONDS = 300;

    private const AI_FAILED_OUTPUT_ID = 'ai_failed';

    private const MAX_ACTIONS_PER_BLOCK = 20;

    private const ACTION_TYPES = [
        'change_field',
        'write_contact_field',
        'check_data',
        'edit_message',
        'calculate_distance_to_moscow',
        'resolve_geo_city',
        'variables',
        'simulate_start_parameter',
        'tag_effects',
        'complete_data_collection',
        'bitrix24_sync',
    ];

    private const ACTION_RESULT_TYPES = ['check_data', 'calculate_distance_to_moscow', 'resolve_geo_city'];

    private const VARIABLE_OPERATIONS = ['set', 'increment', 'clear'];

    private const GEO_CITY_SOURCES = ['current_inbound_message', 'ai_data'];

    private const GEO_CITY_OUTPUT_NOT_FOUND = 'geo_not_found';

    private const GEO_CITY_OUTPUT_MANUAL_REQUIRED = 'geo_manual_required';

    private const GEO_CITY_OUTPUT_LIMIT_REACHED = 'geo_limit_reached';

    private const GEO_CITY_LEGACY_MANUAL_REQUIRED_OUTPUTS = [
        'geo_manual_required',
        'geo_ambiguous',
        'geo_below_threshold',
        'geo_inactive',
    ];

    private const GEO_CITY_LEGACY_NOT_FOUND_OUTPUTS = [
        'geo_failed',
    ];

    private const VARIABLES_LEGACY_OUTPUTS = [
        'variables_done',
        'variables_failed',
    ];

    private const ACTION_SOURCE_TYPES = ['ai_data', 'inbound_message', 'static_value'];

    private const CHANGE_FIELD_VALUE_SOURCES = ['manual', 'start_parameter', 'ai_result'];

    private const ACTION_CHECK_SOURCES = ['current_inbound_message'];

    private const ACTION_DICTIONARIES = ['names'];

    private const ACTION_EDIT_MESSAGE_OPERATIONS = ['remove_buttons', 'delete_message'];

    private const ACTION_EDIT_MESSAGE_TARGETS = ['last_current_run_outbound_with_inline_buttons', 'last_current_run_outbound'];

    private const VARIABLE_SET_VALUE_SOURCES = ['static_value', 'current_message', 'start_param'];

    private const MAX_TAG_EFFECT_IDS = 20;

    private const MAX_TAG_CONDITION_IDS = 20;

    private const TAG_CONDITION_MODES = ['has_all', 'has_any', 'has_none'];

    private const BITRIX24_SYNC_OPERATIONS = [
        'contact_sync',
        'deal_sync',
        'history_export',
        'contact_sync_with_followups',
    ];

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
    ];

    private const CONTACT_PHONE_CONDITIONS = [
        '',
        AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
        AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
    ];

    private const BUTTON_TYPES = ['text', 'request_phone', 'link'];

    private const BUTTON_PLACEMENTS = ['auto', 'reply_keyboard', 'inline_message'];

    private const EDGE_MODES = ['wait_reply', 'automatic', 'button', 'ai_analysis', 'action_result'];

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

    private const EDGE_FIELD_CONDITION_OPERATORS = ['filled', 'empty', 'equals', 'not_equals'];

    private const EDGE_CONTACT_CAPTURE_DATA_TYPES = [
        'phone' => 'phone',
        'first_name' => 'any_text',
        'last_name' => 'any_text',
        'country' => 'any_text',
        'region' => 'any_text',
        'city' => 'any_text',
        'gender' => 'any_text',
        'age_years' => 'number',
        'age_range' => 'any_text',
    ];

    private const EDGE_CONTACT_FIELD_CONDITION_DATA_TYPES = [
        'phone' => 'phone',
        'emails' => 'email',
        'first_name' => 'any_text',
        'last_name' => 'any_text',
        'country' => 'any_text',
        'region' => 'any_text',
        'city' => 'any_text',
        'gender' => 'any_text',
        'age_years' => 'number',
        'age_range' => 'any_text',
        'first_name_source' => 'any_text',
    ];

    private const MAX_TRANSITION_LIMIT = 100000;

    private const EDGE_DELAY_UNITS = ['sec', 'min'];

    private const EDGE_DELAY_TYPES = ['immediate', 'relative', 'scheduled'];

    private const MAX_EDGE_DELAY_VALUE = 100000;

    private const MAX_EDGE_WAYPOINTS = 5;

    private const MAX_TRANSITION_ACTIONS_PER_EDGE = 5;

    private const EDGE_TRANSITION_ACTION_TYPES = ['write_field'];

    private const EDGE_TRANSITION_ACTION_SCOPES = ['contact', 'dialog'];

    private const EDGE_TRANSITION_ACTION_VALUE_SOURCES = ['static'];

    private const EDGE_TRANSITION_CONTACT_FIELDS = [
        'first_name',
        'last_name',
        'country',
        'region',
        'city',
        'gender',
        'gender_source',
        'age_years',
        'age_range',
        'first_name_source',
        'first_name_resolution_method',
    ];

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

        $sheets = $this->normalizeSheets($builder['sheets'] ?? []);
        $sheetIds = collect($sheets)->pluck('id')->all();
        $activeSheetId = $this->normalizeActiveSheetId($builder['active_sheet_id'] ?? null, $sheetIds);
        $blocks = $this->normalizeBlocks($builder['blocks'] ?? []);
        $this->guardBlockSheetIds($blocks, $sheetIds);
        $this->validateGeoAiDataSources($blocks);

        $edges = $this->normalizeEdges($builder['edges'] ?? [], $blocks);
        $this->guardEdgesWithinSingleSheet($edges, $blocks);

        return [
            'draft_version_id' => $this->integerValue($input['draft_version_id'] ?? $input['editable_version_id'] ?? null, 'draft_version_id'),
            'base_revision' => $this->stringValue($input['base_revision'] ?? null, 'base_revision'),
            'builder' => [
                'schema_version' => BuildScenarioBuilderV3StateAction::SCHEMA_VERSION,
                'active_sheet_id' => $activeSheetId,
                'sheets' => $sheets,
                'meta' => $this->normalizeBuilderMeta($builder['meta'] ?? [], $sheets),
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
            $this->fail('builder.blocks', 'Список блоков конструктора должен быть списком.');
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            $this->fail('builder.blocks', 'Превышен лимит блоков конструктора.');
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
        $outputs = $this->hasResolveGeoCityAction($modules)
            ? $this->geoCityCanonicalOutputs($modules)
            : ($this->hasVariablesAction($modules) ? $this->withoutLegacyVariableOutputs($outputs) : $outputs);
        $outputs = $this->hasAiModuleInModules($modules) ? $this->withAiFailedOutput($outputs) : $outputs;
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
            'ai' => $this->normalizeAiPayload($payload, $blockIndex, $moduleIndex),
            'action' => $this->normalizeActionPayload($payload, $blockIndex, $moduleIndex),
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
        $startEvent = $this->normalizeStartEvent($payload['start_event'] ?? null, $blockIndex, $moduleIndex);
        $stageKey = $this->normalizeStartStageKey($payload['stage_key'] ?? null, $startEvent, $blockIndex, $moduleIndex);

        return [
            'command' => (string) ($payload['command'] ?? ''),
            'start_event' => $startEvent,
            'stage_key' => $stageKey,
            'values' => $this->stringList($payload['values'] ?? []),
            'match' => $match,
            'variable' => (string) ($payload['variable'] ?? ''),
            'exclude' => (string) ($payload['exclude'] ?? ''),
            'expression' => $this->normalizeStartExpression($payload['expression'] ?? '', $blockIndex, $moduleIndex),
            'tag_condition' => $this->normalizeTagCondition(
                $payload['tag_condition'] ?? [],
                "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.tag_condition",
            ),
            'contact_phone_condition' => $this->normalizeContactPhoneCondition(
                $payload['contact_phone_condition'] ?? '',
                $blockIndex,
                $moduleIndex,
                "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.contact_phone_condition",
            ),
            'dialog_phone_condition' => $this->normalizeContactPhoneCondition(
                $payload['dialog_phone_condition'] ?? '',
                $blockIndex,
                $moduleIndex,
                "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.dialog_phone_condition",
            ),
            'priority' => $this->normalizeStartPriority($payload['priority'] ?? 10),
            'once' => (bool) ($payload['once'] ?? false),
            'channels' => [
                'mode' => (string) data_get($payload, 'channels.mode', 'selected'),
                'ids' => $channelIds,
            ],
        ];
    }

    private function normalizeStartPriority(mixed $value): int
    {
        return max(self::START_PRIORITY_MIN, min(self::START_PRIORITY_MAX, (int) $value));
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

    private function normalizeStartEvent(mixed $value, int $blockIndex, int $moduleIndex): string
    {
        $event = is_string($value) && trim($value) !== ''
            ? trim($value)
            : self::START_EVENT_MESSAGE;

        if (! in_array($event, self::START_EVENTS, true)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.start_event", 'Unknown start event.');
        }

        return $event;
    }

    private function normalizeStartStageKey(mixed $value, string $startEvent, int $blockIndex, int $moduleIndex): string
    {
        if (! in_array($startEvent, [self::START_EVENT_MESSAGE_IN_STAGE, self::START_EVENT_STAGE_CHANGED], true)) {
            return '';
        }

        $stageKey = is_string($value) ? trim($value) : '';

        if ($stageKey === '' || ! app(DialogStageCatalog::class)->isWorking($stageKey)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.stage_key", 'Выберите стадию диалога.');
        }

        return $stageKey;
    }

    private function normalizeStartExpression(mixed $expression, int $blockIndex, int $moduleIndex): string
    {
        $expression = app(ScenarioEdgeExpressionCondition::class)->normalize($expression);

        if (mb_strlen($expression) > self::MAX_MESSAGE_LENGTH) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.expression", 'Условие запуска слишком длинное.');
        }

        try {
            app(ScenarioEdgeExpressionCondition::class)->assertValid($expression);
        } catch (Throwable) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.expression", 'Некорректное условие запуска.');
        }

        return $expression;
    }

    private function normalizeContactPhoneCondition(mixed $value, int $blockIndex, int $moduleIndex, ?string $path = null): string
    {
        $condition = is_string($value) ? trim($value) : '';

        if (! in_array($condition, self::CONTACT_PHONE_CONDITIONS, true)) {
            $this->fail($path ?? "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.contact_phone_condition", 'Unknown phone condition.');
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

    private function normalizeEdgeDialogPhoneCondition(mixed $value, int $edgeIndex): string
    {
        $condition = is_string($value) ? trim($value) : '';

        if (! in_array($condition, self::CONTACT_PHONE_CONDITIONS, true)) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.dialog_phone_condition", 'Unknown phone condition.');
        }

        return $condition;
    }

    private function normalizeEdgeExpression(mixed $expression, int $edgeIndex): string
    {
        $expression = app(ScenarioEdgeExpressionCondition::class)->normalize($expression);

        if (mb_strlen($expression) > self::MAX_MESSAGE_LENGTH) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.expression", 'Условие слишком длинное.');
        }

        try {
            app(ScenarioEdgeExpressionCondition::class)->assertValid($expression);
        } catch (Throwable) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.expression", 'Некорректное условие стрелки.');
        }

        return $expression;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeMessagePayload(array $payload, int $blockIndex, int $moduleIndex): array
    {
        $text = (string) ($payload['text'] ?? '');
        $textMode = (string) ($payload['text_mode'] ?? 'static');

        if (mb_strlen($text) > self::MAX_MESSAGE_LENGTH) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.text", 'Message text is too long.');
        }

        if ($textMode !== 'by_dialog_variable') {
            return [
                'text' => $text,
                'text_format' => (string) ($payload['text_format'] ?? 'plain_text'),
                'text_mode' => 'static',
                'variable_key' => '',
                'variable_text_variants' => [],
                'fallback_text' => '',
            ];
        }

        $variableKey = trim((string) ($payload['variable_key'] ?? ''));

        if (! $this->validDialogVariableKey($variableKey)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variable_key", 'Invalid dialog variable key.');
        }

        $fallbackText = (string) ($payload['fallback_text'] ?? '');

        if (mb_strlen($fallbackText) > self::MAX_MESSAGE_LENGTH) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.fallback_text", 'Fallback text is too long.');
        }

        $variants = $payload['variable_text_variants'] ?? [];

        if (! is_array($variants) || ! array_is_list($variants)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variable_text_variants", 'Variable text variants must be a list.');
        }

        if (count($variants) > self::MAX_MESSAGE_VARIABLE_VARIANTS) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variable_text_variants", 'Too many variable text variants.');
        }

        $normalizedVariants = [];

        foreach ($variants as $variantIndex => $variant) {
            $variant = $this->arrayValue($variant, "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variable_text_variants.$variantIndex");
            $operator = (string) ($variant['operator'] ?? 'eq');
            $value = trim((string) ($variant['value'] ?? ''));
            $variantText = (string) ($variant['text'] ?? '');

            if (! in_array($operator, ['eq', 'gt', 'gte', 'lt', 'lte'], true)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variable_text_variants.$variantIndex.operator", 'Invalid variant operator.');
            }

            if ($value === '') {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variable_text_variants.$variantIndex.value", 'Variant value is required.');
            }

            if ($operator !== 'eq' && ! is_numeric($value)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variable_text_variants.$variantIndex.value", 'Numeric operator requires numeric value.');
            }

            if (mb_strlen($value) > 100) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variable_text_variants.$variantIndex.value", 'Variant value is too long.');
            }

            if (mb_strlen($variantText) > self::MAX_MESSAGE_LENGTH) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variable_text_variants.$variantIndex.text", 'Variant text is too long.');
            }

            $normalizedVariants[] = [
                'operator' => $operator,
                'value' => $value,
                'text' => $variantText,
            ];
        }

        return [
            'text' => $text,
            'text_format' => (string) ($payload['text_format'] ?? 'plain_text'),
            'text_mode' => 'by_dialog_variable',
            'variable_key' => $variableKey,
            'variable_text_variants' => $normalizedVariants,
            'fallback_text' => $fallbackText,
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

                $buttonType = $this->normalizeButtonType($button['type'] ?? null, $blockIndex, $moduleIndex, $rowIndex, $buttonIndex);

                $normalizedRow[] = [
                    'id' => $buttonId,
                    'text' => $buttonText,
                    'type' => $buttonType,
                    'fn' => (string) ($button['fn'] ?? 'default'),
                    'url' => $this->normalizeButtonUrl($button['url'] ?? null, $buttonType, $blockIndex, $moduleIndex, $rowIndex, $buttonIndex),
                    'color' => $this->normalizeOptionalColor(
                        $button['color'] ?? null,
                        "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.rows.$rowIndex.$buttonIndex.color",
                    ),
                ];
            }

            $normalizedRows[] = $normalizedRow;
        }

        return [
            'placement' => $this->normalizeButtonPlacement($payload['placement'] ?? null, $blockIndex, $moduleIndex),
            'rows' => $normalizedRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeAiPayload(array $payload, int $blockIndex, int $moduleIndex): array
    {
        $prompt = (string) ($payload['prompt'] ?? '');
        $source = trim((string) ($payload['source'] ?? 'current_inbound_message'));
        $variants = $this->normalizeAiVariants($payload['variants'] ?? [], $blockIndex, $moduleIndex);
        $extractFields = $this->normalizeAiExtractFields($payload['extract_fields'] ?? [], $blockIndex, $moduleIndex);

        if (trim($prompt) === '') {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.prompt", 'AI prompt cannot be empty.');
        }

        if (mb_strlen($prompt) > self::MAX_MESSAGE_LENGTH) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.prompt", 'AI prompt is too long.');
        }

        if (! in_array($source, self::AI_SOURCES, true)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.source", 'Unknown AI data source.');
        }

        return [
            'prompt' => $prompt,
            'source' => $source,
            'variants' => $variants,
            'extract_fields' => $extractFields,
        ];
    }

    private function normalizeAiVariantDelaySeconds(mixed $value, int $blockIndex, int $moduleIndex, int $variantIndex): int
    {
        if (! is_numeric($value)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variants.$variantIndex.delay_seconds", 'AI variant delay seconds must be numeric.');
        }

        $seconds = (int) $value;

        if ($seconds < 0 || $seconds > self::MAX_AI_VARIANT_DELAY_SECONDS) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variants.$variantIndex.delay_seconds", 'AI variant delay seconds is out of range.');
        }

        return $seconds;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function normalizeAiVariants(mixed $variants, int $blockIndex, int $moduleIndex): array
    {
        if (! is_array($variants) || ! array_is_list($variants)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variants", 'AI variants must be a list.');
        }

        if ($variants === []) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variants", 'AI needs at least one result variant.');
        }

        if (count($variants) > self::MAX_AI_VARIANTS) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variants", 'Too many AI result variants.');
        }

        $ids = [];
        $normalized = [];

        foreach ($variants as $variantIndex => $variant) {
            $variant = $this->arrayValue($variant, "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variants.$variantIndex");
            $id = $this->stringValue($variant['id'] ?? null, "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variants.$variantIndex.id");
            $label = trim((string) ($variant['label'] ?? ''));

            if (strlen($id) > 64 || ! preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variants.$variantIndex.id", 'Invalid AI variant id.');
            }

            if (isset($ids[$id])) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variants.$variantIndex.id", 'AI variant id must be unique.');
            }

            if ($label === '') {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variants.$variantIndex.label", 'AI variant label cannot be empty.');
            }

            if (mb_strlen($label) > 255) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.variants.$variantIndex.label", 'AI variant label is too long.');
            }

            $ids[$id] = true;
            $normalized[] = [
                'id' => $id,
                'label' => $label,
                'delay_seconds' => $this->normalizeAiVariantDelaySeconds(
                    $variant['delay_seconds'] ?? 0,
                    $blockIndex,
                    $moduleIndex,
                    $variantIndex,
                ),
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array{key: string, label: string, type: string}>
     */
    private function normalizeAiExtractFields(mixed $fields, int $blockIndex, int $moduleIndex): array
    {
        if ($fields === null) {
            return [];
        }

        if (! is_array($fields) || ! array_is_list($fields)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.extract_fields", 'AI extract fields must be a list.');
        }

        if (count($fields) > self::MAX_AI_EXTRACT_FIELDS) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.extract_fields", 'Too many AI extract fields.');
        }

        $keys = [];
        $normalized = [];

        foreach ($fields as $fieldIndex => $field) {
            $field = $this->arrayValue($field, "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.extract_fields.$fieldIndex");
            $key = $this->stringValue($field['key'] ?? null, "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.extract_fields.$fieldIndex.key");
            $label = trim((string) ($field['label'] ?? ''));
            $type = trim((string) ($field['type'] ?? 'text'));

            if (strlen($key) > 64 || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.extract_fields.$fieldIndex.key", 'Invalid AI extract field key.');
            }

            if (isset($keys[$key])) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.extract_fields.$fieldIndex.key", 'AI extract field key must be unique.');
            }

            if ($label === '') {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.extract_fields.$fieldIndex.label", 'AI extract field label cannot be empty.');
            }

            if (mb_strlen($label) > 255) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.extract_fields.$fieldIndex.label", 'AI extract field label is too long.');
            }

            if (! in_array($type, self::AI_EXTRACT_FIELD_TYPES, true)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.extract_fields.$fieldIndex.type", 'Unknown AI extract field type.');
            }

            $keys[$key] = true;
            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{actions: list<array<string, mixed>>}
     */
    private function normalizeActionPayload(array $payload, int $blockIndex, int $moduleIndex): array
    {
        $actions = $payload['actions'] ?? [];

        if (! is_array($actions) || ! array_is_list($actions)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions", 'Actions must be a list.');
        }

        if (count($actions) > self::MAX_ACTIONS_PER_BLOCK) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions", 'Too many actions in block.');
        }

        $normalized = [];

        foreach ($actions as $actionIndex => $action) {
            $action = $this->arrayValue($action, "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex");
            $type = trim((string) ($action['type'] ?? 'write_contact_field'));
            $sourceType = trim((string) ($action['source_type'] ?? 'ai_data'));
            $valueSource = trim((string) ($action['value_source'] ?? 'manual'));
            $sourceBlockClientKey = trim((string) ($action['source_block_client_key'] ?? ''));
            $sourceBlockId = trim((string) ($action['source_block_id'] ?? ''));
            $sourceFieldKey = trim((string) ($action['source_field_key'] ?? ''));
            $staticValue = trim((string) ($action['static_value'] ?? ''));
            $manualValue = trim((string) ($action['manual_value'] ?? $action['static_value'] ?? ''));
            $targetScope = trim((string) ($action['target_scope'] ?? 'contact'));
            $targetField = trim((string) ($action['target_field'] ?? ''));
            $checkSource = trim((string) ($action['check_source'] ?? 'current_inbound_message'));
            $dictionaryKey = trim((string) ($action['dictionary_key'] ?? 'names'));
            $targetVariableKey = trim((string) ($action['target_variable_key'] ?? ''));
            $operation = trim((string) ($action['operation'] ?? 'remove_buttons'));
            $target = trim((string) ($action['target'] ?? 'last_current_run_outbound_with_inline_buttons'));
            $geoSource = trim((string) ($action['source'] ?? 'current_inbound_message'));
            $geoCityFieldKey = trim((string) ($action['city_field_key'] ?? $action['source_field_key'] ?? 'geo_city'));
            $geoRegionFieldKey = trim((string) ($action['region_field_key'] ?? 'geo_region'));
            $geoCountryFieldKey = trim((string) ($action['country_field_key'] ?? 'geo_country'));
            $simulateStartSourceScope = trim((string) ($action['source_scope'] ?? 'dialog'));
            $simulateStartSourceFieldKey = trim((string) ($action['source_field_key'] ?? ''));
            $simulateStartClearSourceField = (bool) ($action['clear_source_field_after_reroute'] ?? false);
            $bitrix24Operation = trim((string) ($action['operation'] ?? 'contact_sync'));

            if (! in_array($type, self::ACTION_TYPES, true)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.type", 'Unknown action type.');
            }

            if (in_array($type, self::ACTION_RESULT_TYPES, true)) {
                $resultActionIndexes = collect($actions)
                    ->map(fn (mixed $item): string => is_array($item) ? trim((string) ($item['type'] ?? 'write_contact_field')) : '')
                    ->filter(fn (string $itemType): bool => in_array($itemType, self::ACTION_RESULT_TYPES, true))
                    ->keys()
                    ->values();

                if ($resultActionIndexes->count() > 1) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.type", 'Only one result action is allowed in action block.');
                }

                if ($actionIndex !== count($actions) - 1) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.type", 'Result action must be the last action in block.');
                }
            }

            if ($type === 'edit_message') {
                if (! in_array($operation, self::ACTION_EDIT_MESSAGE_OPERATIONS, true)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.operation", 'Unknown message edit operation.');
                }

                if (! in_array($target, self::ACTION_EDIT_MESSAGE_TARGETS, true)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.target", 'Unknown message edit target.');
                }

                if (
                    ($operation === 'remove_buttons' && $target !== 'last_current_run_outbound_with_inline_buttons')
                    || ($operation === 'delete_message' && $target !== 'last_current_run_outbound')
                ) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.target", 'Message edit target does not match operation.');
                }

                $normalized[] = [
                    'type' => $type,
                    'operation' => $operation,
                    'target' => $target,
                ];

                continue;
            }

            if ($type === 'check_data') {
                if (! in_array($checkSource, self::ACTION_CHECK_SOURCES, true)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.check_source", 'Unknown data check source.');
                }

                if (! in_array($dictionaryKey, self::ACTION_DICTIONARIES, true)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.dictionary_key", 'Unknown dictionary.');
                }

                if ($targetVariableKey === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $targetVariableKey)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.target_variable_key", 'Invalid target variable.');
                }

                $normalized[] = [
                    'type' => $type,
                    'source_type' => 'inbound_message',
                    'check_source' => $checkSource,
                    'dictionary_key' => $dictionaryKey,
                    'lookup_field' => 'lookup_value',
                    'result_field' => 'result_value',
                    'target_variable_key' => $targetVariableKey,
                ];

                continue;
            }

            if ($type === 'calculate_distance_to_moscow') {
                $normalized[] = [
                    'type' => $type,
                ];

                continue;
            }

            if ($type === 'resolve_geo_city') {
                if (! in_array($geoSource, self::GEO_CITY_SOURCES, true)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.source", 'Unknown geo source.');
                }

                if ($geoSource === 'current_inbound_message') {
                    $normalized[] = [
                        'type' => $type,
                        'source' => 'current_inbound_message',
                    ];

                    continue;
                }

                if ($sourceBlockClientKey === '') {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.source_block_client_key", 'Geo AI source block is required.');
                }

                if ($geoCityFieldKey === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $geoCityFieldKey)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.city_field_key", 'Invalid geo city field.');
                }

                foreach (['region_field_key' => $geoRegionFieldKey, 'country_field_key' => $geoCountryFieldKey] as $field => $fieldKey) {
                    if ($fieldKey !== '' && ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $fieldKey)) {
                        $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.$field", 'Invalid geo optional field.');
                    }
                }

                $normalized[] = [
                    'type' => $type,
                    'source' => 'ai_data',
                    'source_block_client_key' => $sourceBlockClientKey,
                    'city_field_key' => $geoCityFieldKey,
                    'region_field_key' => $geoRegionFieldKey,
                    'country_field_key' => $geoCountryFieldKey,
                ];

                continue;
            }

            if ($type === 'variables') {
                $normalized[] = [
                    'type' => $type,
                    'operations' => $this->normalizeVariableOperations(
                        $action['operations'] ?? [],
                        "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.operations",
                    ),
                ];

                continue;
            }

            if ($type === 'simulate_start_parameter') {
                if ($simulateStartSourceScope !== 'dialog') {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.source_scope", 'Unknown start parameter source.');
                }

                if (! $this->fieldDictionaryEngineSupport->supportsDialogFieldCondition($simulateStartSourceFieldKey)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.source_field_key", 'Invalid start parameter field.');
                }

                $normalized[] = [
                    'type' => $type,
                    'source_scope' => 'dialog',
                    'source_field_key' => $simulateStartSourceFieldKey,
                    'clear_source_field_after_reroute' => $simulateStartClearSourceField,
                ];

                continue;
            }

            if ($type === 'tag_effects') {
                $assignTagIds = $this->normalizeTagEffectIds(
                    $action['assign_tag_ids'] ?? [],
                    "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.assign_tag_ids",
                );
                $removeTagIds = $this->normalizeTagEffectIds(
                    $action['remove_tag_ids'] ?? [],
                    "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.remove_tag_ids",
                );
                $allTagIds = array_values(array_unique([...$assignTagIds, ...$removeTagIds]));

                if (array_intersect($assignTagIds, $removeTagIds) !== []) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.remove_tag_ids", 'Tag cannot be assigned and removed in the same action.');
                }

                if ($allTagIds !== [] && Tag::query()->active()->whereKey($allTagIds)->count() !== count($allTagIds)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.assign_tag_ids", 'Unknown tag id.');
                }

                $normalized[] = [
                    'type' => $type,
                    'assign_tag_ids' => $assignTagIds,
                    'remove_tag_ids' => $removeTagIds,
                ];

                continue;
            }

            if ($type === 'complete_data_collection') {
                $normalized[] = [
                    'type' => $type,
                ];

                continue;
            }

            if ($type === 'bitrix24_sync') {
                if (! in_array($bitrix24Operation, self::BITRIX24_SYNC_OPERATIONS, true)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.operation", 'Unknown Bitrix24 operation.');
                }

                $normalized[] = [
                    'type' => $type,
                    'operation' => $bitrix24Operation,
                ];

                continue;
            }

            if ($type === 'change_field') {
                if (! in_array($valueSource, self::CHANGE_FIELD_VALUE_SOURCES, true)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.value_source", 'Unknown field value source.');
                }

                if (! in_array($targetScope, self::EDGE_CAPTURE_FIELD_SCOPES, true)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.target_scope", 'Unknown action target.');
                }

                if ($targetScope === 'contact' && ! $this->fieldDictionaryEngineSupport->supportsContactChangeField($targetField)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.target_field", 'Unknown contact field.');
                }

                if ($targetScope === 'dialog' && ! $this->fieldDictionaryEngineSupport->supportsDialogChangeField($targetField)) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.target_field", 'Invalid dialog field.');
                }

                if (mb_strlen($manualValue) > 2000) {
                    $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.manual_value", 'Action value is too long.');
                }

                if ($valueSource === 'ai_result') {
                    if ($sourceBlockClientKey === '') {
                        $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.source_block_client_key", 'AI source block is required.');
                    }

                    if ($sourceFieldKey === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $sourceFieldKey)) {
                        $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.source_field_key", 'Invalid AI result field.');
                    }
                }

                if ($valueSource === 'manual' && $targetScope === 'contact') {
                    $this->validateManualChangeFieldContactValue(
                        $manualValue,
                        $targetField,
                        "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.manual_value",
                    );
                }

                $normalized[] = [
                    'type' => 'change_field',
                    'target_scope' => $targetScope,
                    'target_field' => $targetField,
                    'value_source' => $valueSource,
                    'manual_value' => $manualValue,
                    'source_block_client_key' => $valueSource === 'ai_result' ? $sourceBlockClientKey : '',
                    'source_block_id' => $valueSource === 'ai_result' ? $sourceBlockId : '',
                    'source_field_key' => $valueSource === 'ai_result' ? $sourceFieldKey : '',
                ];

                continue;
            }

            if (! in_array($sourceType, self::ACTION_SOURCE_TYPES, true)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.source_type", 'Unknown action source.');
            }

            if (! in_array($targetScope, self::EDGE_CAPTURE_FIELD_SCOPES, true)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.target_scope", 'Unknown action target.');
            }

            if (! in_array($sourceType, ['ai_data', 'static_value'], true)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.source_type", 'Unknown action source.');
            }

            if ($sourceType === 'ai_data' && ($sourceFieldKey === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $sourceFieldKey))) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.source_field_key", 'Invalid action source field.');
            }

            if ($sourceType === 'static_value' && ($staticValue === '' || mb_strlen($staticValue) > 2000)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.static_value", 'Action value is required.');
            }

            if ($targetScope === 'contact' && ! array_key_exists($targetField, self::EDGE_CONTACT_CAPTURE_DATA_TYPES)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.target_field", 'Unknown contact field.');
            }

            if ($targetScope === 'dialog' && ! $this->fieldDictionaryEngineSupport->supportsDialogChangeField($targetField)) {
                $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex.target_field", 'Invalid dialog field.');
            }

            $normalized[] = [
                'type' => $type,
                'source_type' => $sourceType,
                'source_block_client_key' => $sourceBlockClientKey,
                'source_block_id' => $sourceBlockId,
                'source_field_key' => $sourceFieldKey,
                'static_value' => $staticValue,
                'target_scope' => $targetScope,
                'target_field' => $targetField,
            ];
        }

        return ['actions' => $normalized];
    }

    /**
     * @return list<int>
     */
    private function normalizeTagEffectIds(mixed $ids, string $path): array
    {
        if ($ids === null) {
            return [];
        }

        if (! is_array($ids) || ! array_is_list($ids)) {
            $this->fail($path, 'Tag ids must be a list.');
        }

        if (count($ids) > self::MAX_TAG_EFFECT_IDS) {
            $this->fail($path, 'Too many tag ids.');
        }

        return collect($ids)
            ->map(function (mixed $id, int $index) use ($path): int {
                if (is_string($id)) {
                    $id = trim($id);
                }

                if (! is_numeric($id) || (int) $id < 1 || (string) (int) $id !== (string) $id) {
                    $this->fail("$path.$index", 'Tag id must be a positive integer.');
                }

                return (int) $id;
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeVariableOperations(mixed $operations, string $path): array
    {
        if (! is_array($operations) || ! array_is_list($operations)) {
            $this->fail($path, 'Variable operations must be a list.');
        }

        if ($operations === []) {
            $this->fail($path, 'Variable action requires at least one operation.');
        }

        if (count($operations) > self::MAX_VARIABLE_ACTION_OPERATIONS) {
            $this->fail($path, 'Too many variable operations.');
        }

        $normalized = [];

        foreach ($operations as $index => $operation) {
            $operation = $this->arrayValue($operation, "$path.$index");
            $type = trim((string) ($operation['operation'] ?? 'set'));
            $fieldKey = trim((string) ($operation['field_key'] ?? ''));

            if (! in_array($type, self::VARIABLE_OPERATIONS, true)) {
                $this->fail("$path.$index.operation", 'Unknown variable operation.');
            }

            if (! $this->fieldDictionaryEngineSupport->supportsDialogChangeField($fieldKey)) {
                $this->fail("$path.$index.field_key", 'Invalid dialog variable key.');
            }

            if ($type === 'increment') {
                $amountValue = $operation['amount'] ?? 1;

                if (
                    ! is_int($amountValue)
                    && ! (is_string($amountValue) && preg_match('/^\d+$/', trim($amountValue)))
                ) {
                    $this->fail("$path.$index.amount", 'Increment amount must be an integer.');
                }

                $amount = (int) $amountValue;

                if ($amount < 1 || $amount > 100) {
                    $this->fail("$path.$index.amount", 'Increment amount must be from 1 to 100.');
                }

                $normalized[] = [
                    'operation' => 'increment',
                    'field_key' => $fieldKey,
                    'amount' => $amount,
                ];

                continue;
            }

            if ($type === 'clear') {
                $normalized[] = [
                    'operation' => 'clear',
                    'field_key' => $fieldKey,
                ];

                continue;
            }

            $valueSource = trim((string) ($operation['value_source'] ?? 'static_value'));
            $value = $operation['value'] ?? '';

            if (! in_array($valueSource, self::VARIABLE_SET_VALUE_SOURCES, true)) {
                $this->fail("$path.$index.value_source", 'Unknown variable value source.');
            }

            if (! is_scalar($value) && $value !== null) {
                $this->fail("$path.$index.value", 'Variable value must be scalar.');
            }

            if (is_string($value) && mb_strlen($value) > self::MAX_MESSAGE_LENGTH) {
                $this->fail("$path.$index.value", 'Variable value is too long.');
            }

            $normalized[] = [
                'operation' => 'set',
                'field_key' => $fieldKey,
                'value_source' => $valueSource,
                'value' => $value,
            ];
        }

        return $normalized;
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

    private function normalizeButtonUrl(
        mixed $url,
        string $type,
        int $blockIndex,
        int $moduleIndex,
        int $rowIndex,
        int $buttonIndex,
    ): ?string {
        if ($type !== 'link') {
            return null;
        }

        $url = trim((string) $url);

        if ($url === '') {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.rows.$rowIndex.$buttonIndex.url", 'Для кнопки-ссылки нужно указать URL.');
        }

        if (mb_strlen($url) > 2000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.rows.$rowIndex.$buttonIndex.url", 'Некорректный URL кнопки-ссылки.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.rows.$rowIndex.$buttonIndex.url", 'URL кнопки-ссылки должен начинаться с http или https.');
        }

        return $url;
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
                'ai_variant_id' => (string) ($output['ai_variant_id'] ?? ''),
                'ai_choice_id' => filled($output['ai_choice_id'] ?? null) ? (string) $output['ai_choice_id'] : null,
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
            $this->fail('builder.edges', 'Список связей конструктора должен быть списком.');
        }

        if (count($edges) > self::MAX_EDGES) {
            $this->fail('builder.edges', 'Превышен лимит связей конструктора.');
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
                $this->fail("builder.edges.$index.client_key", 'Внутренний ключ связи должен быть уникальным.');
            }

            $clientKeys[$clientKey] = true;

            $source = $this->normalizeEndpoint($edge['source'] ?? null, "builder.edges.$index.source", $blockKeys, $blockIds);
            $target = $this->normalizeEndpoint($edge['target'] ?? null, "builder.edges.$index.target", $blockKeys, $blockIds);
            $sourceOutputId = $this->nullableStringValue(data_get($edge, 'source.output_id'), "builder.edges.$index.source.output_id");
            $originalSourceOutputId = $sourceOutputId;
            $sourceOutputId = $this->normalizeGeoCitySourceOutputId($source['client_key'], $sourceOutputId, $blockKeys);
            $sourceOutputId = $this->normalizeVariablesSourceOutputId($source['client_key'], $sourceOutputId, $blockKeys);
            $sourceWasLegacyVariablesOutput = $originalSourceOutputId !== null
                && $sourceOutputId === null
                && $this->sourceBlockHasVariablesAction($source['client_key'], $blockKeys)
                && in_array($originalSourceOutputId, self::VARIABLES_LEGACY_OUTPUTS, true);

            if ($sourceOutputId !== null) {
                $sourceKey = $source['client_key'].'|'.$sourceOutputId;

                if (isset($sourceOutputs[$sourceKey])) {
                    $this->fail("builder.edges.$index.source.output_id", 'От одного выхода блока может идти только одна связь.');
                }

                $sourceOutputs[$sourceKey] = true;
            }

            if ($sourceOutputId !== null && ! $this->blockHasOutput($source['client_key'], $sourceOutputId, $blockKeys)) {
                $this->fail("builder.edges.$index.source.output_id", 'Выход, от которого начинается связь, не найден.');
            }

            if ($sourceOutputId !== null && $this->blockOutputButtonType($source['client_key'], $sourceOutputId, $blockKeys) === 'link') {
                $this->fail("builder.edges.$index.source.output_id", 'Кнопку-ссылку нельзя использовать как переход сценария.');
            }

            $conditionPayload = $this->normalizeConditionPayload(
                $this->arrayValue($edge['condition_payload'] ?? [], "builder.edges.$index.condition_payload"),
                $sourceOutputId,
                $index,
            );

            if ($sourceWasLegacyVariablesOutput) {
                $conditionPayload['label'] = in_array((string) ($conditionPayload['label'] ?? ''), ['Готово', 'Ошибка'], true)
                    ? 'Дальше'
                    : (filled($conditionPayload['label'] ?? null) ? (string) $conditionPayload['label'] : 'Дальше');
                $conditionPayload['mode'] = 'automatic';
                $conditionPayload['from_output_id'] = null;
            }

            $edgeKey = $conditionPayload['edge_key'];

            if (is_string($edgeKey) && $edgeKey !== '') {
                if (isset($edgeKeys[$edgeKey])) {
                    $this->fail("builder.edges.$index.condition_payload.edge_key", 'Внутренний ключ связи должен быть уникальным.');
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
            'label' => $this->normalizeActionResultEdgeLabel($sourceOutputId, (string) ($payload['label'] ?? '')),
            'mode' => $mode,
            'priority' => $priority,
            'transition_limit' => $transitionLimit,
            'contact_phone_condition' => $this->normalizeEdgeContactPhoneCondition($payload['contact_phone_condition'] ?? '', $edgeIndex),
            'dialog_phone_condition' => $this->normalizeEdgeDialogPhoneCondition($payload['dialog_phone_condition'] ?? '', $edgeIndex),
            'expression' => $this->normalizeEdgeExpression($payload['expression'] ?? '', $edgeIndex),
            'field_condition' => $this->normalizeEdgeFieldCondition($payload['field_condition'] ?? [], $edgeIndex),
            'tag_condition' => $this->normalizeTagCondition(
                $payload['tag_condition'] ?? [],
                "builder.edges.$edgeIndex.condition_payload.tag_condition",
            ),
            'match' => $this->normalizeEdgeMatch($payload['match'] ?? [], $edgeIndex),
            'input_capture' => $this->normalizeEdgeInputCapture($payload['input_capture'] ?? [], $edgeIndex),
            'transition_actions' => $this->normalizeEdgeTransitionActions($payload['transition_actions'] ?? [], $edgeIndex),
            'delay' => $this->normalizeEdgeDelay($payload['delay'] ?? [], $mode, $edgeIndex),
            'flags' => is_array($payload['flags'] ?? null) ? $payload['flags'] : [],
            'ui' => $this->normalizeEdgeUi($payload['ui'] ?? [], $edgeIndex),
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

        if ($sourceOutputId === null && $mode === 'action_result') {
            return 'automatic';
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

    /**
     * @return array<string, mixed>
     */
    private function normalizeEdgeUi(mixed $ui, int $edgeIndex): array
    {
        $ui = is_array($ui) ? $ui : [];

        if (! array_key_exists('waypoints', $ui)) {
            return $ui;
        }

        $waypoints = $ui['waypoints'];
        $key = "builder.edges.$edgeIndex.condition_payload.ui.waypoints";

        if (! is_array($waypoints) || ! array_is_list($waypoints)) {
            $this->fail($key, 'Waypoints must be a list.');
        }

        if (count($waypoints) > self::MAX_EDGE_WAYPOINTS) {
            $this->fail($key, 'Too many edge waypoints.');
        }

        $normalizedWaypoints = [];

        foreach ($waypoints as $waypointIndex => $waypoint) {
            if (! is_array($waypoint)) {
                $this->fail("$key.$waypointIndex", 'Waypoint must be an object.');
            }

            $id = trim((string) ($waypoint['id'] ?? ''));

            if ($id === '' || strlen($id) > 40) {
                $this->fail("$key.$waypointIndex.id", 'Invalid waypoint id.');
            }

            if (! $this->isFiniteNumber($waypoint['x'] ?? null)) {
                $this->fail("$key.$waypointIndex.x", 'Waypoint x must be a number.');
            }

            if (! $this->isFiniteNumber($waypoint['y'] ?? null)) {
                $this->fail("$key.$waypointIndex.y", 'Waypoint y must be a number.');
            }

            $normalizedWaypoints[] = [
                'id' => $id,
                'x' => round((float) $waypoint['x'], 2),
                'y' => round((float) $waypoint['y'], 2),
            ];
        }

        return [
            ...$ui,
            'waypoints' => $normalizedWaypoints,
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
     * @return array{enabled: bool, field_scope: string, field_key: string, operator: string, value: string}
     */
    private function normalizeEdgeFieldCondition(mixed $condition, int $edgeIndex): array
    {
        $condition = is_array($condition) ? $condition : [];
        $enabled = (bool) ($condition['enabled'] ?? false);

        if (! $enabled) {
            return [
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'operator' => 'filled',
                'value' => '',
            ];
        }

        $fieldScope = trim((string) ($condition['field_scope'] ?? 'dialog'));
        $fieldKey = trim((string) ($condition['field_key'] ?? ''));
        $operator = trim((string) ($condition['operator'] ?? 'filled'));
        $value = (string) ($condition['value'] ?? '');

        if (! in_array($fieldScope, self::EDGE_CAPTURE_FIELD_SCOPES, true)) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.field_condition.field_scope", 'Unknown field condition scope.');
        }

        if (! in_array($operator, self::EDGE_FIELD_CONDITION_OPERATORS, true)) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.field_condition.operator", 'Unknown field condition operator.');
        }

        if ($fieldScope === 'dialog' && ! $this->fieldDictionaryEngineSupport->supportsDialogFieldCondition($fieldKey)) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.field_condition.field_key", 'Invalid dialog field key.');
        }

        if ($fieldScope === 'contact' && ! $this->fieldDictionaryEngineSupport->supportsContactFieldCondition($fieldKey)) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.field_condition.field_key", 'Unknown contact field key.');
        }

        if (mb_strlen($value) > self::MAX_MESSAGE_LENGTH) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.field_condition.value", 'Field condition value is too long.');
        }

        if (in_array($operator, ['filled', 'empty'], true)) {
            $value = '';
        }

        return [
            'enabled' => true,
            'field_scope' => $fieldScope,
            'field_key' => $fieldKey,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    /**
     * @return array{enabled: bool, mode: string, tag_ids: list<int>}
     */
    private function normalizeTagCondition(mixed $condition, string $path): array
    {
        $condition = is_array($condition) ? $condition : [];
        $enabled = (bool) ($condition['enabled'] ?? false);
        $mode = trim((string) ($condition['mode'] ?? 'has_all'));
        $tagIds = $this->normalizeTagConditionIds($condition['tag_ids'] ?? [], "$path.tag_ids");

        if (! in_array($mode, self::TAG_CONDITION_MODES, true)) {
            $this->fail("$path.mode", 'Некорректное условие по меткам.');
        }

        if ($enabled && $tagIds === []) {
            $this->fail("$path.tag_ids", 'Некорректное условие по меткам.');
        }

        if ($enabled && Tag::query()->active()->whereKey($tagIds)->count() !== count($tagIds)) {
            $this->fail("$path.tag_ids", 'Некорректное условие по меткам.');
        }

        return [
            'enabled' => $enabled,
            'mode' => $mode,
            'tag_ids' => $tagIds,
        ];
    }

    /**
     * @return list<int>
     */
    private function normalizeTagConditionIds(mixed $ids, string $path): array
    {
        if ($ids === null) {
            return [];
        }

        if (! is_array($ids) || ! array_is_list($ids)) {
            $this->fail($path, 'Некорректное условие по меткам.');
        }

        if (count($ids) > self::MAX_TAG_CONDITION_IDS) {
            $this->fail($path, 'Некорректное условие по меткам.');
        }

        return collect($ids)
            ->map(function (mixed $id, int $index) use ($path): int {
                if (is_string($id)) {
                    $id = trim($id);
                }

                if (! is_numeric($id) || (int) $id < 1 || (string) (int) $id !== (string) $id) {
                    $this->fail("$path.$index", 'Некорректное условие по меткам.');
                }

                return (int) $id;
            })
            ->unique()
            ->values()
            ->all();
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
            if (! $this->fieldDictionaryEngineSupport->supportsDialogChangeField($fieldKey)) {
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
     * @return list<array{type: string, target_scope: string, target_field: string, value_source: string, value: string}>
     */
    private function normalizeEdgeTransitionActions(mixed $actions, int $edgeIndex): array
    {
        if (! is_array($actions)) {
            return [];
        }

        if (! array_is_list($actions)) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.transition_actions", 'Transition actions must be a list.');
        }

        if (count($actions) > self::MAX_TRANSITION_ACTIONS_PER_EDGE) {
            $this->fail("builder.edges.$edgeIndex.condition_payload.transition_actions", 'Too many transition actions.');
        }

        $normalized = [];

        foreach ($actions as $actionIndex => $action) {
            $action = $this->arrayValue($action, "builder.edges.$edgeIndex.condition_payload.transition_actions.$actionIndex");
            $type = trim((string) ($action['type'] ?? 'write_field'));
            $targetScope = trim((string) ($action['target_scope'] ?? 'contact'));
            $targetField = trim((string) ($action['target_field'] ?? ''));
            $valueSource = trim((string) ($action['value_source'] ?? 'static'));
            $value = trim((string) ($action['value'] ?? ''));
            $baseKey = "builder.edges.$edgeIndex.condition_payload.transition_actions.$actionIndex";

            if (! in_array($type, self::EDGE_TRANSITION_ACTION_TYPES, true)) {
                $this->fail("$baseKey.type", 'Unknown transition action type.');
            }

            if (! in_array($targetScope, self::EDGE_TRANSITION_ACTION_SCOPES, true)) {
                $this->fail("$baseKey.target_scope", 'Unknown transition action scope.');
            }

            if (! in_array($valueSource, self::EDGE_TRANSITION_ACTION_VALUE_SOURCES, true)) {
                $this->fail("$baseKey.value_source", 'Unknown transition action value source.');
            }

            if ($targetScope === 'contact' && ! in_array($targetField, self::EDGE_TRANSITION_CONTACT_FIELDS, true)) {
                $this->fail("$baseKey.target_field", 'Unknown writable contact field.');
            }

            if ($targetScope === 'dialog' && ! $this->fieldDictionaryEngineSupport->supportsDialogChangeField($targetField)) {
                $this->fail("$baseKey.target_field", 'Invalid dialog field key.');
            }

            if ($value === '') {
                $this->fail("$baseKey.value", 'Transition action value is required.');
            }

            if (mb_strlen($value) > self::MAX_MESSAGE_LENGTH) {
                $this->fail("$baseKey.value", 'Transition action value is too long.');
            }

            $normalized[] = [
                'type' => $type,
                'target_scope' => $targetScope,
                'target_field' => $targetField,
                'value_source' => $valueSource,
                'value' => $value,
            ];
        }

        return $normalized;
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

    private function validateManualChangeFieldContactValue(string $value, string $targetField, string $path): void
    {
        if ($value === '') {
            return;
        }

        if (in_array($targetField, ['first_name', 'last_name', 'country', 'region', 'city'], true) && mb_strlen($value) > 255) {
            $this->fail($path, 'Action value is too long.');
        }

        if ($targetField === 'gender' && ! array_key_exists($value, Contact::genderOptions())) {
            $this->fail($path, 'Unknown gender value.');
        }

        if ($targetField === 'age_range' && ! array_key_exists($value, Contact::ageRangeOptions())) {
            $this->fail($path, 'Unknown age range value.');
        }

        if ($targetField === 'age_years') {
            if (preg_match('/^\d{1,3}$/', $value) !== 1) {
                $this->fail($path, 'Invalid age value.');
            }

            $age = (int) $value;

            if ($age < 1 || $age > 120) {
                $this->fail($path, 'Invalid age value.');
            }
        }
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $blockKeys
     */
    private function blockHasOutput(string $clientKey, string $outputId, $blockKeys): bool
    {
        $block = $blockKeys->get($clientKey);

        if (
            $outputId === self::GEO_CITY_OUTPUT_LIMIT_REACHED
            && is_array($block)
            && $this->hasResolveGeoCityAction(data_get($block, 'settings_payload.modules', []))
        ) {
            return true;
        }

        if (
            $outputId === self::AI_FAILED_OUTPUT_ID
            && is_array($block)
            && $this->hasAiModuleInModules(data_get($block, 'settings_payload.modules', []))
        ) {
            return true;
        }

        $outputs = data_get($block, 'settings_payload.outputs', []);

        return collect(is_array($outputs) ? $outputs : [])
            ->contains(fn (array $output): bool => ($output['id'] ?? null) === $outputId);
    }

    private function normalizeActionResultEdgeLabel(?string $sourceOutputId, string $label): string
    {
        return match ($sourceOutputId) {
            'geo_found' => 'Город найден',
            'geo_manual_required' => 'Нужно уточнить',
            'geo_not_found' => 'Город не найден',
            'geo_limit_reached' => 'Превышено попыток',
            default => $label,
        };
    }

    private function normalizeGeoCitySourceOutputId(string $clientKey, ?string $outputId, $blockKeys): ?string
    {
        if ($outputId === null) {
            return $outputId;
        }

        $block = $blockKeys->get($clientKey);

        if (! is_array($block) || ! $this->hasResolveGeoCityAction(data_get($block, 'settings_payload.modules', []))) {
            return $outputId;
        }

        if (in_array($outputId, self::GEO_CITY_LEGACY_MANUAL_REQUIRED_OUTPUTS, true)) {
            return self::GEO_CITY_OUTPUT_MANUAL_REQUIRED;
        }

        return in_array($outputId, self::GEO_CITY_LEGACY_NOT_FOUND_OUTPUTS, true)
            ? self::GEO_CITY_OUTPUT_NOT_FOUND
            : $outputId;
    }

    private function normalizeVariablesSourceOutputId(string $clientKey, ?string $outputId, $blockKeys): ?string
    {
        if ($outputId === null) {
            return null;
        }

        if (! $this->sourceBlockHasVariablesAction($clientKey, $blockKeys)) {
            return $outputId;
        }

        return in_array($outputId, self::VARIABLES_LEGACY_OUTPUTS, true) ? null : $outputId;
    }

    private function sourceBlockHasVariablesAction(string $clientKey, $blockKeys): bool
    {
        $block = $blockKeys->get($clientKey);

        return is_array($block) && $this->hasVariablesAction(data_get($block, 'settings_payload.modules', []));
    }

    private function hasResolveGeoCityAction(mixed $modules): bool
    {
        if (! is_array($modules)) {
            return false;
        }

        foreach ($modules as $module) {
            if (! is_array($module) || ($module['type'] ?? null) !== 'action') {
                continue;
            }

            $actions = data_get($module, 'payload.actions', []);

            if (! is_array($actions)) {
                continue;
            }

            foreach ($actions as $action) {
                if (is_array($action) && ($action['type'] ?? null) === 'resolve_geo_city') {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasVariablesAction(mixed $modules): bool
    {
        if (! is_array($modules)) {
            return false;
        }

        foreach ($modules as $module) {
            if (! is_array($module) || ($module['type'] ?? null) !== 'action') {
                continue;
            }

            $actions = data_get($module, 'payload.actions', []);

            if (! is_array($actions)) {
                continue;
            }

            foreach ($actions as $action) {
                if (is_array($action) && ($action['type'] ?? null) === 'variables') {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasAiModuleInModules(mixed $modules): bool
    {
        if (! is_array($modules)) {
            return false;
        }

        foreach ($modules as $module) {
            if (is_array($module) && ($module['type'] ?? null) === 'ai') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $modules
     * @return list<array<string, mixed>>
     */
    private function geoCityCanonicalOutputs(array $modules): array
    {
        $moduleId = 'mod_action';

        foreach ($modules as $module) {
            if (($module['type'] ?? null) === 'action' && $this->hasResolveGeoCityAction([$module])) {
                $moduleId = (string) ($module['id'] ?? $moduleId);
                break;
            }
        }

        return [
            [
                'id' => 'geo_found',
                'label' => 'Город найден',
                'source' => 'action',
                'module_id' => $moduleId,
                'action_result_id' => 'geo_found',
            ],
            [
                'id' => 'geo_manual_required',
                'label' => 'Нужно уточнить',
                'source' => 'action',
                'module_id' => $moduleId,
                'action_result_id' => 'geo_manual_required',
            ],
            [
                'id' => 'geo_not_found',
                'label' => 'Город не найден',
                'source' => 'action',
                'module_id' => $moduleId,
                'action_result_id' => 'geo_not_found',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function withoutLegacyVariableOutputs(array $outputs): array
    {
        return collect($outputs)
            ->filter(fn (mixed $output): bool => is_array($output)
                && ! in_array((string) ($output['id'] ?? ''), self::VARIABLES_LEGACY_OUTPUTS, true))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $outputs
     * @return list<array<string, mixed>>
     */
    private function withAiFailedOutput(array $outputs): array
    {
        $outputs = collect($outputs)
            ->reject(fn (array $output): bool => ($output['id'] ?? null) === self::AI_FAILED_OUTPUT_ID)
            ->values()
            ->all();

        $outputs[] = [
            'id' => self::AI_FAILED_OUTPUT_ID,
            'label' => 'Ошибка ИИ',
            'source' => 'ai',
            'module_id' => 'mod_ai',
            'ai_variant_id' => self::AI_FAILED_OUTPUT_ID,
            'ai_choice_id' => null,
            'system' => true,
        ];

        return $outputs;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function validateGeoAiDataSources(array $blocks): void
    {
        $blocksByClientKey = collect($blocks)->keyBy('client_key');

        foreach ($blocks as $blockIndex => $block) {
            $modules = data_get($block, 'settings_payload.modules', []);

            if (! is_array($modules)) {
                continue;
            }

            foreach ($modules as $moduleIndex => $module) {
                if (! is_array($module) || ($module['type'] ?? null) !== 'action') {
                    continue;
                }

                $actions = data_get($module, 'payload.actions', []);

                if (! is_array($actions)) {
                    continue;
                }

                foreach ($actions as $actionIndex => $action) {
                    $path = "builder.blocks.$blockIndex.settings_payload.modules.$moduleIndex.payload.actions.$actionIndex";

                    if (
                        is_array($action)
                        && ($action['type'] ?? null) === 'change_field'
                        && ($action['value_source'] ?? null) === 'ai_result'
                    ) {
                        $sourceBlockClientKey = (string) ($action['source_block_client_key'] ?? '');
                        $sourceBlock = $blocksByClientKey->get($sourceBlockClientKey);

                        if (! is_array($sourceBlock) || ! $this->hasAiModule($sourceBlock)) {
                            $this->fail("$path.source_block_client_key", 'AI source block must be an AI analysis block.');
                        }

                        $sourceFieldKey = (string) ($action['source_field_key'] ?? '');

                        if (! in_array($sourceFieldKey, $this->aiExtractFieldKeys($sourceBlock), true)) {
                            $this->fail("$path.source_field_key", 'AI source field must exist in selected AI analysis block.');
                        }
                    }

                    if (
                        ! is_array($action)
                        || ($action['type'] ?? null) !== 'resolve_geo_city'
                        || ($action['source'] ?? null) !== 'ai_data'
                    ) {
                        continue;
                    }

                    $sourceBlockClientKey = (string) ($action['source_block_client_key'] ?? '');
                    $sourceBlock = $blocksByClientKey->get($sourceBlockClientKey);

                    if (! is_array($sourceBlock) || ! $this->hasAiModule($sourceBlock)) {
                        $this->fail("$path.source_block_client_key", 'Geo source block must be an AI analysis block.');
                    }

                    $extractFieldKeys = $this->aiExtractFieldKeys($sourceBlock);

                    foreach ([
                        'city_field_key' => (string) ($action['city_field_key'] ?? ''),
                        'region_field_key' => (string) ($action['region_field_key'] ?? ''),
                        'country_field_key' => (string) ($action['country_field_key'] ?? ''),
                    ] as $field => $fieldKey) {
                        if ($fieldKey !== '' && ! in_array($fieldKey, $extractFieldKeys, true)) {
                            $this->fail("$path.$field", 'Geo source field must exist in selected AI analysis block.');
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function hasAiModule(array $block): bool
    {
        return collect(data_get($block, 'settings_payload.modules', []))
            ->contains(fn (mixed $module): bool => is_array($module) && ($module['type'] ?? null) === 'ai');
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<string>
     */
    private function aiExtractFieldKeys(array $block): array
    {
        $aiModule = collect(data_get($block, 'settings_payload.modules', []))
            ->first(fn (mixed $module): bool => is_array($module) && ($module['type'] ?? null) === 'ai');

        return collect(data_get($aiModule, 'payload.extract_fields', []))
            ->filter(fn (mixed $field): bool => is_array($field))
            ->map(fn (array $field): string => trim((string) ($field['key'] ?? '')))
            ->filter(fn (string $key): bool => $key !== '')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $blockKeys
     */
    private function blockOutputButtonType(string $clientKey, string $outputId, $blockKeys): ?string
    {
        $block = $blockKeys->get($clientKey);
        $outputs = data_get($block, 'settings_payload.outputs', []);

        $output = collect(is_array($outputs) ? $outputs : [])
            ->first(fn (array $output): bool => ($output['id'] ?? null) === $outputId);

        return is_array($output) && is_string($output['button_type'] ?? null)
            ? (string) $output['button_type']
            : null;
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

                if (($buttonsById[$buttonId]['type'] ?? null) === 'link') {
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
        $sheetId = trim((string) ($ui['sheet_id'] ?? self::DEFAULT_SHEET_ID));

        if ($sheetId === '') {
            $sheetId = self::DEFAULT_SHEET_ID;
        }

        if (! $this->isValidSheetId($sheetId)) {
            $this->fail("builder.blocks.$blockIndex.settings_payload.ui.sheet_id", 'Некорректный ID листа блока.');
        }

        $normalized = [
            'sheet_id' => $sheetId,
            'width' => (int) ($ui['width'] ?? 320),
            'collapsed' => (bool) ($ui['collapsed'] ?? false),
            'card_id' => $this->optionalStringValue($ui['card_id'] ?? '', "builder.blocks.$blockIndex.settings_payload.ui.card_id", ''),
            'display_number' => $this->optionalStringValue($ui['display_number'] ?? '', "builder.blocks.$blockIndex.settings_payload.ui.display_number", ''),
        ];

        $importSource = $this->normalizeBlockImportSource(
            $ui['import_source'] ?? null,
            "builder.blocks.$blockIndex.settings_payload.ui.import_source",
        );

        if ($importSource !== null) {
            $normalized['import_source'] = $importSource;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeBlockImportSource(mixed $source, string $path): ?array
    {
        if ($source === null || $source === '') {
            return null;
        }

        $source = $this->arrayValue($source, $path);
        $type = $this->optionalStringValue($source['type'] ?? '', "$path.type", '');

        if ($type === '') {
            return null;
        }

        if ($type !== 'auto_reply_rule_xlsx') {
            $this->fail("$path.type", 'Unknown import source.');
        }

        return [
            'type' => $type,
            'source_workbook_key' => $this->optionalStringValue($source['source_workbook_key'] ?? '', "$path.source_workbook_key", ''),
            'source_rule_id' => $this->nonNegativeIntegerValue($source['source_rule_id'] ?? 0, "$path.source_rule_id", 1_000_000_000),
            'created_batch_id' => $this->optionalStringValue($source['created_batch_id'] ?? '', "$path.created_batch_id", ''),
            'last_import_batch_id' => $this->optionalStringValue($source['last_import_batch_id'] ?? '', "$path.last_import_batch_id", ''),
            'source_row_hash' => $this->optionalStringValue($source['source_row_hash'] ?? '', "$path.source_row_hash", ''),
            'imported_payload_hash' => $this->optionalStringValue($source['imported_payload_hash'] ?? '', "$path.imported_payload_hash", ''),
            'source_rule_name' => $this->optionalStringValue($source['source_rule_name'] ?? '', "$path.source_rule_name", ''),
            'source_row_number' => $this->nonNegativeIntegerValue($source['source_row_number'] ?? 0, "$path.source_row_number", 1_000_000_000),
            'source_file_name' => $this->optionalStringValue($source['source_file_name'] ?? '', "$path.source_file_name", ''),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeSheets(mixed $sheets): array
    {
        if (! is_array($sheets) || $sheets === []) {
            return [$this->defaultSheet()];
        }

        if (! array_is_list($sheets)) {
            $this->fail('builder.sheets', 'Список листов конструктора должен быть списком.');
        }

        if (count($sheets) > self::MAX_SHEETS) {
            $this->fail('builder.sheets', 'Превышен лимит листов конструктора.');
        }

        $seen = [];
        $normalized = [];

        foreach ($sheets as $index => $sheet) {
            $sheet = $this->arrayValue($sheet, "builder.sheets.$index");
            $id = trim((string) ($sheet['id'] ?? ''));

            if (! $this->isValidSheetId($id)) {
                $this->fail("builder.sheets.$index.id", 'Некорректный ID листа.');
            }

            if (isset($seen[$id])) {
                $this->fail("builder.sheets.$index.id", 'ID листа должен быть уникальным.');
            }

            $seen[$id] = true;

            $name = trim((string) ($sheet['name'] ?? ''));

            if ($id === self::DEFAULT_SHEET_ID && $name === '') {
                $name = self::DEFAULT_SHEET_NAME;
            }

            if ($name === '' || mb_strlen($name) > self::MAX_SHEET_NAME_LENGTH) {
                $this->fail("builder.sheets.$index.name", 'Название листа должно быть от 1 до 40 символов.');
            }

            $color = $this->normalizeOptionalColor($sheet['color'] ?? 'none', "builder.sheets.$index.color", allowNone: true) ?? 'none';

            $normalizedSheet = [
                'id' => $id,
                'name' => $name,
                'color' => $color,
                'view' => $this->normalizeSheetView($sheet['view'] ?? [], $index),
            ];

            $importSource = $this->normalizeSheetImportSource(
                $sheet['import_source'] ?? null,
                "builder.sheets.$index.import_source",
            );

            if ($importSource !== null) {
                $normalizedSheet['import_source'] = $importSource;
            }

            $normalized[] = $normalizedSheet;
        }

        if (! isset($seen[self::DEFAULT_SHEET_ID])) {
            array_unshift($normalized, $this->defaultSheet());
        }

        $mainSheet = collect($normalized)->firstWhere('id', self::DEFAULT_SHEET_ID) ?? $this->defaultSheet();
        $otherSheets = collect($normalized)
            ->reject(fn (array $sheet): bool => $sheet['id'] === self::DEFAULT_SHEET_ID)
            ->values()
            ->all();

        return [$mainSheet, ...$otherSheets];
    }

    private function normalizeOptionalColor(mixed $color, string $path, bool $allowNone = false): ?string
    {
        if ($color === null) {
            return $allowNone ? 'none' : null;
        }

        $value = trim((string) $color);

        if ($value === '') {
            return $allowNone ? 'none' : null;
        }

        if ($value === 'none') {
            return $allowNone ? 'none' : null;
        }

        $normalized = $this->colorRegistry->normalizeInputValue($value, allowNone: $allowNone);

        if ($normalized === null) {
            $this->fail($path, 'Некорректный цвет.');
        }

        return $normalized;
    }

    /**
     * @return array{id: string, name: string, color: string, view: array{tx: float, ty: float, zoom: float}}
     */
    private function defaultSheet(): array
    {
        return [
            'id' => self::DEFAULT_SHEET_ID,
            'name' => self::DEFAULT_SHEET_NAME,
            'color' => 'none',
            'view' => ['tx' => 0, 'ty' => 0, 'zoom' => 1],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeSheetImportSource(mixed $source, string $path): ?array
    {
        if ($source === null || $source === '') {
            return null;
        }

        $source = $this->arrayValue($source, $path);
        $type = $this->optionalStringValue($source['type'] ?? '', "$path.type", '');

        if ($type === '') {
            return null;
        }

        if ($type !== 'auto_reply_rule_xlsx') {
            $this->fail("$path.type", 'Unknown sheet import source.');
        }

        return [
            'type' => $type,
            'created_batch_id' => $this->optionalStringValue($source['created_batch_id'] ?? '', "$path.created_batch_id", ''),
        ];
    }

    /**
     * @return array{tx: float, ty: float, zoom: float}
     */
    private function normalizeSheetView(mixed $view, int $sheetIndex): array
    {
        $view = is_array($view) ? $view : [];
        $tx = $this->finiteFloatValue($view['tx'] ?? 0, "builder.sheets.$sheetIndex.view.tx");
        $ty = $this->finiteFloatValue($view['ty'] ?? 0, "builder.sheets.$sheetIndex.view.ty");
        $zoom = $this->finiteFloatValue($view['zoom'] ?? 1, "builder.sheets.$sheetIndex.view.zoom");

        if ($tx < -100000 || $tx > 100000 || $ty < -100000 || $ty > 100000) {
            $this->fail("builder.sheets.$sheetIndex.view", 'Позиция листа вне допустимых границ.');
        }

        if ($zoom < 0.35 || $zoom > 2.2) {
            $this->fail("builder.sheets.$sheetIndex.view.zoom", 'Масштаб листа вне допустимых границ.');
        }

        return [
            'tx' => round($tx, 2),
            'ty' => round($ty, 2),
            'zoom' => round($zoom, 3),
        ];
    }

    /**
     * @param  list<string>  $sheetIds
     */
    private function normalizeActiveSheetId(mixed $value, array $sheetIds): string
    {
        $activeSheetId = trim((string) ($value ?? self::DEFAULT_SHEET_ID));
        $activeSheetId = $activeSheetId !== '' ? $activeSheetId : self::DEFAULT_SHEET_ID;

        if (! in_array($activeSheetId, $sheetIds, true)) {
            $this->fail('builder.active_sheet_id', 'Активный лист не найден.');
        }

        return $activeSheetId;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<string>  $sheetIds
     */
    private function guardBlockSheetIds(array $blocks, array $sheetIds): void
    {
        foreach ($blocks as $index => $block) {
            $sheetId = (string) data_get($block, 'settings_payload.ui.sheet_id', self::DEFAULT_SHEET_ID);

            if (! in_array($sheetId, $sheetIds, true)) {
                $this->fail("builder.blocks.$index.settings_payload.ui.sheet_id", 'Лист блока не найден.');
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @param  list<array<string, mixed>>  $blocks
     */
    private function guardEdgesWithinSingleSheet(array $edges, array $blocks): void
    {
        $sheetsByClientKey = collect($blocks)
            ->mapWithKeys(fn (array $block): array => [
                (string) $block['client_key'] => (string) data_get($block, 'settings_payload.ui.sheet_id', self::DEFAULT_SHEET_ID),
            ]);

        foreach ($edges as $index => $edge) {
            $sourceSheet = $sheetsByClientKey->get((string) data_get($edge, 'source.client_key'));
            $targetSheet = $sheetsByClientKey->get((string) data_get($edge, 'target.client_key'));

            if ($sourceSheet !== null && $targetSheet !== null && $sourceSheet !== $targetSheet) {
                $this->fail("builder.edges.$index", 'Связи между разными листами запрещены.');
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $sheets
     * @return array{next_sheet_number: int}
     */
    private function normalizeBuilderMeta(mixed $meta, array $sheets): array
    {
        $meta = is_array($meta) ? $meta : [];
        $storedNext = $this->positiveIntegerOrNull($meta['next_sheet_number'] ?? null) ?? 1;
        $nextFromSheets = collect($sheets)
            ->map(fn (array $sheet): int => $this->sheetNumberFromId((string) $sheet['id']) ?? 0)
            ->max() + 1;

        return [
            'next_sheet_number' => max($storedNext, $nextFromSheets),
        ];
    }

    private function sheetNumberFromId(string $sheetId): ?int
    {
        if (! preg_match('/^sheet_(\d+)$/', $sheetId, $matches)) {
            return null;
        }

        return max(1, (int) $matches[1]);
    }

    private function isValidSheetId(string $sheetId): bool
    {
        return strlen($sheetId) <= 40 && preg_match('/^[a-z][a-z0-9_]*$/', $sheetId) === 1;
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

    private function positiveIntegerOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        $string = trim((string) $value);

        if ($string === '' || ! ctype_digit($string)) {
            return null;
        }

        $integer = (int) $string;

        return $integer > 0 ? $integer : null;
    }

    private function finiteFloatValue(mixed $value, string $key): float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            $this->fail($key, 'Value must be a finite number.');
        }

        $float = (float) $value;

        if (! is_finite($float)) {
            $this->fail($key, 'Value must be a finite number.');
        }

        return $float;
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

    private function isFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value);
    }

    private function validDialogVariableKey(string $key): bool
    {
        if ($key === '' || mb_strlen($key) > 64) {
            return false;
        }

        if (in_array($key, ['__proto__', 'constructor', 'prototype'], true)) {
            return false;
        }

        return preg_match('/^(?!_)[\p{L}][\p{L}\p{N}_]{0,63}$/u', $key) === 1;
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
