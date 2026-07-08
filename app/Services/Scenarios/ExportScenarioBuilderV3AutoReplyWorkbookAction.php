<?php

namespace App\Services\Scenarios;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Scenario;
use App\Models\Tag;
use App\Models\User;
use App\Services\AutoReplyRules\AutoReplyRuleWorkbookFormat;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExportScenarioBuilderV3AutoReplyWorkbookAction
{
    private const MATCH_EXACT_CALLBACK = 'exact_callback';

    public function __construct(
        private readonly BuildScenarioBuilderV3StateAction $buildScenarioBuilderV3StateAction,
    ) {}

    public function handle(Scenario $scenario, User $user, mixed $requestedSheetId = null): Spreadsheet
    {
        $state = $this->buildScenarioBuilderV3StateAction->handle(
            $scenario->fresh(['draftVersion', 'publishedVersion']),
            $user,
        );
        $builder = is_array($state['builder'] ?? null) ? $state['builder'] : [];
        $sheets = $this->sheets($builder);
        $sheetId = $this->requestedSheetId($sheets, $requestedSheetId);
        $exportSheets = $sheetId !== null ? [$sheetId => $sheets[$sheetId]] : $sheets;
        $tagsById = $this->tagsById();
        $spreadsheet = new Spreadsheet;

        $this->buildRulesSheet($spreadsheet->getActiveSheet(), $builder, $exportSheets, $tagsById, $sheetId);
        $this->buildCategoriesSheet($spreadsheet, $exportSheets);
        $this->buildChannelsSheet($spreadsheet);
        $this->buildTagsSheet($spreadsheet, $tagsById);
        $this->buildInstructionsSheet($spreadsheet);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  array<string, mixed>  $builder
     * @param  array<string, array<string, mixed>>  $sheets
     * @param  array<int, Tag>  $tagsById
     */
    private function buildRulesSheet(Worksheet $sheet, array $builder, array $sheets, array $tagsById, ?string $sheetId): void
    {
        $sheet->setTitle(AutoReplyRuleWorkbookFormat::SHEET_RULES);
        $this->writeRow($sheet, 1, AutoReplyRuleWorkbookFormat::rulesColumns());

        $rowIndex = 2;

        foreach ($this->autoReplyBlocks($builder, $sheetId) as $block) {
            $this->writeRow($sheet, $rowIndex, $this->ruleRow($block, $sheets, $tagsById));
            $rowIndex++;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $sheets
     */
    private function buildCategoriesSheet(Spreadsheet $spreadsheet, array $sheets): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(AutoReplyRuleWorkbookFormat::SHEET_CATEGORIES);
        $this->writeRow($sheet, 1, ['id', 'name', 'sort_order']);

        $rowIndex = 2;
        $sortOrder = 1;

        foreach ($sheets as $sheetId => $builderSheet) {
            $this->writeRow($sheet, $rowIndex, [
                $sheetId,
                (string) ($builderSheet['name'] ?? $sheetId),
                $sortOrder,
            ]);
            $rowIndex++;
            $sortOrder++;
        }
    }

    private function buildChannelsSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(AutoReplyRuleWorkbookFormat::SHEET_CHANNELS);
        $this->writeRow($sheet, 1, ['id', 'name', 'platform']);

        $rowIndex = 2;

        foreach (Channel::query()->orderBy('name')->orderBy('id')->get() as $channel) {
            $this->writeRow($sheet, $rowIndex, [
                (int) $channel->id,
                (string) $channel->name,
                (string) $channel->platform,
            ]);
            $rowIndex++;
        }
    }

    /**
     * @param  array<int, Tag>  $tagsById
     */
    private function buildTagsSheet(Spreadsheet $spreadsheet, array $tagsById): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(AutoReplyRuleWorkbookFormat::SHEET_TAGS);
        $this->writeRow($sheet, 1, ['id', 'name']);

        $rowIndex = 2;

        foreach ($tagsById as $tag) {
            $this->writeRow($sheet, $rowIndex, [
                (int) $tag->id,
                (string) $tag->name,
            ]);
            $rowIndex++;
        }
    }

    private function buildInstructionsSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(AutoReplyRuleWorkbookFormat::SHEET_INSTRUCTIONS);
        $this->writeRow($sheet, 1, ['rule']);

        $lines = [
            'Файл сформирован из V3-конструктора. id — пользовательский номер блока.',
            'Импорт в V3 поддерживает required_tag_names как has_all и excluded_tag_names как has_none.',
            'Одновременное заполнение required_tag_names и excluded_tag_names в одной строке пока не поддержано.',
            ...AutoReplyRuleWorkbookFormat::instructionLines(),
        ];
        $rowIndex = 2;

        foreach ($lines as $line) {
            $this->writeRow($sheet, $rowIndex, [$line]);
            $rowIndex++;
        }
    }

    /**
     * @param  list<mixed>  $values
     */
    private function writeRow(Worksheet $sheet, int $rowIndex, array $values): void
    {
        foreach (array_values($values) as $offset => $value) {
            $cell = Coordinate::stringFromColumnIndex($offset + 1).$rowIndex;

            if (is_string($value)) {
                $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);

                continue;
            }

            $sheet->setCellValue($cell, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $builder
     * @return list<array<string, mixed>>
     */
    private function autoReplyBlocks(array $builder, ?string $sheetId = null): array
    {
        $blocks = collect(is_array($builder['blocks'] ?? null) ? $builder['blocks'] : [])
            ->filter(fn (mixed $block): bool => is_array($block) && $this->startModule($block) !== null)
            ->filter(fn (array $block): bool => $sheetId === null || $this->blockSheetId($block) === $sheetId)
            ->values()
            ->all();

        usort($blocks, fn (array $left, array $right): int => $this->compareBlocks($left, $right));

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareBlocks(array $left, array $right): int
    {
        $leftPriority = $this->priority($left);
        $rightPriority = $this->priority($right);

        if ($leftPriority !== $rightPriority) {
            return $rightPriority <=> $leftPriority;
        }

        $leftDisplayOrder = $this->displayOrder($left);
        $rightDisplayOrder = $this->displayOrder($right);

        if ($leftDisplayOrder !== $rightDisplayOrder) {
            return $rightDisplayOrder <=> $leftDisplayOrder;
        }

        return strnatcmp($this->displayId($right), $this->displayId($left));
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, array<string, mixed>>  $sheets
     * @param  array<int, Tag>  $tagsById
     * @return list<string|int|null>
     */
    private function ruleRow(array $block, array $sheets, array $tagsById): array
    {
        $start = $this->startModule($block) ?? [];
        $message = $this->messageText($block);
        $button = $this->buttonConfig($block);
        $tagCondition = $this->tagConditionNames($start, $tagsById);
        $tagEffects = $this->tagEffectNames($block, $tagsById);
        $match = $this->matchScope($start);

        return [
            $this->displayNumericId($block),
            (string) ($block['title'] ?? 'Автоответ'),
            $this->categoryName($block, $sheets),
            $this->startEnabled($start) ? '1' : '0',
            $this->priority($block),
            $match,
            $match === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND ? '' : $this->keyword($start),
            $this->phoneCondition(data_get($start, 'payload.contact_phone_condition', '')),
            $message,
            $button['button_kind'],
            $button['button_text'],
            $button['button_url'],
            AutoReplyRuleWorkbookFormat::formatList($this->channelIds($start)),
            AutoReplyRuleWorkbookFormat::formatList($tagCondition['required']),
            AutoReplyRuleWorkbookFormat::formatList($tagCondition['excluded']),
            AutoReplyRuleWorkbookFormat::formatList($tagEffects['assign']),
            AutoReplyRuleWorkbookFormat::formatList($tagEffects['remove']),
        ];
    }

    /**
     * @param  array<string, mixed>  $builder
     * @return array<string, array<string, mixed>>
     */
    private function sheets(array $builder): array
    {
        $sheets = collect(is_array($builder['sheets'] ?? null) ? $builder['sheets'] : [])
            ->filter(fn (mixed $sheet): bool => is_array($sheet) && trim((string) ($sheet['id'] ?? '')) !== '')
            ->keyBy(fn (array $sheet): string => (string) $sheet['id'])
            ->all();

        if (! isset($sheets['main'])) {
            $sheets = [
                'main' => [
                    'id' => 'main',
                    'name' => 'Главный',
                ],
                ...$sheets,
            ];
        }

        return $sheets;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sheets
     */
    private function requestedSheetId(array $sheets, mixed $requestedSheetId): ?string
    {
        $sheetId = trim((string) $requestedSheetId);

        if ($sheetId === '') {
            return null;
        }

        if (! isset($sheets[$sheetId])) {
            throw ValidationException::withMessages([
                'sheet_id' => 'Лист для экспорта не найден.',
            ]);
        }

        return $sheetId;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function blockSheetId(array $block): string
    {
        $sheetId = trim((string) data_get($block, 'settings_payload.ui.sheet_id', 'main'));

        return $sheetId !== '' ? $sheetId : 'main';
    }

    /**
     * @return array<int, Tag>
     */
    private function tagsById(): array
    {
        return Tag::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Tag $tag): int => (int) $tag->id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function startModule(array $block): ?array
    {
        foreach ($this->modules($block) as $module) {
            if (($module['type'] ?? null) === 'start_condition') {
                return $module;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    private function modules(array $block): array
    {
        return collect(data_get($block, 'settings_payload.modules', []))
            ->filter(fn (mixed $module): bool => is_array($module))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function displayNumericId(array $block): int
    {
        $displayId = $this->displayId($block);

        if (ctype_digit($displayId) && (int) $displayId > 0) {
            return (int) $displayId;
        }

        return max(1, (int) ($block['id'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function displayId(array $block): string
    {
        $displayId = trim((string) ($block['display_id'] ?? data_get($block, 'settings_payload.ui.display_number', '')));

        if ($displayId !== '') {
            return $displayId;
        }

        $cardId = trim((string) data_get($block, 'settings_payload.ui.card_id', ''));

        return $cardId !== '' ? $cardId : trim((string) ($block['id'] ?? $block['client_key'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function displayOrder(array $block): int
    {
        $displayId = $this->displayId($block);

        return ctype_digit($displayId) ? (int) $displayId : PHP_INT_MIN;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function priority(array $block): int
    {
        return max(1, min(100, (int) data_get($this->startModule($block), 'payload.priority', 10)));
    }

    /**
     * @param  array<string, mixed>  $start
     */
    private function startEnabled(array $start): bool
    {
        return ($start['enabled'] ?? true) !== false;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, array<string, mixed>>  $sheets
     */
    private function categoryName(array $block, array $sheets): string
    {
        $sheetId = (string) data_get($block, 'settings_payload.ui.sheet_id', 'main');
        $sheet = $sheets[$sheetId] ?? null;

        return (string) ($sheet['name'] ?? ($sheetId === 'main' ? 'Главный' : $sheetId));
    }

    /**
     * @param  array<string, mixed>  $start
     */
    private function matchScope(array $start): string
    {
        $match = trim((string) data_get($start, 'payload.match', AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD));

        return match ($match) {
            'strict' => AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
            'contains' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
            'exact' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
            AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
            AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            self::MATCH_EXACT_CALLBACK => $match,
            default => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
        };
    }

    /**
     * @param  array<string, mixed>  $start
     */
    private function keyword(array $start): string
    {
        $command = trim((string) data_get($start, 'payload.command', ''));

        if ($command !== '') {
            return $command;
        }

        $values = data_get($start, 'payload.values', []);

        if (! is_array($values)) {
            return '';
        }

        foreach ($values as $value) {
            $text = trim((string) $value);

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $start
     * @return list<string>
     */
    private function channelIds(array $start): array
    {
        return collect(data_get($start, 'payload.channels.ids', []))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->map(fn (int $id): string => (string) $id)
            ->all();
    }

    private function phoneCondition(mixed $value): string
    {
        $condition = trim((string) $value);

        return in_array($condition, [
            AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
        ], true) ? $condition : '';
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function messageText(array $block): string
    {
        foreach ($this->modules($block) as $module) {
            if (($module['type'] ?? null) !== 'message' || ($module['enabled'] ?? true) === false) {
                continue;
            }

            $text = (string) data_get($module, 'payload.text', '');

            return $text !== '' ? $text : (string) data_get($module, 'payload.fallback_text', '');
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{button_kind:string,button_text:?string,button_url:?string}
     */
    private function buttonConfig(array $block): array
    {
        foreach ($this->modules($block) as $module) {
            if (($module['type'] ?? null) !== 'buttons' || ($module['enabled'] ?? true) === false) {
                continue;
            }

            foreach ($this->flatButtons(data_get($module, 'payload.rows', [])) as $button) {
                $type = (string) ($button['type'] ?? '');

                if ($type === 'link') {
                    return [
                        'button_kind' => AutoReplyRuleWorkbookFormat::BUTTON_KIND_LINK,
                        'button_text' => (string) ($button['text'] ?? ''),
                        'button_url' => (string) ($button['url'] ?? ''),
                    ];
                }

                if ($type === 'request_phone') {
                    return [
                        'button_kind' => AutoReplyRuleWorkbookFormat::BUTTON_KIND_REQUEST_PHONE,
                        'button_text' => (string) ($button['text'] ?? ''),
                        'button_url' => null,
                    ];
                }
            }
        }

        return [
            'button_kind' => AutoReplyRuleWorkbookFormat::BUTTON_KIND_NONE,
            'button_text' => null,
            'button_url' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function flatButtons(mixed $rows): array
    {
        return collect(is_array($rows) ? $rows : [])
            ->flatMap(fn (mixed $row): array => is_array($row) ? $row : [])
            ->filter(fn (mixed $button): bool => is_array($button))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $start
     * @param  array<int, Tag>  $tagsById
     * @return array{required: list<string>, excluded: list<string>}
     */
    private function tagConditionNames(array $start, array $tagsById): array
    {
        $condition = data_get($start, 'payload.tag_condition', []);

        if (! is_array($condition) || ($condition['enabled'] ?? false) !== true) {
            return ['required' => [], 'excluded' => []];
        }

        $names = $this->tagNames(data_get($condition, 'tag_ids', []), $tagsById);
        $mode = (string) ($condition['mode'] ?? 'has_all');

        return match ($mode) {
            'has_all' => ['required' => $names, 'excluded' => []],
            'has_none' => ['required' => [], 'excluded' => $names],
            default => ['required' => [], 'excluded' => []],
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<int, Tag>  $tagsById
     * @return array{assign: list<string>, remove: list<string>}
     */
    private function tagEffectNames(array $block, array $tagsById): array
    {
        $assign = [];
        $remove = [];

        foreach ($this->modules($block) as $module) {
            if (($module['type'] ?? null) !== 'action' || ($module['enabled'] ?? true) === false) {
                continue;
            }

            $actions = data_get($module, 'payload.actions', []);

            foreach (is_array($actions) ? $actions : [] as $action) {
                if (! is_array($action) || ($action['type'] ?? null) !== 'tag_effects') {
                    continue;
                }

                $assign = [...$assign, ...$this->tagNames($action['assign_tag_ids'] ?? [], $tagsById)];
                $remove = [...$remove, ...$this->tagNames($action['remove_tag_ids'] ?? [], $tagsById)];
            }
        }

        return [
            'assign' => array_values(array_unique($assign)),
            'remove' => array_values(array_unique($remove)),
        ];
    }

    /**
     * @param  array<int, Tag>  $tagsById
     * @return list<string>
     */
    private function tagNames(mixed $ids, array $tagsById): array
    {
        return collect(is_array($ids) ? $ids : [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => isset($tagsById[$id]))
            ->map(fn (int $id): string => (string) $tagsById[$id]->name)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
