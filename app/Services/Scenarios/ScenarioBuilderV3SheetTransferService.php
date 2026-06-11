<?php

namespace App\Services\Scenarios;

use App\Models\Channel;
use App\Models\Scenario;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScenarioBuilderV3SheetTransferService
{
    public const FORMAT = 'abrikosoff.constructor.v3.sheet_export';

    public const EXPORT_FORMAT_VERSION = 1;

    public const MAX_JSON_BYTES = 1048576;

    private const DEFAULT_SHEET_ID = 'main';

    public function __construct(
        private readonly BuildScenarioBuilderV3StateAction $buildScenarioBuilderV3StateAction,
        private readonly SaveScenarioBuilderV3StateAction $saveScenarioBuilderV3StateAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(Scenario $scenario, User $user): array
    {
        $state = $this->currentState($scenario, $user);
        $builder = $state['builder'];
        $sheetId = $this->activeSheetId($builder);
        $sheet = $this->sheetById($builder, $sheetId);
        $blocks = $this->blocksForSheet($builder, $sheetId);
        $blockKeys = collect($blocks)->pluck('client_key')->mapWithKeys(fn (mixed $key): array => [(string) $key => true])->all();
        $blockExportKeys = [];
        $edgeExportKeys = [];

        $exportBlocks = collect($blocks)
            ->values()
            ->map(function (array $block, int $index) use (&$blockExportKeys): array {
                $exportKey = $this->exportKey('block', $index + 1);
                $blockExportKeys[(string) $block['client_key']] = $exportKey;

                return [
                    'export_key' => $exportKey,
                    'title' => (string) ($block['title'] ?? 'Блок'),
                    'type' => (string) ($block['type'] ?? 'state'),
                    'position' => [
                        'x' => (float) data_get($block, 'position.x', 0),
                        'y' => (float) data_get($block, 'position.y', 0),
                    ],
                    'settings_payload' => $this->sanitizeSettingsPayloadForExport($block['settings_payload'] ?? []),
                ];
            })
            ->all();

        $exportEdges = collect($builder['edges'] ?? [])
            ->filter(fn (mixed $edge): bool => is_array($edge)
                && isset($blockKeys[(string) data_get($edge, 'source.client_key')])
                && isset($blockKeys[(string) data_get($edge, 'target.client_key')]))
            ->values()
            ->map(function (array $edge, int $index) use (&$edgeExportKeys, $blockExportKeys): array {
                $exportKey = $this->exportKey('edge', $index + 1);
                $edgeExportKeys[(string) $edge['client_key']] = $exportKey;

                return [
                    'export_key' => $exportKey,
                    'source' => [
                        'block_export_key' => $blockExportKeys[(string) data_get($edge, 'source.client_key')],
                        'output_id' => data_get($edge, 'source.output_id'),
                    ],
                    'target' => [
                        'block_export_key' => $blockExportKeys[(string) data_get($edge, 'target.client_key')],
                    ],
                    'condition_payload' => $this->sanitizeConditionPayloadForExport($edge['condition_payload'] ?? []),
                ];
            })
            ->all();

        $channelHints = [];
        $channelHintKeysById = [];
        $startBlocks = [];

        foreach ($blocks as $block) {
            $startModule = $this->startModule($block['settings_payload'] ?? []);

            if ($startModule === null) {
                continue;
            }

            $hintKeys = [];
            foreach ($this->normalizeIdList(data_get($startModule, 'payload.channels.ids', [])) as $channelId) {
                if (! isset($channelHintKeysById[$channelId])) {
                    $channel = Channel::query()->find($channelId);
                    $hintKey = $this->exportKey('channel', count($channelHintKeysById) + 1);
                    $channelHintKeysById[$channelId] = $hintKey;
                    $channelHints[] = [
                        'export_key' => $hintKey,
                        'source_channel_id' => $channelId,
                        'name' => $channel instanceof Channel ? (string) $channel->name : 'Канал #'.$channelId,
                        'platform' => $channel instanceof Channel ? (string) $channel->platform : '',
                        'connection_type' => $channel instanceof Channel ? (string) $channel->connection_type : '',
                        'is_active' => $channel instanceof Channel ? (bool) $channel->is_active : false,
                    ];
                }

                $hintKeys[] = $channelHintKeysById[$channelId];
            }

            $startBlocks[] = [
                'block_export_key' => $blockExportKeys[(string) $block['client_key']],
                'title' => (string) ($block['title'] ?? 'Старт'),
                'channel_hint_keys' => $hintKeys,
                'start_condition_summary' => $this->startConditionSummary($startModule),
            ];
        }

        return [
            'format' => self::FORMAT,
            'export_format_version' => self::EXPORT_FORMAT_VERSION,
            'schema_version' => BuildScenarioBuilderV3StateAction::SCHEMA_VERSION,
            'exported_at' => CarbonImmutable::now()->utc()->toJSON(),
            'source' => [
                'draft_version_id' => (int) data_get($state, 'scenario.draft_version_id'),
                'builder_revision' => (string) data_get($builder, 'revision', ''),
            ],
            'sheet' => [
                'export_key' => 'sheet_000001',
                'source_sheet_id' => $sheetId,
                'name' => (string) ($sheet['name'] ?? 'Главный'),
                'view' => [
                    'tx' => (float) data_get($sheet, 'view.tx', 0),
                    'ty' => (float) data_get($sheet, 'view.ty', 0),
                    'zoom' => (float) data_get($sheet, 'view.zoom', 1),
                ],
            ],
            'blocks' => $exportBlocks,
            'edges' => $exportEdges,
            'start_blocks' => $startBlocks,
            'channel_hints' => $channelHints,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(Scenario $scenario, User $user, array $input): array
    {
        $document = $this->decodeDocument($input['json'] ?? null);
        $state = $this->currentState($scenario, $user);
        $builder = $state['builder'];
        $sheetId = $this->activeSheetId($builder);

        $this->validateDocument($document);
        $this->guardNoCurrentCrossSheetEdges($builder, $sheetId);

        return $this->previewPayload($state, $document, $sheetId, $user);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function apply(Scenario $scenario, User $user, array $input): array
    {
        $document = $this->decodeDocument($input['json'] ?? null);
        $this->validateDocument($document);

        $selectedChannels = $this->selectedChannels($input['selected_channels'] ?? []);
        $startExportKeys = collect($document['start_blocks'])->pluck('block_export_key')->mapWithKeys(fn (mixed $key): array => [(string) $key => true])->all();
        $extraSelectedKeys = array_diff(array_keys($selectedChannels), array_keys($startExportKeys));

        if ($extraSelectedKeys !== []) {
            throw ValidationException::withMessages([
                'selected_channels' => 'Каналы можно выбирать только для стартовых блоков из импортируемого файла.',
            ]);
        }

        $this->guardSelectedChannelsAvailable($selectedChannels, $user);

        $state = $this->currentState($scenario, $user);
        $builder = $state['builder'];
        $sheetId = $this->activeSheetId($builder);

        $this->guardNoCurrentCrossSheetEdges($builder, $sheetId);

        $draftVersionId = (int) ($input['draft_version_id'] ?? 0);
        $baseRevision = trim((string) ($input['base_builder_revision'] ?? $input['base_revision'] ?? ''));

        if ($draftVersionId !== (int) data_get($state, 'scenario.draft_version_id')) {
            throw ValidationException::withMessages([
                'draft_version_id' => 'Черновик сценария изменился. Обновите конструктор и повторите импорт.',
            ]);
        }

        if ($baseRevision === '') {
            throw ValidationException::withMessages([
                'base_builder_revision' => 'Не передана версия конструктора для проверки изменений.',
            ]);
        }

        $newBuilder = $this->buildImportedBuilder($builder, $document, $sheetId, $selectedChannels);
        $savedState = $this->saveScenarioBuilderV3StateAction->handle($scenario, [
            'draft_version_id' => $draftVersionId,
            'base_revision' => $baseRevision,
            'builder' => $newBuilder,
        ]);

        $focusExportKey = $this->firstBlockExportKey($document);
        $focusClientKey = $focusExportKey !== null ? 'import_'.$focusExportKey : null;

        $savedState['import'] = [
            'sheet_id' => $sheetId,
            'focus_block_client_key' => $focusClientKey,
            'empty_sheet' => count($document['blocks']) === 0,
        ];

        return $savedState;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentState(Scenario $scenario, User $user): array
    {
        return $this->buildScenarioBuilderV3StateAction->handle($scenario->fresh(['draftVersion', 'publishedVersion']), $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDocument(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            throw ValidationException::withMessages([
                'json' => 'Выберите JSON-файл для импорта.',
            ]);
        }

        if (strlen($raw) > self::MAX_JSON_BYTES) {
            throw ValidationException::withMessages([
                'json' => 'Файл импорта больше 1 MB.',
            ]);
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::withMessages([
                'json' => 'JSON-файл не удалось прочитать.',
            ]);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function validateDocument(array $document): void
    {
        if (($document['format'] ?? null) !== self::FORMAT) {
            $this->fail('format', 'Формат файла не поддерживается.');
        }

        if ((int) ($document['export_format_version'] ?? 0) !== self::EXPORT_FORMAT_VERSION) {
            $this->fail('export_format_version', 'Версия export-формата не поддерживается.');
        }

        if ((int) ($document['schema_version'] ?? 0) !== BuildScenarioBuilderV3StateAction::SCHEMA_VERSION) {
            $this->fail('schema_version', 'Файл должен быть для V3-конструктора.');
        }

        foreach (['sheet', 'blocks', 'edges', 'start_blocks', 'channel_hints'] as $key) {
            if (! array_key_exists($key, $document)) {
                $this->fail($key, 'В файле импорта не хватает обязательных полей.');
            }
        }

        $sheet = $this->arrayValue($document['sheet'], 'sheet');
        $blocks = $this->listValue($document['blocks'], 'blocks');
        $edges = $this->listValue($document['edges'], 'edges');
        $startBlocks = $this->listValue($document['start_blocks'], 'start_blocks');
        $channelHints = $this->listValue($document['channel_hints'], 'channel_hints');

        $this->stringValue($sheet['export_key'] ?? null, 'sheet.export_key');
        $this->stringValue($sheet['source_sheet_id'] ?? null, 'sheet.source_sheet_id');
        $this->stringValue($sheet['name'] ?? null, 'sheet.name');
        $this->arrayValue($sheet['view'] ?? [], 'sheet.view');

        $blockKeys = [];
        foreach ($blocks as $index => $block) {
            $block = $this->arrayValue($block, "blocks.$index");
            $exportKey = $this->exportKeyValue($block['export_key'] ?? null, "blocks.$index.export_key", 'block');
            $this->guardUniqueKey($blockKeys, $exportKey, "blocks.$index.export_key");
            $this->stringValue($block['title'] ?? null, "blocks.$index.title");
            $this->stringValue($block['type'] ?? null, "blocks.$index.type");
            $this->arrayValue($block['position'] ?? null, "blocks.$index.position");
            $this->arrayValue($block['settings_payload'] ?? null, "blocks.$index.settings_payload");
        }

        $edgeKeys = [];
        foreach ($edges as $index => $edge) {
            $edge = $this->arrayValue($edge, "edges.$index");
            $exportKey = $this->exportKeyValue($edge['export_key'] ?? null, "edges.$index.export_key", 'edge');
            $this->guardUniqueKey($edgeKeys, $exportKey, "edges.$index.export_key");

            $sourceBlockKey = $this->stringValue(data_get($edge, 'source.block_export_key'), "edges.$index.source.block_export_key");
            $targetBlockKey = $this->stringValue(data_get($edge, 'target.block_export_key'), "edges.$index.target.block_export_key");

            if (! isset($blockKeys[$sourceBlockKey]) || ! isset($blockKeys[$targetBlockKey])) {
                $this->fail("edges.$index", 'Связь ссылается на блок, которого нет в файле импорта.');
            }

            $this->arrayValue($edge['condition_payload'] ?? null, "edges.$index.condition_payload");
        }

        $hintKeys = [];
        foreach ($channelHints as $index => $hint) {
            $hint = $this->arrayValue($hint, "channel_hints.$index");
            $exportKey = $this->exportKeyValue($hint['export_key'] ?? null, "channel_hints.$index.export_key", 'channel');
            $this->guardUniqueKey($hintKeys, $exportKey, "channel_hints.$index.export_key");
        }

        foreach ($startBlocks as $index => $startBlock) {
            $startBlock = $this->arrayValue($startBlock, "start_blocks.$index");
            $blockExportKey = $this->stringValue($startBlock['block_export_key'] ?? null, "start_blocks.$index.block_export_key");

            if (! isset($blockKeys[$blockExportKey])) {
                $this->fail("start_blocks.$index.block_export_key", 'Стартовый блок ссылается на блок, которого нет в файле импорта.');
            }

            foreach ($this->listValue($startBlock['channel_hint_keys'] ?? [], "start_blocks.$index.channel_hint_keys") as $hintIndex => $hintKey) {
                $hintKey = $this->stringValue($hintKey, "start_blocks.$index.channel_hint_keys.$hintIndex");

                if (! isset($hintKeys[$hintKey])) {
                    $this->fail("start_blocks.$index.channel_hint_keys.$hintIndex", 'Подсказка канала ссылается на канал, которого нет в файле импорта.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function previewPayload(array $state, array $document, string $sheetId, User $user): array
    {
        $startBlocks = collect($document['start_blocks'])
            ->map(fn (array $startBlock): array => [
                'block_export_key' => (string) $startBlock['block_export_key'],
                'title' => (string) ($startBlock['title'] ?? ''),
                'channel_hint_keys' => array_values($startBlock['channel_hint_keys'] ?? []),
                'start_condition_summary' => (string) ($startBlock['start_condition_summary'] ?? ''),
            ])
            ->values()
            ->all();

        return [
            'draft_version_id' => (int) data_get($state, 'scenario.draft_version_id'),
            'base_builder_revision' => (string) data_get($state, 'builder.revision', ''),
            'sheet_id' => $sheetId,
            'source_sheet' => [
                'source_sheet_id' => (string) data_get($document, 'sheet.source_sheet_id', ''),
                'name' => (string) data_get($document, 'sheet.name', ''),
            ],
            'counts' => [
                'blocks' => count($document['blocks']),
                'edges' => count($document['edges']),
                'start_blocks' => count($document['start_blocks']),
                'channel_hints' => count($document['channel_hints']),
            ],
            'start_blocks' => $startBlocks,
            'channel_hints' => $document['channel_hints'],
            'available_channels' => $this->availableChannels($user),
            'warnings' => array_values(array_filter([
                'Импорт полностью заменит активный лист. Остальные листы останутся без изменений.',
                count($document['blocks']) === 0 ? 'Файл содержит пустой лист.' : null,
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $builder
     * @param  array<string, mixed>  $document
     * @param  array<string, list<int>>  $selectedChannels
     * @return array<string, mixed>
     */
    private function buildImportedBuilder(array $builder, array $document, string $sheetId, array $selectedChannels): array
    {
        $currentBlocks = is_array($builder['blocks'] ?? null) ? $builder['blocks'] : [];
        $currentEdges = is_array($builder['edges'] ?? null) ? $builder['edges'] : [];
        $activeBlockKeys = collect($currentBlocks)
            ->filter(fn (mixed $block): bool => is_array($block) && $this->blockSheetId($block) === $sheetId)
            ->pluck('client_key')
            ->mapWithKeys(fn (mixed $key): array => [(string) $key => true])
            ->all();
        $otherBlocks = collect($currentBlocks)
            ->filter(fn (mixed $block): bool => is_array($block) && $this->blockSheetId($block) !== $sheetId)
            ->values()
            ->all();
        $otherEdges = collect($currentEdges)
            ->filter(fn (mixed $edge): bool => is_array($edge)
                && ! isset($activeBlockKeys[(string) data_get($edge, 'source.client_key')])
                && ! isset($activeBlockKeys[(string) data_get($edge, 'target.client_key')]))
            ->values()
            ->all();

        $blockClientKeysByExportKey = [];
        $importedBlocks = collect($document['blocks'])
            ->map(function (array $block) use ($sheetId, $selectedChannels, &$blockClientKeysByExportKey): array {
                $exportKey = (string) $block['export_key'];
                $clientKey = 'import_'.$exportKey;
                $blockClientKeysByExportKey[$exportKey] = $clientKey;

                return [
                    'id' => null,
                    'client_key' => $clientKey,
                    'type' => 'state',
                    'title' => (string) ($block['title'] ?? 'Блок'),
                    'position' => [
                        'x' => (float) data_get($block, 'position.x', 0),
                        'y' => (float) data_get($block, 'position.y', 0),
                    ],
                    'settings_payload' => $this->sanitizeSettingsPayloadForImport(
                        $block['settings_payload'] ?? [],
                        $sheetId,
                        $selectedChannels[$exportKey] ?? null,
                    ),
                ];
            })
            ->values()
            ->all();

        $importedEdges = collect($document['edges'])
            ->map(fn (array $edge): array => [
                'id' => null,
                'client_key' => 'import_'.$edge['export_key'],
                'source' => [
                    'block_id' => null,
                    'client_key' => $blockClientKeysByExportKey[(string) data_get($edge, 'source.block_export_key')],
                    'output_id' => data_get($edge, 'source.output_id'),
                ],
                'target' => [
                    'block_id' => null,
                    'client_key' => $blockClientKeysByExportKey[(string) data_get($edge, 'target.block_export_key')],
                ],
                'condition_payload' => $this->sanitizeConditionPayloadForImport(
                    $edge['condition_payload'] ?? [],
                    data_get($edge, 'source.output_id'),
                ),
            ])
            ->values()
            ->all();

        return [
            'schema_version' => BuildScenarioBuilderV3StateAction::SCHEMA_VERSION,
            'active_sheet_id' => $sheetId,
            'sheets' => is_array($builder['sheets'] ?? null) && $builder['sheets'] !== []
                ? $builder['sheets']
                : [[
                    'id' => self::DEFAULT_SHEET_ID,
                    'name' => 'Главный',
                    'color' => 'none',
                    'view' => ['tx' => 0, 'ty' => 0, 'zoom' => 1],
                ]],
            'blocks' => array_values([...$otherBlocks, ...$importedBlocks]),
            'edges' => array_values([...$otherEdges, ...$importedEdges]),
            'visible_scope' => is_array($builder['visible_scope'] ?? null)
                ? $builder['visible_scope']
                : ['block_ids' => [], 'edge_ids' => []],
        ];
    }

    /**
     * @param  array<string, mixed>  $builder
     */
    private function guardNoCurrentCrossSheetEdges(array $builder, string $sheetId): void
    {
        $blocksByKey = collect($builder['blocks'] ?? [])
            ->filter(fn (mixed $block): bool => is_array($block))
            ->mapWithKeys(fn (array $block): array => [(string) $block['client_key'] => $this->blockSheetId($block)])
            ->all();

        foreach ($builder['edges'] ?? [] as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $sourceSheet = $blocksByKey[(string) data_get($edge, 'source.client_key')] ?? null;
            $targetSheet = $blocksByKey[(string) data_get($edge, 'target.client_key')] ?? null;

            if ($sourceSheet === null || $targetSheet === null || $sourceSheet === $targetSheet) {
                continue;
            }

            if ($sourceSheet === $sheetId || $targetSheet === $sheetId) {
                throw ValidationException::withMessages([
                    'sheet' => 'Импорт заблокирован: активный лист связан стрелкой с другим листом.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $builder
     * @return list<array<string, mixed>>
     */
    private function blocksForSheet(array $builder, string $sheetId): array
    {
        return collect($builder['blocks'] ?? [])
            ->filter(fn (mixed $block): bool => is_array($block) && $this->blockSheetId($block) === $sheetId)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $builder
     */
    private function activeSheetId(array $builder): string
    {
        $sheetId = trim((string) ($builder['active_sheet_id'] ?? ''));

        return $sheetId !== '' ? $sheetId : self::DEFAULT_SHEET_ID;
    }

    /**
     * @param  array<string, mixed>  $builder
     * @return array<string, mixed>
     */
    private function sheetById(array $builder, string $sheetId): array
    {
        $sheet = collect($builder['sheets'] ?? [])->first(
            fn (mixed $item): bool => is_array($item) && (string) ($item['id'] ?? '') === $sheetId,
        );

        return is_array($sheet) ? $sheet : [
            'id' => self::DEFAULT_SHEET_ID,
            'name' => 'Главный',
            'view' => ['tx' => 0, 'ty' => 0, 'zoom' => 1],
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function blockSheetId(array $block): string
    {
        $sheetId = trim((string) data_get($block, 'settings_payload.ui.sheet_id', ''));

        return $sheetId !== '' ? $sheetId : self::DEFAULT_SHEET_ID;
    }

    /**
     * @param  array<string, mixed>  $settingsPayload
     * @return array<string, mixed>
     */
    private function sanitizeSettingsPayloadForExport(array $settingsPayload): array
    {
        data_forget($settingsPayload, 'ui.card_id');
        data_forget($settingsPayload, 'ui.sheet_id');

        $ui = is_array($settingsPayload['ui'] ?? null) ? $settingsPayload['ui'] : [];
        $settingsPayload['ui'] = array_filter([
            'width' => $ui['width'] ?? null,
            'collapsed' => $ui['collapsed'] ?? null,
            'display_number' => $ui['display_number'] ?? null,
        ], fn (mixed $value): bool => $value !== null);

        return $settingsPayload;
    }

    /**
     * @param  array<string, mixed>  $settingsPayload
     * @param  list<int>|null  $selectedChannelIds
     * @return array<string, mixed>
     */
    private function sanitizeSettingsPayloadForImport(array $settingsPayload, string $sheetId, ?array $selectedChannelIds): array
    {
        $settingsPayload = $this->sanitizeSettingsPayloadForExport($settingsPayload);
        $settingsPayload['schema_version'] = BuildScenarioBuilderV3StateAction::SCHEMA_VERSION;
        $settingsPayload['kind'] = in_array($settingsPayload['kind'] ?? null, ['state', 'non_state'], true)
            ? (string) $settingsPayload['kind']
            : 'state';
        $settingsPayload['ui'] = is_array($settingsPayload['ui'] ?? null) ? $settingsPayload['ui'] : [];
        $settingsPayload['ui']['sheet_id'] = $sheetId;
        unset($settingsPayload['ui']['card_id']);
        unset($settingsPayload['ui']['display_number']);
        $settingsPayload['modules'] = is_array($settingsPayload['modules'] ?? null) && array_is_list($settingsPayload['modules'])
            ? $settingsPayload['modules']
            : [];
        $settingsPayload['outputs'] = is_array($settingsPayload['outputs'] ?? null) && array_is_list($settingsPayload['outputs'])
            ? $settingsPayload['outputs']
            : [];

        if ($selectedChannelIds !== null) {
            foreach ($settingsPayload['modules'] as $index => $module) {
                if (! is_array($module) || ($module['type'] ?? null) !== 'start_condition') {
                    continue;
                }

                data_set($module, 'payload.channels.mode', 'selected');
                data_set($module, 'payload.channels.ids', array_values($selectedChannelIds));
                $settingsPayload['modules'][$index] = $module;
                break;
            }
        }

        return $settingsPayload;
    }

    /**
     * @param  array<string, mixed>  $conditionPayload
     * @return array<string, mixed>
     */
    private function sanitizeConditionPayloadForExport(array $conditionPayload): array
    {
        data_forget($conditionPayload, 'ui.edge_id');

        return $conditionPayload;
    }

    /**
     * @param  array<string, mixed>  $conditionPayload
     * @return array<string, mixed>
     */
    private function sanitizeConditionPayloadForImport(array $conditionPayload, mixed $sourceOutputId): array
    {
        $conditionPayload = $this->sanitizeConditionPayloadForExport($conditionPayload);
        $conditionPayload['schema_version'] = BuildScenarioBuilderV3StateAction::SCHEMA_VERSION;
        $conditionPayload['edge_schema_version'] = BuildScenarioBuilderV3StateAction::SCHEMA_VERSION;
        $conditionPayload['edge_key'] = null;
        $conditionPayload['from_output_id'] = $sourceOutputId;

        return $conditionPayload;
    }

    /**
     * @param  array<string, mixed>  $settingsPayload
     * @return array<string, mixed>|null
     */
    private function startModule(array $settingsPayload): ?array
    {
        foreach ($settingsPayload['modules'] ?? [] as $module) {
            if (is_array($module) && ($module['type'] ?? null) === 'start_condition') {
                return $module;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $startModule
     */
    private function startConditionSummary(array $startModule): string
    {
        $match = (string) data_get($startModule, 'payload.match', '');
        $command = trim((string) data_get($startModule, 'payload.command', ''));

        return trim(implode(' ', array_filter([$match, $command])));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availableChannels(User $user): array
    {
        return Channel::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (Channel $channel): bool => $user->can('update', $channel))
            ->map(fn (Channel $channel): array => [
                'id' => (int) $channel->id,
                'name' => (string) $channel->name,
                'platform' => (string) $channel->platform,
                'connection_type' => (string) $channel->connection_type,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, list<int>>  $selectedChannels
     */
    private function guardSelectedChannelsAvailable(array $selectedChannels, User $user): void
    {
        $ids = collect($selectedChannels)->flatten()->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values()->all();

        if ($ids === []) {
            return;
        }

        $channels = Channel::query()->whereKey($ids)->where('is_active', true)->get();

        if (
            $channels->count() !== count($ids)
            || $channels->contains(fn (Channel $channel): bool => ! $user->can('update', $channel))
        ) {
            throw ValidationException::withMessages([
                'selected_channels' => 'Один из выбранных каналов недоступен или выключен.',
            ]);
        }
    }

    /**
     * @return array<string, list<int>>
     */
    private function selectedChannels(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $ids) {
            if (! is_string($key)) {
                $this->fail('selected_channels', 'Ключ выбора каналов должен быть export_key стартового блока.');
            }

            $result[$key] = $this->normalizeIdList($ids);
        }

        return $result;
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
     * @param  array<string, mixed>  $document
     */
    private function firstBlockExportKey(array $document): ?string
    {
        $keys = collect($document['blocks'] ?? [])->pluck('export_key')->filter()->sort()->values();

        return $keys->isNotEmpty() ? (string) $keys->first() : null;
    }

    private function exportKey(string $prefix, int $number): string
    {
        return sprintf('%s_%06d', $prefix, $number);
    }

    private function exportKeyValue(mixed $value, string $field, string $prefix): string
    {
        $value = $this->stringValue($value, $field);

        if (! Str::startsWith($value, $prefix.'_')) {
            $this->fail($field, 'Некорректный export_key.');
        }

        return $value;
    }

    /**
     * @param  array<string, true>  $keys
     */
    private function guardUniqueKey(array &$keys, string $key, string $field): void
    {
        if (isset($keys[$key])) {
            $this->fail($field, 'Export key должен быть уникальным.');
        }

        $keys[$key] = true;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            $this->fail($field, 'Поле должно быть объектом.');
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function listValue(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($field, 'Поле должно быть списком.');
        }

        return $value;
    }

    private function stringValue(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            $this->fail($field, 'Поле должно быть строкой.');
        }

        return $value;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => $message,
        ]);
    }
}
