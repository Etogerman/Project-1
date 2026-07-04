<?php

namespace App\Services\Scenarios;

use App\Models\Channel;
use App\Models\Scenario;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScenarioBuilderV3SheetTransferService
{
    public const FORMAT = 'abrikosoff.constructor.v3.sheet_export';

    public const EXPORT_FORMAT_VERSION = 2;

    private const LEGACY_EXPORT_FORMAT_VERSION = 1;

    public const MAX_JSON_BYTES = 1048576;

    private const DEFAULT_SHEET_ID = 'main';

    public function __construct(
        private readonly BuildScenarioBuilderV3StateAction $buildScenarioBuilderV3StateAction,
        private readonly SaveScenarioBuilderV3StateAction $saveScenarioBuilderV3StateAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(Scenario $scenario, User $user, mixed $requestedSheetId = null): array
    {
        $state = $this->currentState($scenario, $user);
        $builder = $state['builder'];
        $sheetId = $this->exportSheetId($builder, $requestedSheetId);
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

        $tagHints = $this->tagHintsForReferences($this->tagReferenceContexts($exportBlocks, $exportEdges));

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
            'tag_hints' => $tagHints,
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
        $tagMappings = $this->resolveImportedTagMappings($document, $input['tag_mappings'] ?? []);

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

        $newBuilder = $this->buildImportedBuilder($builder, $document, $sheetId, $selectedChannels, $tagMappings);
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

        $exportFormatVersion = (int) ($document['export_format_version'] ?? 0);

        if (! in_array($exportFormatVersion, [self::LEGACY_EXPORT_FORMAT_VERSION, self::EXPORT_FORMAT_VERSION], true)) {
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
        $tagHints = array_key_exists('tag_hints', $document)
            ? $this->listValue($document['tag_hints'], 'tag_hints')
            : [];

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

        $tagHintKeys = [];
        foreach ($tagHints as $index => $hint) {
            $hint = $this->arrayValue($hint, "tag_hints.$index");
            $sourceTagId = $this->positiveIntValue($hint['source_tag_id'] ?? null, "tag_hints.$index.source_tag_id");
            $this->guardUniqueKey($tagHintKeys, (string) $sourceTagId, "tag_hints.$index.source_tag_id");
            $this->stringValue($hint['name'] ?? null, "tag_hints.$index.name");

            if (isset($hint['color']) && ! in_array((string) $hint['color'], array_keys(Tag::colorOptions()), true)) {
                $this->fail("tag_hints.$index.color", 'Некорректный цвет тега.');
            }
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
        $tagResolution = $this->tagResolutionPreview($document);
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
                'tag_hints' => count($this->tagReferenceContexts($document['blocks'], $document['edges'])),
            ],
            'start_blocks' => $startBlocks,
            'channel_hints' => $document['channel_hints'],
            'available_channels' => $this->availableChannels($user),
            'available_tags' => $this->availableTags(),
            'tag_hints' => array_values($this->documentTagHints($document)),
            'default_tag_mappings' => $tagResolution['mappings'],
            'unresolved_tags' => $tagResolution['unresolved'],
            'can_create_tags' => $user->hasRolePermission('tags.edit'),
            'warnings' => array_values(array_filter([
                'Импорт полностью заменит активный лист. Остальные листы останутся без изменений.',
                count($document['blocks']) === 0 ? 'Файл содержит пустой лист.' : null,
                $this->hasLegacyTagReferencesWithoutHints($document)
                    ? 'Файл содержит production-ссылки на теги без названий. Сопоставьте их с текущими тегами или создайте локальные технические теги прямо в импорте.'
                    : null,
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $builder
     * @param  array<string, mixed>  $document
     * @param  array<string, list<int>>  $selectedChannels
     * @param  array<int, int>  $tagMappings
     * @return array<string, mixed>
     */
    private function buildImportedBuilder(array $builder, array $document, string $sheetId, array $selectedChannels, array $tagMappings): array
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
            ->map(function (array $block) use ($sheetId, $selectedChannels, $tagMappings, &$blockClientKeysByExportKey): array {
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
                        $tagMappings,
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
                    $tagMappings,
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
     * @param  array<string, mixed>  $document
     * @return array<int, int>
     */
    private function resolveImportedTagMappings(array $document, mixed $rawMappings): array
    {
        $references = $this->tagReferenceContexts($document['blocks'], $document['edges']);

        if ($references === []) {
            return [];
        }

        $mappings = array_replace(
            $this->defaultTagMappings($references, $this->documentTagHints($document)),
            $this->userTagMappings($rawMappings),
        );
        $mappings = array_intersect_key($mappings, $references);
        $targetIds = array_values(array_unique(array_filter(
            array_values($mappings),
            fn (int $id): bool => $id > 0,
        )));

        if ($targetIds !== [] && Tag::query()->active()->whereKey($targetIds)->count() !== count($targetIds)) {
            throw ValidationException::withMessages([
                'tag_mappings' => 'Один из выбранных тегов недоступен или выключен.',
            ]);
        }

        $missingSourceIds = array_values(array_diff(array_keys($references), array_keys($mappings)));

        if ($missingSourceIds !== []) {
            sort($missingSourceIds);

            throw ValidationException::withMessages([
                'tag_mappings' => $this->formatUnresolvedTagMappingMessage($missingSourceIds, $references, $this->documentTagHints($document)),
            ]);
        }

        return $mappings;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{mappings: array<int, int>, unresolved: list<array<string, mixed>>}
     */
    private function tagResolutionPreview(array $document): array
    {
        $references = $this->tagReferenceContexts($document['blocks'], $document['edges']);
        $hints = $this->documentTagHints($document);
        $mappings = $this->defaultTagMappings($references, $hints);
        $inactiveTagsByName = $this->inactiveTagsByNormalizedName();
        $unresolved = [];

        foreach ($references as $sourceTagId => $contexts) {
            if (isset($mappings[$sourceTagId])) {
                continue;
            }

            $hint = $hints[$sourceTagId] ?? [];
            $name = trim((string) ($hint['name'] ?? ''));
            $inactiveTag = $name !== ''
                ? ($inactiveTagsByName[$this->normalizeTagName($name)] ?? null)
                : null;

            $unresolved[] = [
                'source_tag_id' => $sourceTagId,
                'name' => $name,
                'label' => $name !== '' ? $name : 'Тег #'.$sourceTagId,
                'color' => (string) ($hint['color'] ?? Tag::COLOR_GRAY),
                'can_create' => $name !== '' && ! ($inactiveTag instanceof Tag),
                'can_reactivate' => $inactiveTag instanceof Tag,
                'inactive_tag' => $inactiveTag instanceof Tag ? $this->tagPreviewPayload($inactiveTag) : null,
                'reason' => $inactiveTag instanceof Tag
                    ? 'inactive_match'
                    : ($name !== '' ? 'not_found' : 'legacy_missing_metadata'),
                'contexts' => array_slice(array_values(array_unique($contexts)), 0, 3),
            ];
        }

        return [
            'mappings' => $mappings,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * @param  array<int, list<string>>  $references
     * @param  array<int, array<string, mixed>>  $hints
     * @return array<int, int>
     */
    private function defaultTagMappings(array $references, array $hints): array
    {
        $sourceIds = array_keys($references);

        if ($sourceIds === []) {
            return [];
        }

        $mappings = Tag::query()
            ->active()
            ->whereKey($sourceIds)
            ->pluck('id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => (int) $id])
            ->all();
        $unmappedNames = [];

        foreach ($sourceIds as $sourceId) {
            if (isset($mappings[$sourceId])) {
                continue;
            }

            $name = $this->normalizeTagName((string) ($hints[$sourceId]['name'] ?? ''));

            if ($name !== '') {
                $unmappedNames[$sourceId] = $name;
            }
        }

        if ($unmappedNames === []) {
            return $mappings;
        }

        $tagsByName = Tag::query()
            ->active()
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Tag $tag): array => [$this->normalizeTagName($tag->name) => (int) $tag->id])
            ->all();

        foreach ($unmappedNames as $sourceId => $name) {
            if (isset($tagsByName[$name])) {
                $mappings[$sourceId] = $tagsByName[$name];
            }
        }

        return $mappings;
    }

    /**
     * @return array<string, Tag>
     */
    private function inactiveTagsByNormalizedName(): array
    {
        return Tag::query()
            ->where('is_active', false)
            ->get(['id', 'name', 'slug', 'color', 'is_active'])
            ->mapWithKeys(fn (Tag $tag): array => [$this->normalizeTagName($tag->name) => $tag])
            ->all();
    }

    /**
     * @return array{id: int, name: string, slug: string, color: string, is_active: bool}
     */
    private function tagPreviewPayload(Tag $tag): array
    {
        return [
            'id' => (int) $tag->id,
            'name' => (string) $tag->name,
            'slug' => (string) $tag->slug,
            'color' => (string) $tag->color,
            'is_active' => (bool) $tag->is_active,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function userTagMappings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $mappings = [];

        foreach ($value as $key => $mapping) {
            if (is_array($mapping)) {
                $sourceTagId = (int) ($mapping['source_tag_id'] ?? 0);
                $tagId = (int) ($mapping['tag_id'] ?? 0);
            } else {
                $sourceTagId = is_numeric($key) ? (int) $key : 0;
                $tagId = (int) $mapping;
            }

            if ($sourceTagId > 0 && $tagId > 0) {
                $mappings[$sourceTagId] = $tagId;
            }
        }

        return $mappings;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<int, array{source_tag_id: int, name: string, slug: string, color: string, is_active: bool}>
     */
    private function documentTagHints(array $document): array
    {
        $rawHints = $document['tag_hints'] ?? [];

        if (! is_array($rawHints) || ! array_is_list($rawHints)) {
            return [];
        }

        $hints = [];

        foreach ($rawHints as $hint) {
            if (! is_array($hint)) {
                continue;
            }

            $sourceTagId = (int) ($hint['source_tag_id'] ?? 0);

            if ($sourceTagId <= 0) {
                continue;
            }

            $color = (string) ($hint['color'] ?? Tag::COLOR_GRAY);
            $hints[$sourceTagId] = [
                'source_tag_id' => $sourceTagId,
                'name' => trim((string) ($hint['name'] ?? '')),
                'slug' => trim((string) ($hint['slug'] ?? '')),
                'color' => in_array($color, array_keys(Tag::colorOptions()), true) ? $color : Tag::COLOR_GRAY,
                'is_active' => (bool) ($hint['is_active'] ?? true),
            ];
        }

        return $hints;
    }

    /**
     * @param  array<int, list<string>>  $references
     * @return list<array<string, mixed>>
     */
    private function tagHintsForReferences(array $references): array
    {
        $ids = array_keys($references);

        if ($ids === []) {
            return [];
        }

        return Tag::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'color', 'is_active'])
            ->map(fn (Tag $tag): array => [
                'source_tag_id' => (int) $tag->id,
                'name' => (string) $tag->name,
                'slug' => (string) $tag->slug,
                'color' => (string) $tag->color,
                'is_active' => (bool) $tag->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $edges
     * @return array<int, list<string>>
     */
    private function tagReferenceContexts(array $blocks, array $edges): array
    {
        $references = [];

        foreach ($blocks as $blockIndex => $block) {
            $blockTitle = trim((string) ($block['title'] ?? ''));
            $blockLabel = $blockTitle !== '' ? $blockTitle : 'блок #'.($blockIndex + 1);
            $modules = data_get($block, 'settings_payload.modules', []);

            if (! is_array($modules)) {
                continue;
            }

            foreach ($modules as $module) {
                if (! is_array($module)) {
                    continue;
                }

                $this->rememberTagReferences(
                    $references,
                    data_get($module, 'payload.tag_condition.tag_ids', []),
                    "блок {$blockLabel}, условие по тегам",
                );

                $actions = data_get($module, 'payload.actions', []);

                if (! is_array($actions)) {
                    continue;
                }

                foreach ($actions as $action) {
                    if (! is_array($action) || ($action['type'] ?? null) !== 'tag_effects') {
                        continue;
                    }

                    $this->rememberTagReferences(
                        $references,
                        $action['assign_tag_ids'] ?? [],
                        "блок {$blockLabel}, назначение тегов",
                    );
                    $this->rememberTagReferences(
                        $references,
                        $action['remove_tag_ids'] ?? [],
                        "блок {$blockLabel}, снятие тегов",
                    );
                }
            }
        }

        foreach ($edges as $edgeIndex => $edge) {
            $edgeLabel = trim((string) ($edge['export_key'] ?? ''));
            $edgeLabel = $edgeLabel !== '' ? $edgeLabel : 'связь #'.($edgeIndex + 1);

            $this->rememberTagReferences(
                $references,
                data_get($edge, 'condition_payload.tag_condition.tag_ids', []),
                "{$edgeLabel}, условие по тегам",
            );
        }

        return $references;
    }

    /**
     * @param  array<int, list<string>>  $references
     */
    private function rememberTagReferences(array &$references, mixed $ids, string $context): void
    {
        foreach ($this->normalizeIdList($ids) as $id) {
            $references[$id] ??= [];
            $references[$id][] = $context;
        }
    }

    /**
     * @param  list<int>  $missingSourceIds
     * @param  array<int, list<string>>  $references
     * @param  array<int, array<string, mixed>>  $hints
     */
    private function formatUnresolvedTagMappingMessage(array $missingSourceIds, array $references, array $hints): string
    {
        $visibleIds = array_slice($missingSourceIds, 0, 10);
        $idsText = implode(', ', array_map(fn (int $id): string => '#'.$id, $visibleIds));
        $hiddenCount = count($missingSourceIds) - count($visibleIds);

        if ($hiddenCount > 0) {
            $idsText .= ' и ещё '.$hiddenCount;
        }

        $firstId = $missingSourceIds[0];
        $firstContext = $references[$firstId][0] ?? null;
        $contextText = $firstContext !== null ? ' Первый проблемный участок: '.$firstContext.'.' : '';
        $hasMissingHints = collect($missingSourceIds)->contains(
            fn (int $id): bool => trim((string) ($hints[$id]['name'] ?? '')) === '',
        );

        if ($hasMissingHints) {
            return 'Файл импорта ссылается на теги без данных для автосоздания: '.$idsText.'.'
                .$contextText
                .' Сопоставьте их с существующими тегами или создайте локальные технические теги прямо в импорте.';
        }

        return 'Сопоставьте или создайте теги из файла перед импортом: '.$idsText.'.'.$contextText;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function hasLegacyTagReferencesWithoutHints(array $document): bool
    {
        if (array_key_exists('tag_hints', $document)) {
            return false;
        }

        return $this->tagReferenceContexts($document['blocks'], $document['edges']) !== [];
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
     */
    private function exportSheetId(array $builder, mixed $requestedSheetId): string
    {
        $sheetId = trim((string) $requestedSheetId);

        if ($sheetId === '') {
            return $this->activeSheetId($builder);
        }

        if ($sheetId === self::DEFAULT_SHEET_ID) {
            return $sheetId;
        }

        $exists = collect($builder['sheets'] ?? [])->contains(
            fn (mixed $sheet): bool => is_array($sheet) && (string) ($sheet['id'] ?? '') === $sheetId,
        );

        if (! $exists) {
            throw ValidationException::withMessages([
                'sheet_id' => 'Лист для экспорта не найден.',
            ]);
        }

        return $sheetId;
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
     * @param  array<int, int>  $tagMappings
     * @return array<string, mixed>
     */
    private function sanitizeSettingsPayloadForImport(array $settingsPayload, string $sheetId, ?array $selectedChannelIds, array $tagMappings): array
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

        $settingsPayload = $this->remapSettingsTagReferences($settingsPayload, $tagMappings);

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
     * @param  array<int, int>  $tagMappings
     * @return array<string, mixed>
     */
    private function sanitizeConditionPayloadForImport(array $conditionPayload, mixed $sourceOutputId, array $tagMappings): array
    {
        $conditionPayload = $this->sanitizeConditionPayloadForExport($conditionPayload);
        $conditionPayload['schema_version'] = BuildScenarioBuilderV3StateAction::SCHEMA_VERSION;
        $conditionPayload['edge_schema_version'] = BuildScenarioBuilderV3StateAction::SCHEMA_VERSION;
        $conditionPayload['edge_key'] = null;
        $conditionPayload['from_output_id'] = $sourceOutputId;

        if (data_get($conditionPayload, 'tag_condition.tag_ids') !== null) {
            data_set(
                $conditionPayload,
                'tag_condition.tag_ids',
                $this->remapTagIdList(data_get($conditionPayload, 'tag_condition.tag_ids', []), $tagMappings),
            );
        }

        return $conditionPayload;
    }

    /**
     * @param  array<string, mixed>  $settingsPayload
     * @param  array<int, int>  $tagMappings
     * @return array<string, mixed>
     */
    private function remapSettingsTagReferences(array $settingsPayload, array $tagMappings): array
    {
        if ($tagMappings === [] || ! is_array($settingsPayload['modules'] ?? null)) {
            return $settingsPayload;
        }

        foreach ($settingsPayload['modules'] as $moduleIndex => $module) {
            if (! is_array($module)) {
                continue;
            }

            if (data_get($module, 'payload.tag_condition.tag_ids') !== null) {
                data_set(
                    $module,
                    'payload.tag_condition.tag_ids',
                    $this->remapTagIdList(data_get($module, 'payload.tag_condition.tag_ids', []), $tagMappings),
                );
            }

            $actions = data_get($module, 'payload.actions', []);

            if (is_array($actions)) {
                foreach ($actions as $actionIndex => $action) {
                    if (! is_array($action) || ($action['type'] ?? null) !== 'tag_effects') {
                        continue;
                    }

                    $actions[$actionIndex]['assign_tag_ids'] = $this->remapTagIdList($action['assign_tag_ids'] ?? [], $tagMappings);
                    $actions[$actionIndex]['remove_tag_ids'] = $this->remapTagIdList($action['remove_tag_ids'] ?? [], $tagMappings);
                }

                data_set($module, 'payload.actions', $actions);
            }

            $settingsPayload['modules'][$moduleIndex] = $module;
        }

        return $settingsPayload;
    }

    /**
     * @param  array<int, int>  $tagMappings
     * @return list<int>
     */
    private function remapTagIdList(mixed $ids, array $tagMappings): array
    {
        return collect($this->normalizeIdList($ids))
            ->map(fn (int $id): int => $tagMappings[$id] ?? $id)
            ->unique()
            ->values()
            ->all();
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
     * @return list<array<string, mixed>>
     */
    private function availableTags(): array
    {
        return Tag::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'color', 'is_active'])
            ->map(fn (Tag $tag): array => [
                'id' => (int) $tag->id,
                'name' => (string) $tag->name,
                'color' => (string) $tag->color,
                'is_active' => (bool) $tag->is_active,
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

    private function normalizeTagName(string $name): string
    {
        return Str::lower(trim($name));
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

    private function positiveIntValue(mixed $value, string $field): int
    {
        $number = (int) $value;

        if ($number <= 0 || (string) $number !== (string) $value) {
            $this->fail($field, 'Поле должно быть положительным числом.');
        }

        return $number;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => $message,
        ]);
    }
}
