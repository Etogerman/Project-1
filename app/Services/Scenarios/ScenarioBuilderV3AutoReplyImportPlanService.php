<?php

namespace App\Services\Scenarios;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Scenario;
use App\Models\Tag;
use App\Models\User;
use App\Services\AutoReplyRules\AutoReplyRuleWorkbookFormat;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class ScenarioBuilderV3AutoReplyImportPlanService
{
    public const WORKBOOK_KEY = 'auto_reply_rules';

    private const SHEET_COLORS = ['blue', 'green', 'yellow', 'red', 'purple', 'teal', 'gray'];

    private const PLACEMENT_SINGLE_SHEET = 'single_sheet';

    private const PLACEMENT_BY_CATEGORY = 'by_category';

    private const PLACEMENT_CURRENT_SHEET = 'current_sheet';

    private const DEFAULT_IMPORT_SHEET_NAME = 'Импорт автоответов';

    private const IMPORT_LAYOUT_START_X = 80;

    private const IMPORT_LAYOUT_START_Y = 80;

    private const IMPORT_LAYOUT_COLUMN_GAP = 400;

    private const IMPORT_LAYOUT_ROW_GAP = 320;

    /**
     * @return array<string, mixed>
     */
    public function preview(Scenario $scenario, User $user, UploadedFile $file, array $payload): array
    {
        $builder = $this->builderPayload($payload);
        $userMappings = $this->userMappings($payload);
        $excludedRows = $this->integerSet($payload['excluded_row_numbers'] ?? []);
        $overwriteRows = $this->integerSet($payload['overwrite_conflict_row_numbers'] ?? []);
        $placementMode = $this->placementMode($payload['placement_mode'] ?? null);
        $importBatchId = $this->importBatchId($payload['import_batch_id'] ?? null);
        $parsed = $this->parseWorkbook($file);
        $availableChannels = $this->availableChannels($user);
        $availableTags = $this->availableTags();
        $channelRefs = $parsed['channel_refs'];
        $tagRefs = $parsed['tag_refs'];
        $rows = $parsed['rows'];
        $existingBlocks = $this->existingImportedBlocks($builder);
        $plannedSheets = $this->plannedSheets($builder);
        $newBlockLayout = [];
        $usedClientKeys = collect($builder['blocks'] ?? [])
            ->pluck('client_key')
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->values()
            ->all();

        $planBlocks = [];
        $rowResults = [];
        $unresolvedChannels = [];
        $unresolvedTags = [];
        $summary = [
            'rows_total' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'blocked' => 0,
            'excluded' => 0,
            'conflicts' => 0,
            'inactive' => 0,
        ];

        foreach ($rows as $row) {
            $summary['rows_total']++;
            $rowNumber = (int) $row['row_number'];
            $sourceRuleId = (int) ($row['id'] ?? 0);
            $blockers = [];

            if (isset($excludedRows[$rowNumber])) {
                $summary['excluded']++;
                $rowResults[] = $this->rowResult($row, 'excluded', ['row_excluded'], null);
                continue;
            }

            if ($sourceRuleId <= 0) {
                $blockers[] = 'rule_id_required';
            }

            if (! (bool) ($row['is_active'] ?? false)) {
                $blockers[] = 'inactive_rule';
                $summary['inactive']++;
            }

            if (($row['required_tag_names'] ?? []) !== [] || ($row['excluded_tag_names'] ?? []) !== []) {
                $blockers[] = 'tag_conditions_not_supported';
            }

            $channelResolution = $this->resolveChannels(
                $row,
                $availableChannels['by_id'],
                $userMappings['channels'],
                $channelRefs,
            );
            $tagResolution = $this->resolveTags(
                $row,
                $availableTags['by_name'],
                $userMappings['tags'],
                $tagRefs,
            );

            $blockers = [
                ...$blockers,
                ...$channelResolution['blockers'],
                ...$tagResolution['blockers'],
                ...$this->buttonBlockers($row, $channelResolution['channel_ids'], $availableChannels['by_id']),
            ];

            foreach ($channelResolution['unresolved'] as $unresolved) {
                $unresolvedChannels[$unresolved['excel_channel_key']] = $unresolved;
            }

            foreach ($tagResolution['unresolved'] as $unresolved) {
                $unresolvedTags[$unresolved['excel_tag_name']] = $unresolved;
            }

            $existingBlock = $sourceRuleId > 0 ? ($existingBlocks[$sourceRuleId] ?? null) : null;
            $operation = is_array($existingBlock) ? 'update' : 'create';

            $generatedBlock = null;
            $clientKey = '';
            $baseBlock = is_array($existingBlock) ? $existingBlock : [];

            if (is_array($existingBlock)) {
                $sheetId = (string) data_get($existingBlock, 'settings_payload.ui.sheet_id', 'main');
                $position = $this->positionFromBlock($existingBlock);
                $clientKey = (string) ($existingBlock['client_key'] ?? '');
                $generatedBlock = $this->buildBlock(
                    $row,
                    $clientKey,
                    $position,
                    $sheetId,
                    $channelResolution['channel_ids'],
                    $tagResolution['assign_tag_ids'],
                    $tagResolution['remove_tag_ids'],
                    $baseBlock,
                    $file->getClientOriginalName(),
                    $importBatchId,
                );
                $importedPayloadHash = $this->importedPayloadHash($generatedBlock);
                data_set($generatedBlock, 'settings_payload.ui.import_source.imported_payload_hash', $importedPayloadHash);

                $existingHash = (string) data_get($existingBlock, 'settings_payload.ui.import_source.imported_payload_hash', '');
                $currentImportedHash = $this->importedPayloadHash($existingBlock);
                $hasManualConflict = $existingHash !== ''
                    && $currentImportedHash !== ''
                    && ! hash_equals($existingHash, $currentImportedHash);

                if ($hasManualConflict && ! isset($overwriteRows[$rowNumber])) {
                    $summary['conflicts']++;
                    $rowResults[] = $this->rowResult($row, 'conflict', ['manual_edit_conflict'], $existingBlock);
                    continue;
                }
            }

            if ($blockers !== []) {
                $summary['blocked']++;
                $rowResults[] = $this->rowResult($row, 'blocked', array_values(array_unique($blockers)), $existingBlock);
                continue;
            }

            if (! is_array($generatedBlock)) {
                $sheetId = $this->sheetIdForNewBlock(
                    $builder,
                    $plannedSheets,
                    $placementMode,
                    (string) ($row['category_name'] ?? ''),
                    $importBatchId,
                );
                $position = $this->gridPosition(
                    $builder,
                    $sheetId,
                    $plannedSheets,
                    $placementMode,
                    (string) ($row['category_name'] ?? ''),
                    $newBlockLayout,
                );
                $clientKey = $this->uniqueClientKey("import_auto_reply_{$sourceRuleId}", $usedClientKeys);
                $generatedBlock = $this->buildBlock(
                    $row,
                    $clientKey,
                    $position,
                    $sheetId,
                    $channelResolution['channel_ids'],
                    $tagResolution['assign_tag_ids'],
                    $tagResolution['remove_tag_ids'],
                    $baseBlock,
                    $file->getClientOriginalName(),
                    $importBatchId,
                );
                $importedPayloadHash = $this->importedPayloadHash($generatedBlock);
                data_set($generatedBlock, 'settings_payload.ui.import_source.imported_payload_hash', $importedPayloadHash);
            }

            $planBlocks[] = [
                'operation' => $operation,
                'source_rule_id' => $sourceRuleId,
                'row_number' => $rowNumber,
                'client_key' => $clientKey,
                'block' => $generatedBlock,
            ];

            if ($operation === 'update') {
                $summary['updated']++;
            } else {
                $summary['created']++;
            }

            $rowResults[] = $this->rowResult($row, $operation, [], $existingBlock);
        }

        $canApply = $summary['blocked'] === 0
            && $summary['conflicts'] === 0
            && $planBlocks !== [];

        return [
            'scenario_id' => (int) $scenario->id,
            'source_workbook_key' => self::WORKBOOK_KEY,
            'source_file_name' => $file->getClientOriginalName(),
            'placement_mode' => $placementMode,
            'import_batch_id' => $importBatchId,
            'summary' => $summary,
            'can_apply' => $canApply,
            'warnings' => [
                'Старый модуль автоответов не отключается автоматически. Перед публикацией проверьте риск двойных ответов.',
            ],
            'available_channels' => $availableChannels['list'],
            'available_tags' => $availableTags['list'],
            'unresolved_channels' => array_values($unresolvedChannels),
            'unresolved_tags' => array_values($unresolvedTags),
            'rows' => $rowResults,
            'plan' => [
                'sheets' => array_values($plannedSheets),
                'blocks' => $planBlocks,
                'focus_block_client_key' => (string) ($planBlocks[0]['client_key'] ?? ''),
                'focus_sheet_id' => (string) data_get($planBlocks, '0.block.settings_payload.ui.sheet_id', 'main'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function builderPayload(array $payload): array
    {
        $state = $payload['builder_state'] ?? [];

        if (is_string($state)) {
            $decoded = json_decode($state, true);
            $state = is_array($decoded) ? $decoded : [];
        }

        if (is_array($state) && is_array($state['builder'] ?? null)) {
            return $state['builder'];
        }

        return is_array($state) ? $state : [];
    }

    /**
     * @return array{
     *     channels: array<string, int>,
     *     tags: array<string, int>
     * }
     */
    private function userMappings(array $payload): array
    {
        return [
            'channels' => collect($payload['channel_mappings'] ?? [])
                ->filter(fn (mixed $mapping): bool => is_array($mapping))
                ->mapWithKeys(fn (array $mapping): array => [
                    trim((string) ($mapping['excel_channel_key'] ?? '')) => (int) ($mapping['channel_id'] ?? 0),
                ])
                ->filter(fn (int $channelId, string $key): bool => $key !== '' && $channelId > 0)
                ->all(),
            'tags' => collect($payload['tag_mappings'] ?? [])
                ->filter(fn (mixed $mapping): bool => is_array($mapping))
                ->mapWithKeys(fn (array $mapping): array => [
                    $this->normalizeName((string) ($mapping['excel_tag_name'] ?? '')) => (int) ($mapping['tag_id'] ?? 0),
                ])
                ->filter(fn (int $tagId, string $key): bool => $key !== '' && $tagId > 0)
                ->all(),
        ];
    }

    /**
     * @return array<int, true>
     */
    private function integerSet(mixed $values): array
    {
        if (is_string($values)) {
            $decoded = json_decode($values, true);
            $values = is_array($decoded) ? $decoded : [];
        }

        return collect(is_array($values) ? $values : [])
            ->map(fn (mixed $value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->mapWithKeys(fn (int $value): array => [$value => true])
            ->all();
    }

    private function placementMode(mixed $value): string
    {
        $mode = trim((string) ($value ?? ''));

        return in_array($mode, [
            self::PLACEMENT_SINGLE_SHEET,
            self::PLACEMENT_BY_CATEGORY,
            self::PLACEMENT_CURRENT_SHEET,
        ], true)
            ? $mode
            : self::PLACEMENT_SINGLE_SHEET;
    }

    private function importBatchId(mixed $value): string
    {
        $batchId = trim((string) ($value ?? ''));

        if (
            $batchId !== ''
            && mb_strlen($batchId) <= 120
            && preg_match('/^[A-Za-z0-9_.:-]+$/', $batchId) === 1
        ) {
            return $batchId;
        }

        return 'auto_reply_xlsx_'.now()->format('Ymd_His').'_'.Str::lower(Str::random(6));
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     channel_refs: array<string, array<string, mixed>>,
     *     tag_refs: array<string, array<string, mixed>>
     * }
     */
    private function parseWorkbook(UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'workbook' => 'Не удалось прочитать XLSX-файл. Проверьте формат и попробуйте ещё раз.',
            ]);
        }

        try {
            $rulesSheet = $spreadsheet->getSheetByName(AutoReplyRuleWorkbookFormat::SHEET_RULES);

            if (! $rulesSheet instanceof Worksheet) {
                throw ValidationException::withMessages([
                    'workbook' => 'В файле отсутствует лист rules.',
                ]);
            }

            $rules = $rulesSheet->toArray(null, true, true, false);

            if ($rules === []) {
                throw ValidationException::withMessages([
                    'workbook' => 'Лист rules пуст.',
                ]);
            }

            $headerMap = $this->headerMap($rules[0] ?? []);
            $rows = [];

            foreach (array_slice($rules, 1, null, true) as $rowOffset => $row) {
                $rowNumber = $rowOffset + 1;
                $rowData = $this->rowData($row, $headerMap);

                if ($this->emptyRow($rowData)) {
                    continue;
                }

                $rows[] = $this->normalizeRuleRow($rowData, $rowNumber);
            }

            return [
                'rows' => $rows,
                'channel_refs' => $this->referenceRows($spreadsheet->getSheetByName(AutoReplyRuleWorkbookFormat::SHEET_CHANNELS), 'id'),
                'tag_refs' => $this->referenceRows($spreadsheet->getSheetByName(AutoReplyRuleWorkbookFormat::SHEET_TAGS), 'name'),
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array<string, int>
     */
    private function headerMap(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $index => $value) {
            $key = trim((string) $value);

            if ($key !== '') {
                $map[$key] = $index;
            }
        }

        $missing = array_values(array_diff(AutoReplyRuleWorkbookFormat::rulesColumns(), array_keys($map)));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'workbook' => 'В листе rules отсутствуют обязательные колонки: '.implode(', ', $missing).'.',
            ]);
        }

        return $map;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $headerMap
     * @return array<string, mixed>
     */
    private function rowData(array $row, array $headerMap): array
    {
        $data = [];

        foreach (AutoReplyRuleWorkbookFormat::rulesColumns() as $column) {
            $data[$column] = $row[$headerMap[$column]] ?? null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $rowData
     */
    private function emptyRow(array $rowData): bool
    {
        foreach ($rowData as $value) {
            if (trim((string) ($value ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $rowData
     * @return array<string, mixed>
     */
    private function normalizeRuleRow(array $rowData, int $rowNumber): array
    {
        $matchScope = $this->nullableString($rowData['match_scope'] ?? null) ?? '';
        $buttonKind = $this->nullableString($rowData['button_kind'] ?? null) ?? AutoReplyRuleWorkbookFormat::BUTTON_KIND_NONE;

        return [
            'row_number' => $rowNumber,
            'id' => $this->positiveInt($rowData['id'] ?? null),
            'name' => $this->nullableString($rowData['name'] ?? null),
            'category_name' => $this->nullableString($rowData['category_name'] ?? null) ?? 'Без категории',
            'is_active' => $this->boolValue($rowData['is_active'] ?? false),
            'priority' => $this->intValue($rowData['priority'] ?? 10, 10),
            'match_scope' => $matchScope,
            'keyword' => $this->nullableString($rowData['keyword'] ?? null),
            'contact_phone_condition' => $this->nullableString($rowData['contact_phone_condition'] ?? null) ?? '',
            'reply_text' => (string) ($rowData['reply_text'] ?? ''),
            'button_kind' => $buttonKind,
            'button_text' => $this->nullableString($rowData['button_text'] ?? null),
            'button_url' => $this->nullableString($rowData['button_url'] ?? null),
            'channel_keys' => AutoReplyRuleWorkbookFormat::parseList($rowData['channel_ids'] ?? null),
            'required_tag_names' => AutoReplyRuleWorkbookFormat::parseList($rowData['required_tag_names'] ?? null),
            'excluded_tag_names' => AutoReplyRuleWorkbookFormat::parseList($rowData['excluded_tag_names'] ?? null),
            'assign_tag_names' => AutoReplyRuleWorkbookFormat::parseList($rowData['assign_tag_names'] ?? null),
            'remove_tag_names' => AutoReplyRuleWorkbookFormat::parseList($rowData['remove_tag_names'] ?? null),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function referenceRows(?Worksheet $sheet, string $keyColumn): array
    {
        if (! $sheet instanceof Worksheet) {
            return [];
        }

        $rows = $sheet->toArray(null, true, true, false);

        if ($rows === []) {
            return [];
        }

        $header = [];

        foreach (($rows[0] ?? []) as $index => $value) {
            $name = trim((string) $value);

            if ($name !== '') {
                $header[$name] = $index;
            }
        }

        if (! array_key_exists($keyColumn, $header)) {
            return [];
        }

        $result = [];

        foreach (array_slice($rows, 1) as $row) {
            $key = trim((string) ($row[$header[$keyColumn]] ?? ''));

            if ($key === '') {
                continue;
            }

            $item = [];

            foreach ($header as $name => $index) {
                $item[$name] = $row[$index] ?? null;
            }

            $result[$keyColumn === 'name' ? $this->normalizeName($key) : $key] = $item;
        }

        return $result;
    }

    /**
     * @return array{list: list<array<string, mixed>>, by_id: array<int, Channel>}
     */
    private function availableChannels(User $user): array
    {
        $channels = Channel::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (Channel $channel): bool => $user->can('update', $channel))
            ->values();

        return [
            'list' => $channels
                ->map(fn (Channel $channel): array => [
                    'id' => (int) $channel->id,
                    'name' => (string) $channel->name,
                    'platform' => (string) $channel->platform,
                ])
                ->all(),
            'by_id' => $channels->keyBy(fn (Channel $channel): int => (int) $channel->id)->all(),
        ];
    }

    /**
     * @return array{list: list<array<string, mixed>>, by_name: array<string, Tag>}
     */
    private function availableTags(): array
    {
        $tags = Tag::query()
            ->active()
            ->orderBy('name')
            ->get();

        return [
            'list' => $tags
                ->map(fn (Tag $tag): array => [
                    'id' => (int) $tag->id,
                    'name' => (string) $tag->name,
                    'color' => (string) $tag->color,
                ])
                ->all(),
            'by_name' => $tags
                ->keyBy(fn (Tag $tag): string => $this->normalizeName((string) $tag->name))
                ->all(),
        ];
    }

    /**
     * @return array{channel_ids: list<int>, blockers: list<string>, unresolved: list<array<string, mixed>>}
     */
    private function resolveChannels(array $row, array $channelsById, array $mappings, array $channelRefs): array
    {
        $channelKeys = array_values(array_unique(array_map('strval', $row['channel_keys'] ?? [])));

        if ($channelKeys === []) {
            return [
                'channel_ids' => [],
                'blockers' => ['channel_required'],
                'unresolved' => [],
            ];
        }

        $ids = [];
        $blockers = [];
        $unresolved = [];

        foreach ($channelKeys as $channelKey) {
            $mappedId = $mappings[$channelKey] ?? null;
            $channelId = $mappedId !== null ? (int) $mappedId : (int) $channelKey;

            if ($channelId > 0 && isset($channelsById[$channelId])) {
                $ids[] = $channelId;
                continue;
            }

            $blockers[] = 'channel_not_mapped';
            $ref = $channelRefs[$channelKey] ?? [];
            $unresolved[] = [
                'excel_channel_key' => $channelKey,
                'name' => (string) ($ref['name'] ?? "Канал #{$channelKey}"),
                'platform' => (string) ($ref['platform'] ?? ''),
            ];
        }

        return [
            'channel_ids' => array_values(array_unique($ids)),
            'blockers' => array_values(array_unique($blockers)),
            'unresolved' => $unresolved,
        ];
    }

    /**
     * @return array{assign_tag_ids: list<int>, remove_tag_ids: list<int>, blockers: list<string>, unresolved: list<array<string, mixed>>}
     */
    private function resolveTags(array $row, array $tagsByName, array $mappings, array $tagRefs): array
    {
        $assign = $this->resolveTagList($row['assign_tag_names'] ?? [], $tagsByName, $mappings, $tagRefs, 'assign_tag_names');
        $remove = $this->resolveTagList($row['remove_tag_names'] ?? [], $tagsByName, $mappings, $tagRefs, 'remove_tag_names');

        return [
            'assign_tag_ids' => $assign['ids'],
            'remove_tag_ids' => $remove['ids'],
            'blockers' => array_values(array_unique([...$assign['blockers'], ...$remove['blockers']])),
            'unresolved' => [...$assign['unresolved'], ...$remove['unresolved']],
        ];
    }

    /**
     * @return array{ids: list<int>, blockers: list<string>, unresolved: list<array<string, mixed>>}
     */
    private function resolveTagList(array $names, array $tagsByName, array $mappings, array $tagRefs, string $column): array
    {
        $ids = [];
        $blockers = [];
        $unresolved = [];

        foreach (array_values(array_unique($names)) as $name) {
            $normalized = $this->normalizeName((string) $name);
            $mappedId = $mappings[$normalized] ?? null;

            if ($mappedId !== null) {
                $ids[] = (int) $mappedId;
                continue;
            }

            $tag = $tagsByName[$normalized] ?? null;

            if ($tag instanceof Tag) {
                $ids[] = (int) $tag->id;
                continue;
            }

            $blockers[] = 'tag_not_mapped';
            $ref = $tagRefs[$normalized] ?? [];
            $unresolved[] = [
                'excel_tag_name' => (string) $name,
                'column' => $column,
                'name' => (string) ($ref['name'] ?? $name),
            ];
        }

        return [
            'ids' => array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0))),
            'blockers' => array_values(array_unique($blockers)),
            'unresolved' => $unresolved,
        ];
    }

    /**
     * @return list<string>
     */
    private function buttonBlockers(array $row, array $channelIds, array $channelsById): array
    {
        $buttonKind = (string) ($row['button_kind'] ?? AutoReplyRuleWorkbookFormat::BUTTON_KIND_NONE);

        if ($buttonKind === AutoReplyRuleWorkbookFormat::BUTTON_KIND_NONE || $buttonKind === '') {
            return [];
        }

        if ($buttonKind === AutoReplyRuleWorkbookFormat::BUTTON_KIND_LINK) {
            if (($row['button_text'] ?? null) === null || ($row['button_url'] ?? null) === null) {
                return ['button_link_required'];
            }

            if (filter_var((string) $row['button_url'], FILTER_VALIDATE_URL) === false) {
                return ['button_url_invalid'];
            }
        }

        $unsupported = collect($channelIds)
            ->map(fn (int $id): ?Channel => $channelsById[$id] ?? null)
            ->filter()
            ->contains(fn (Channel $channel): bool => ! in_array($channel->platform, [
                Channel::PLATFORM_TELEGRAM,
                Channel::PLATFORM_MAX,
            ], true));

        return $unsupported ? ['button_kind_not_supported_for_channel'] : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function existingImportedBlocks(array $builder): array
    {
        $blocks = is_array($builder['blocks'] ?? null) ? $builder['blocks'] : [];
        $result = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (data_get($block, 'settings_payload.ui.import_source.type') !== 'auto_reply_rule_xlsx') {
                continue;
            }

            if (data_get($block, 'settings_payload.ui.import_source.source_workbook_key') !== self::WORKBOOK_KEY) {
                continue;
            }

            $sourceRuleId = (int) data_get($block, 'settings_payload.ui.import_source.source_rule_id', 0);

            if ($sourceRuleId > 0) {
                $result[$sourceRuleId] = $block;
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function plannedSheets(array $builder): array
    {
        $existing = collect(is_array($builder['sheets'] ?? null) ? $builder['sheets'] : [])
            ->filter(fn (mixed $sheet): bool => is_array($sheet) && trim((string) ($sheet['id'] ?? '')) !== '')
            ->keyBy(fn (array $sheet): string => (string) $sheet['id'])
            ->map(fn (array $sheet): array => [
                'operation' => 'use',
                'sheet' => $sheet,
            ])
            ->all();

        if (! isset($existing['main'])) {
            $existing['main'] = [
                'operation' => 'use',
                'sheet' => [
                    'id' => 'main',
                    'name' => 'Главный',
                    'color' => 'none',
                    'view' => ['tx' => 0, 'ty' => 0, 'zoom' => 1],
                ],
            ];
        }

        return $existing;
    }

    /**
     * @param  array<string, array<string, mixed>>  $plannedSheets
     */
    private function sheetIdForNewBlock(
        array $builder,
        array &$plannedSheets,
        string $placementMode,
        string $categoryName,
        string $importBatchId,
    ): string {
        if ($placementMode === self::PLACEMENT_CURRENT_SHEET) {
            $activeSheetId = trim((string) ($builder['active_sheet_id'] ?? 'main'));

            return isset($plannedSheets[$activeSheetId]) ? $activeSheetId : 'main';
        }

        if ($placementMode === self::PLACEMENT_BY_CATEGORY) {
            $sheetId = $this->sheetIdForCategory($categoryName);

            if (! isset($plannedSheets[$sheetId])) {
                $createdCount = collect($plannedSheets)
                    ->where('operation', 'create')
                    ->count();
                $plannedSheets[$sheetId] = [
                    'operation' => 'create',
                    'sheet' => [
                        'id' => $sheetId,
                        'name' => $this->uniqueSheetName(
                            mb_substr(trim($categoryName) !== '' ? trim($categoryName) : 'Без категории', 0, 40),
                            $plannedSheets,
                        ),
                        'color' => self::SHEET_COLORS[$createdCount % count(self::SHEET_COLORS)],
                        'view' => ['tx' => 0, 'ty' => 0, 'zoom' => 1],
                        'import_source' => [
                            'type' => 'auto_reply_rule_xlsx',
                            'created_batch_id' => $importBatchId,
                        ],
                    ],
                ];
            }

            return $sheetId;
        }

        $existingBatchSheetId = $this->plannedSheetIdForBatch($plannedSheets, $importBatchId);

        if ($existingBatchSheetId !== null) {
            return $existingBatchSheetId;
        }

        $sheetId = $this->uniqueSheetId($this->sheetIdForImportBatch($importBatchId), $plannedSheets);

        $plannedSheets[$sheetId] = [
            'operation' => 'create',
            'sheet' => [
                'id' => $sheetId,
                'name' => $this->uniqueSheetName(self::DEFAULT_IMPORT_SHEET_NAME, $plannedSheets),
                'color' => 'purple',
                'view' => ['tx' => 0, 'ty' => 0, 'zoom' => 1],
                'import_source' => [
                    'type' => 'auto_reply_rule_xlsx',
                    'created_batch_id' => $importBatchId,
                ],
            ],
        ];

        return $sheetId;
    }

    private function sheetIdForCategory(string $categoryName): string
    {
        $name = trim($categoryName) !== '' ? trim($categoryName) : 'Без категории';
        $slug = Str::slug($name, '_', 'ru');

        if ($slug === '') {
            $slug = substr(sha1($name), 0, 10);
        }

        $sheetId = 'import_'.$slug;

        if (strlen($sheetId) > 40) {
            $sheetId = substr($sheetId, 0, 29).'_'.substr(sha1($name), 0, 10);
        }

        return $sheetId;
    }

    private function sheetIdForImportBatch(string $importBatchId): string
    {
        $suffix = preg_replace('/[^a-z0-9_]+/', '_', Str::lower($importBatchId)) ?: substr(sha1($importBatchId), 0, 8);
        $suffix = trim($suffix, '_');

        if ($suffix === '') {
            $suffix = substr(sha1($importBatchId), 0, 8);
        }

        $suffix = substr($suffix, -14);

        return 'import_auto_reply_'.$suffix;
    }

    /**
     * @param  array<string, array<string, mixed>>  $plannedSheets
     */
    private function plannedSheetIdForBatch(array $plannedSheets, string $importBatchId): ?string
    {
        foreach ($plannedSheets as $sheetId => $item) {
            if (
                ($item['operation'] ?? 'use') === 'create'
                && data_get($item, 'sheet.import_source.type') === 'auto_reply_rule_xlsx'
                && (string) data_get($item, 'sheet.import_source.created_batch_id') === $importBatchId
            ) {
                return (string) $sheetId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $plannedSheets
     * @param  array<string, mixed>  $layout
     * @return array{x: int, y: int}
     */
    private function gridPosition(
        array $builder,
        string $sheetId,
        array $plannedSheets,
        string $placementMode,
        string $categoryName,
        array &$layout,
    ): array {
        $isNewSheet = ($plannedSheets[$sheetId]['operation'] ?? 'use') === 'create';
        $baseY = $isNewSheet ? self::IMPORT_LAYOUT_START_Y : $this->nextSheetBaseY($builder, $sheetId);

        if ($placementMode === self::PLACEMENT_SINGLE_SHEET || $placementMode === self::PLACEMENT_CURRENT_SHEET) {
            $categoryKey = $this->normalizeName(trim($categoryName) !== '' ? $categoryName : 'Без категории');
            $categoryKey = $categoryKey !== '' ? $categoryKey : 'no_category';
            $sheetCategoryKey = $sheetId.'|'.$categoryKey;

            if (! isset($layout['category_columns'][$sheetId][$categoryKey])) {
                $layout['category_columns'][$sheetId][$categoryKey] = count($layout['category_columns'][$sheetId] ?? []);
            }

            $columnIndex = (int) $layout['category_columns'][$sheetId][$categoryKey];
            $rowIndex = (int) ($layout['category_rows'][$sheetCategoryKey] ?? 0);
            $layout['category_rows'][$sheetCategoryKey] = $rowIndex + 1;

            return [
                'x' => self::IMPORT_LAYOUT_START_X + ($columnIndex * self::IMPORT_LAYOUT_COLUMN_GAP),
                'y' => $baseY + ($rowIndex * self::IMPORT_LAYOUT_ROW_GAP),
            ];
        }

        $index = (int) ($layout['sheet_rows'][$sheetId] ?? 0);
        $layout['sheet_rows'][$sheetId] = $index + 1;

        return [
            'x' => self::IMPORT_LAYOUT_START_X,
            'y' => $baseY + ($index * self::IMPORT_LAYOUT_ROW_GAP),
        ];
    }

    private function nextSheetBaseY(array $builder, string $sheetId): int
    {
        $blocks = is_array($builder['blocks'] ?? null) ? $builder['blocks'] : [];
        $maxY = null;

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if ((string) data_get($block, 'settings_payload.ui.sheet_id', 'main') !== $sheetId) {
                continue;
            }

            $y = (int) data_get($block, 'position.y', 80);
            $maxY = $maxY === null ? $y : max($maxY, $y);
        }

        return $maxY === null ? self::IMPORT_LAYOUT_START_Y : $maxY + self::IMPORT_LAYOUT_ROW_GAP;
    }

    /**
     * @param  array<string, array<string, mixed>>  $plannedSheets
     */
    private function uniqueSheetId(string $baseId, array $plannedSheets): string
    {
        $base = substr($baseId, 0, 40);
        $candidate = $base;
        $suffix = 2;

        while (isset($plannedSheets[$candidate])) {
            $tail = '_'.$suffix;
            $candidate = substr($base, 0, 40 - strlen($tail)).$tail;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, array<string, mixed>>  $plannedSheets
     */
    private function uniqueSheetName(string $baseName, array $plannedSheets): string
    {
        $base = trim($baseName) !== '' ? trim($baseName) : self::DEFAULT_IMPORT_SHEET_NAME;
        $base = mb_substr($base, 0, 40);
        $used = collect($plannedSheets)
            ->map(fn (array $item): string => (string) data_get($item, 'sheet.name', ''))
            ->filter()
            ->map(fn (string $name): string => mb_strtolower($name))
            ->all();
        $candidate = $base;
        $suffix = 2;

        while (in_array(mb_strtolower($candidate), $used, true)) {
            $tail = ' '.$suffix;
            $candidate = mb_substr($base, 0, 40 - mb_strlen($tail)).$tail;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @return array{x: int, y: int}
     */
    private function positionFromBlock(array $block): array
    {
        return [
            'x' => (int) data_get($block, 'position.x', 80),
            'y' => (int) data_get($block, 'position.y', 80),
        ];
    }

    /**
     * @param  list<string>  $usedClientKeys
     */
    private function uniqueClientKey(string $base, array &$usedClientKeys): string
    {
        $key = preg_replace('/[^A-Za-z0-9_]+/', '_', $base) ?: 'import_auto_reply';
        $candidate = $key;
        $suffix = 2;

        while (in_array($candidate, $usedClientKeys, true)) {
            $candidate = $key.'_'.$suffix;
            $suffix++;
        }

        $usedClientKeys[] = $candidate;

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBlock(
        array $row,
        string $clientKey,
        array $position,
        string $sheetId,
        array $channelIds,
        array $assignTagIds,
        array $removeTagIds,
        array $baseBlock,
        string $sourceFileName,
        string $importBatchId,
    ): array {
        $sourceRuleId = (int) ($row['id'] ?? 0);
        $baseSettings = is_array($baseBlock['settings_payload'] ?? null) ? $baseBlock['settings_payload'] : [];
        $baseUi = is_array($baseSettings['ui'] ?? null) ? $baseSettings['ui'] : [];
        $baseImportSource = is_array($baseUi['import_source'] ?? null) ? $baseUi['import_source'] : [];
        $createdBatchId = trim((string) ($baseImportSource['created_batch_id'] ?? ''));

        if ($createdBatchId === '') {
            $createdBatchId = $importBatchId;
        }

        $modules = [
            $this->startModule($row, $channelIds),
        ];

        if ($assignTagIds !== [] || $removeTagIds !== []) {
            $modules[] = [
                'id' => 'mod_action',
                'type' => 'action',
                'enabled' => true,
                'payload' => [
                    'actions' => [[
                        'type' => 'tag_effects',
                        'assign_tag_ids' => $assignTagIds,
                        'remove_tag_ids' => $removeTagIds,
                    ]],
                ],
            ];
        }

        $modules[] = [
            'id' => 'mod_message',
            'type' => 'message',
            'enabled' => true,
            'payload' => [
                'text' => (string) ($row['reply_text'] ?? ''),
                'text_format' => 'plain_text',
            ],
        ];

        $buttonModule = $this->buttonModule($row);

        if ($buttonModule !== null) {
            $modules[] = $buttonModule;
        }

        return [
            'id' => $baseBlock['id'] ?? null,
            'client_key' => $clientKey,
            'type' => 'state',
            'title' => $row['name'] ?: "Автоответ #{$sourceRuleId}",
            'position' => $position,
            'settings_payload' => [
                'schema_version' => 3,
                'kind' => 'state',
                'ui' => [
                    'sheet_id' => $sheetId,
                    'width' => $baseUi['width'] ?? 320,
                    'collapsed' => (bool) ($baseUi['collapsed'] ?? false),
                    'card_id' => $baseUi['card_id'] ?? '',
                    'display_number' => $baseUi['display_number'] ?? '',
                    'import_source' => [
                        'type' => 'auto_reply_rule_xlsx',
                        'source_workbook_key' => self::WORKBOOK_KEY,
                        'source_rule_id' => $sourceRuleId,
                        'created_batch_id' => $createdBatchId,
                        'last_import_batch_id' => $importBatchId,
                        'source_row_hash' => $this->rowHash($row),
                        'imported_payload_hash' => '',
                        'source_rule_name' => (string) ($row['name'] ?? ''),
                        'source_row_number' => (int) ($row['row_number'] ?? 0),
                        'source_file_name' => $sourceFileName,
                    ],
                ],
                'modules' => $modules,
                'outputs' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function startModule(array $row, array $channelIds): array
    {
        $match = (string) ($row['match_scope'] ?? AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER);
        $command = $match === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND
            ? ''
            : (string) ($row['keyword'] ?? '');

        return [
            'id' => 'mod_start',
            'type' => 'start_condition',
            'enabled' => true,
            'payload' => [
                'command' => $command,
                'values' => [],
                'match' => $match,
                'variable' => '',
                'exclude' => '',
                'contact_phone_condition' => (string) ($row['contact_phone_condition'] ?? ''),
                'dialog_phone_condition' => '',
                'priority' => (int) ($row['priority'] ?? 10),
                'once' => false,
                'channels' => [
                    'mode' => 'selected',
                    'ids' => $channelIds,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buttonModule(array $row): ?array
    {
        $buttonKind = (string) ($row['button_kind'] ?? AutoReplyRuleWorkbookFormat::BUTTON_KIND_NONE);

        if ($buttonKind === AutoReplyRuleWorkbookFormat::BUTTON_KIND_NONE || $buttonKind === '') {
            return null;
        }

        $button = [
            'id' => 'btn_1',
            'text' => (string) ($row['button_text'] ?? ''),
            'type' => $buttonKind === AutoReplyRuleWorkbookFormat::BUTTON_KIND_LINK ? 'link' : 'request_phone',
            'fn' => 'default',
            'url' => $buttonKind === AutoReplyRuleWorkbookFormat::BUTTON_KIND_LINK ? (string) ($row['button_url'] ?? '') : null,
            'color' => null,
        ];

        if ($button['type'] === 'request_phone' && trim($button['text']) === '') {
            $button['text'] = 'Поделиться номером телефона';
        }

        return [
            'id' => 'mod_buttons',
            'type' => 'buttons',
            'enabled' => true,
            'payload' => [
                'placement' => 'auto',
                'rows' => [[$button]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowResult(array $row, string $status, array $reasons, ?array $existingBlock): array
    {
        return [
            'row_number' => (int) ($row['row_number'] ?? 0),
            'source_rule_id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'status' => $status,
            'reasons' => $reasons,
            'existing_block_client_key' => is_array($existingBlock) ? (string) ($existingBlock['client_key'] ?? '') : '',
        ];
    }

    private function rowHash(array $row): string
    {
        return $this->canonicalHash(Arr::except($row, ['row_number']));
    }

    private function importedPayloadHash(array $block): string
    {
        return $this->canonicalHash([
            'title' => (string) ($block['title'] ?? ''),
            'type' => (string) ($block['type'] ?? 'state'),
            'modules' => data_get($block, 'settings_payload.modules', []),
            'outputs' => data_get($block, 'settings_payload.outputs', []),
        ]);
    }

    private function canonicalHash(mixed $value): string
    {
        $normalized = $this->sortRecursive($value);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursive($item);
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $int = $this->intValue($value, 0);

        return $int > 0 ? $int : null;
    }

    private function intValue(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && fmod($value, 1.0) === 0.0) {
            return (int) $value;
        }

        $text = trim((string) ($value ?? ''));

        return preg_match('/^-?\d+$/', $text) === 1 ? (int) $text : $default;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'yes', 'да'], true);
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
