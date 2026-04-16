<?php

namespace App\Services\AutoReplyRules;

use App\Data\AutoReplyRules\AutoReplyRuleWorkbookPreviewData;
use App\Data\AutoReplyRules\AutoReplyRuleWorkbookRowData;
use App\Data\AutoReplyRules\AutoReplyRuleWorkbookRowErrorData;
use App\Models\AutoReplyCategory;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Tag;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class ParseAutoReplyRulesWorkbookAction
{
    public function handle(string $path): AutoReplyRuleWorkbookPreviewData
    {
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'workbook' => 'Не удалось прочитать XLSX-файл. Проверьте формат и попробуйте ещё раз.',
            ]);
        }

        try {
            $sheet = $spreadsheet->getSheetByName(AutoReplyRuleWorkbookFormat::SHEET_RULES);

            if (! $sheet instanceof Worksheet) {
                throw ValidationException::withMessages([
                    'workbook' => 'В файле отсутствует лист rules.',
                ]);
            }

            $rows = $sheet->toArray(null, true, true, false);

            if ($rows === []) {
                throw ValidationException::withMessages([
                    'workbook' => 'Лист rules пуст.',
                ]);
            }

            $headerMap = $this->resolveHeaderMap($rows[0] ?? []);
            $lookups = $this->prepareLookups(array_slice($rows, 1), $headerMap);

            $createRows = [];
            $updateRows = [];
            $errors = [];

            foreach (array_slice($rows, 1, null, true) as $rowOffset => $row) {
                $rowNumber = $rowOffset + 1;
                $rowData = $this->extractRowData($row, $headerMap);

                if ($this->isEmptyRow($rowData)) {
                    continue;
                }

                try {
                    $parsedRow = $this->parseRow($rowData, $rowNumber, $lookups);

                    if ($parsedRow->id === null) {
                        $createRows[] = $parsedRow;
                    } else {
                        $updateRows[] = $parsedRow;
                    }
                } catch (ValidationException $exception) {
                    $errors = [
                        ...$errors,
                        ...$this->buildRowErrors($rowNumber, $exception),
                    ];
                }
            }

            $errors = [
                ...$errors,
                ...$this->filterRowsWithUniquenessConflicts($createRows, $updateRows),
            ];

            return new AutoReplyRuleWorkbookPreviewData($createRows, $updateRows, $errors);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array<string, int>
     */
    protected function resolveHeaderMap(array $headerRow): array
    {
        $headerMap = [];

        foreach ($headerRow as $index => $value) {
            $column = trim((string) $value);

            if ($column === '') {
                continue;
            }

            $headerMap[$column] = $index;
        }

        $missingColumns = array_values(array_diff(
            AutoReplyRuleWorkbookFormat::rulesColumns(),
            array_keys($headerMap),
        ));

        if ($missingColumns !== []) {
            throw ValidationException::withMessages([
                'workbook' => 'В листе rules отсутствуют обязательные колонки: '.implode(', ', $missingColumns).'.',
            ]);
        }

        return $headerMap;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, int>  $headerMap
     * @return array<string, mixed>
     */
    protected function prepareLookups(array $rows, array $headerMap): array
    {
        $ruleIds = [];
        $categoryNames = [];
        $channelIds = [];
        $tagNames = [];

        foreach ($rows as $row) {
            $rowData = $this->extractRowData($row, $headerMap);

            if ($this->isEmptyRow($rowData)) {
                continue;
            }

            $ruleId = $this->parseNullableInteger($rowData['id'] ?? null);

            if ($ruleId !== null) {
                $ruleIds[] = $ruleId;
            }

            $categoryName = AutoReplyRuleWorkbookFormat::normalizeNullableString($rowData['category_name'] ?? null);

            if ($categoryName !== null) {
                $categoryNames[] = $categoryName;
            }

            foreach (AutoReplyRuleWorkbookFormat::parseList($rowData['channel_ids'] ?? null) as $channelId) {
                $parsedChannelId = $this->parseNullableInteger($channelId);

                if ($parsedChannelId !== null) {
                    $channelIds[] = $parsedChannelId;
                }
            }

            foreach ([
                'required_tag_names',
                'excluded_tag_names',
                'assign_tag_names',
                'remove_tag_names',
            ] as $column) {
                foreach (AutoReplyRuleWorkbookFormat::parseList($rowData[$column] ?? null) as $tagName) {
                    $tagNames[] = $tagName;
                }
            }
        }

        $rules = AutoReplyRule::query()
            ->with(['tagConditions', 'tagEffects'])
            ->whereKey(array_values(array_unique($ruleIds)))
            ->get()
            ->keyBy(fn (AutoReplyRule $rule): int => (int) $rule->id)
            ->all();

        $categories = AutoReplyCategory::query()
            ->whereIn('name', array_values(array_unique($categoryNames)))
            ->get()
            ->mapWithKeys(fn (AutoReplyCategory $category): array => [
                $category->name => (int) $category->id,
            ])
            ->all();

        $channels = Channel::query()
            ->whereIn('id', array_values(array_unique($channelIds)))
            ->get()
            ->keyBy(fn (Channel $channel): int => (int) $channel->id)
            ->all();

        $tagsByName = Tag::query()
            ->whereIn('name', array_values(array_unique($tagNames)))
            ->get()
            ->groupBy('name')
            ->all();

        return [
            'rules' => $rules,
            'categories' => $categories,
            'channels' => $channels,
            'tags_by_name' => $tagsByName,
        ];
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $headerMap
     * @return array<string, mixed>
     */
    protected function extractRowData(array $row, array $headerMap): array
    {
        $data = [];

        foreach (AutoReplyRuleWorkbookFormat::rulesColumns() as $column) {
            $data[$column] = $row[$headerMap[$column]] ?? null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $rowData
     * @param  array<string, mixed>  $lookups
     */
    protected function parseRow(array $rowData, int $rowNumber, array $lookups): AutoReplyRuleWorkbookRowData
    {
        $ruleId = $this->parseNullableInteger($rowData['id'] ?? null);
        /** @var AutoReplyRule|null $existingRule */
        $existingRule = $ruleId !== null
            ? ($lookups['rules'][$ruleId] ?? null)
            : null;

        if ($ruleId !== null && ! $existingRule instanceof AutoReplyRule) {
            throw ValidationException::withMessages([
                'id' => 'Правило с указанным ID не найдено.',
            ]);
        }

        $name = AutoReplyRuleWorkbookFormat::normalizeNullableString($rowData['name'] ?? null);

        if ($name !== null && mb_strlen($name) > 255) {
            throw ValidationException::withMessages([
                'name' => 'Название правила не должно быть длиннее 255 символов.',
            ]);
        }

        $categoryName = AutoReplyRuleWorkbookFormat::normalizeNullableString($rowData['category_name'] ?? null);
        $autoReplyCategoryId = null;

        if ($categoryName !== null) {
            $autoReplyCategoryId = $lookups['categories'][$categoryName] ?? null;

            if (! is_int($autoReplyCategoryId) || $autoReplyCategoryId <= 0) {
                throw ValidationException::withMessages([
                    'category_name' => 'Указанная категория не найдена.',
                ]);
            }
        }

        $isActive = $this->parseBoolean($rowData['is_active'] ?? null, 'is_active');
        $priority = $this->parseRequiredInteger($rowData['priority'] ?? null, 'priority');
        $matchScope = AutoReplyRuleWorkbookFormat::normalizeNullableString($rowData['match_scope'] ?? null);

        if ($matchScope === null || ! array_key_exists($matchScope, AutoReplyRule::matchScopeOptions())) {
            throw ValidationException::withMessages([
                'match_scope' => 'Укажите допустимое значение match_scope.',
            ]);
        }

        $keyword = AutoReplyRuleWorkbookFormat::normalizeNullableString($rowData['keyword'] ?? null);

        if ($matchScope === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND) {
            $keyword = null;
        } elseif ($keyword === null) {
            throw ValidationException::withMessages([
                'keyword' => 'Для выбранного match_scope нужно заполнить keyword.',
            ]);
        } elseif (mb_strlen($keyword) > 255) {
            throw ValidationException::withMessages([
                'keyword' => 'Keyword не должен быть длиннее 255 символов.',
            ]);
        }

        $contactPhoneCondition = AutoReplyRuleWorkbookFormat::normalizeNullableString($rowData['contact_phone_condition'] ?? null);

        if ($contactPhoneCondition !== null && ! array_key_exists($contactPhoneCondition, AutoReplyRule::phoneConditionOptions())) {
            throw ValidationException::withMessages([
                'contact_phone_condition' => 'Укажите допустимое значение contact_phone_condition.',
            ]);
        }

        $replyText = (string) ($rowData['reply_text'] ?? '');

        if (trim($replyText) === '') {
            throw ValidationException::withMessages([
                'reply_text' => 'Текст ответа обязателен.',
            ]);
        }

        if (mb_strlen($replyText) > 2000) {
            throw ValidationException::withMessages([
                'reply_text' => 'Текст ответа не должен быть длиннее 2000 символов.',
            ]);
        }

        $buttonKind = $this->resolveButtonKind($rowData['button_kind'] ?? null);
        $buttonText = AutoReplyRuleWorkbookFormat::normalizeNullableString($rowData['button_text'] ?? null);
        $buttonUrl = AutoReplyRuleWorkbookFormat::normalizeNullableString($rowData['button_url'] ?? null);

        if ($buttonKind === AutoReplyRuleWorkbookFormat::BUTTON_KIND_LINK) {
            if ($buttonText === null || $buttonUrl === null) {
                throw ValidationException::withMessages([
                    'button_kind' => 'Для кнопки-ссылки заполните button_text и button_url.',
                ]);
            }

            if (filter_var($buttonUrl, FILTER_VALIDATE_URL) === false) {
                throw ValidationException::withMessages([
                    'button_url' => 'Для кнопки-ссылки укажите корректный URL.',
                ]);
            }
        } else {
            $buttonText = null;
            $buttonUrl = null;
        }

        $channelIds = $this->resolveChannelIds($rowData['channel_ids'] ?? null, $lookups['channels']);
        $this->guardButtonKindForChannels($buttonKind, $channelIds, $lookups['channels']);

        $requiredTags = $this->resolveTagsByNames(
            AutoReplyRuleWorkbookFormat::parseList($rowData['required_tag_names'] ?? null),
            'required_tag_names',
            $lookups['tags_by_name'],
        );
        $excludedTags = $this->resolveTagsByNames(
            AutoReplyRuleWorkbookFormat::parseList($rowData['excluded_tag_names'] ?? null),
            'excluded_tag_names',
            $lookups['tags_by_name'],
        );
        $assignTags = $this->resolveTagsByNames(
            AutoReplyRuleWorkbookFormat::parseList($rowData['assign_tag_names'] ?? null),
            'assign_tag_names',
            $lookups['tags_by_name'],
        );
        $removeTags = $this->resolveTagsByNames(
            AutoReplyRuleWorkbookFormat::parseList($rowData['remove_tag_names'] ?? null),
            'remove_tag_names',
            $lookups['tags_by_name'],
        );

        $requiredTagIds = array_keys($requiredTags);
        $excludedTagIds = array_keys($excludedTags);
        $assignTagIds = array_keys($assignTags);
        $removeTagIds = array_keys($removeTags);

        $this->guardAgainstOverlap($requiredTagIds, $excludedTagIds, 'required_tag_names', 'excluded_tag_names', 'Один и тот же тег нельзя одновременно сделать обязательным и исключающим.');
        $this->guardAgainstOverlap($assignTagIds, $removeTagIds, 'assign_tag_names', 'remove_tag_names', 'Один и тот же тег нельзя одновременно назначать и снимать.');
        $this->guardInactiveTags(
            $requiredTags,
            $existingRule?->tagConditions->pluck('tag_id')->map(fn (mixed $id): int => (int) $id)->all() ?? [],
            'required_tag_names',
        );
        $this->guardInactiveTags(
            $excludedTags,
            $existingRule?->tagConditions->pluck('tag_id')->map(fn (mixed $id): int => (int) $id)->all() ?? [],
            'excluded_tag_names',
        );
        $this->guardInactiveTags(
            $assignTags,
            $existingRule?->tagEffects->pluck('tag_id')->map(fn (mixed $id): int => (int) $id)->all() ?? [],
            'assign_tag_names',
        );
        $this->guardInactiveTags(
            $removeTags,
            $existingRule?->tagEffects->pluck('tag_id')->map(fn (mixed $id): int => (int) $id)->all() ?? [],
            'remove_tag_names',
        );

        return new AutoReplyRuleWorkbookRowData(
            rowNumber: $rowNumber,
            id: $ruleId,
            name: $name,
            autoReplyCategoryId: $autoReplyCategoryId,
            isActive: $isActive,
            priority: $priority,
            matchScope: $matchScope,
            keyword: $keyword,
            contactPhoneCondition: $contactPhoneCondition,
            replyText: $replyText,
            buttonKind: $buttonKind,
            buttonText: $buttonText,
            buttonUrl: $buttonUrl,
            channelIds: $channelIds,
            requiredTagIds: $requiredTagIds,
            excludedTagIds: $excludedTagIds,
            assignTagIds: $assignTagIds,
            removeTagIds: $removeTagIds,
        );
    }

    /**
     * @param  array<string, mixed>  $rowData
     */
    protected function isEmptyRow(array $rowData): bool
    {
        foreach ($rowData as $value) {
            if (is_string($value) && trim($value) !== '') {
                return false;
            }

            if (! is_string($value) && $value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    protected function parseNullableInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_float($value)) {
            if (fmod($value, 1.0) !== 0.0) {
                return null;
            }

            $integer = (int) $value;

            return $integer > 0 ? $integer : null;
        }

        $normalized = trim((string) $value);

        if (! preg_match('/^-?\d+$/', $normalized)) {
            return null;
        }

        $integer = (int) $normalized;

        return $integer > 0 ? $integer : null;
    }

    protected function parseRequiredInteger(mixed $value, string $column): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && fmod($value, 1.0) === 0.0) {
            return (int) $value;
        }

        $normalized = trim((string) $value);

        if (preg_match('/^-?\d+$/', $normalized)) {
            return (int) $normalized;
        }

        throw ValidationException::withMessages([
            $column => sprintf('Колонка %s должна содержать целое число.', $column),
        ]);
    }

    protected function parseBoolean(mixed $value, string $column): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = mb_strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw ValidationException::withMessages([
                $column => sprintf('Колонка %s должна содержать 1/0 или true/false.', $column),
            ]),
        };
    }

    protected function resolveButtonKind(mixed $value): ?string
    {
        $buttonKind = AutoReplyRuleWorkbookFormat::normalizeNullableString($value);

        if ($buttonKind === null || $buttonKind === AutoReplyRuleWorkbookFormat::BUTTON_KIND_NONE) {
            return null;
        }

        if (! in_array($buttonKind, [
            AutoReplyRuleWorkbookFormat::BUTTON_KIND_REQUEST_PHONE,
            AutoReplyRuleWorkbookFormat::BUTTON_KIND_LINK,
        ], true)) {
            throw ValidationException::withMessages([
                'button_kind' => 'Укажите допустимое значение button_kind.',
            ]);
        }

        return $buttonKind;
    }

    /**
     * @param  array<int, Channel>  $channels
     * @return list<int>
     */
    protected function resolveChannelIds(mixed $value, array $channels): array
    {
        $channelIds = [];

        foreach (AutoReplyRuleWorkbookFormat::parseList($value) as $channelIdValue) {
            if (filter_var($channelIdValue, FILTER_VALIDATE_INT) === false) {
                throw ValidationException::withMessages([
                    'channel_ids' => 'Список channel_ids должен содержать только целые ID каналов.',
                ]);
            }

            $channelId = (int) $channelIdValue;

            if ($channelId <= 0 || ! isset($channels[$channelId])) {
                throw ValidationException::withMessages([
                    'channel_ids' => 'Один из указанных каналов не найден.',
                ]);
            }

            $channelIds[] = $channelId;
        }

        $channelIds = array_values(array_unique($channelIds));

        if ($channelIds === []) {
            throw ValidationException::withMessages([
                'channel_ids' => 'Укажите хотя бы один канал.',
            ]);
        }

        return $channelIds;
    }

    /**
     * @param  array<int, Channel>  $channels
     * @param  list<int>  $channelIds
     */
    protected function guardButtonKindForChannels(?string $buttonKind, array $channelIds, array $channels): void
    {
        if ($buttonKind === null) {
            return;
        }

        $platforms = array_values(array_unique(array_map(
            fn (int $channelId): string => (string) $channels[$channelId]->platform,
            $channelIds,
        )));

        $availableButtonKinds = [AutoReplyRuleWorkbookFormat::BUTTON_KIND_REQUEST_PHONE];

        if (array_diff($platforms, [Channel::PLATFORM_TELEGRAM, Channel::PLATFORM_MAX]) === []) {
            $availableButtonKinds[] = AutoReplyRuleWorkbookFormat::BUTTON_KIND_LINK;
        }

        if (in_array($buttonKind, $availableButtonKinds, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'button_kind' => 'Выбранная кнопка недоступна для текущего набора каналов.',
        ]);
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, Collection<int, Tag>>  $tagsByName
     * @return array<int, Tag>
     */
    protected function resolveTagsByNames(array $names, string $column, array $tagsByName): array
    {
        $resolved = [];

        foreach ($names as $name) {
            /** @var Collection<int, Tag>|null $matches */
            $matches = $tagsByName[$name] ?? null;

            if (! $matches instanceof Collection || $matches->isEmpty()) {
                throw ValidationException::withMessages([
                    $column => sprintf('Тег "%s" не найден.', $name),
                ]);
            }

            if ($matches->count() > 1) {
                throw ValidationException::withMessages([
                    $column => sprintf('Тег "%s" найден неоднозначно. Используйте уникальные названия тегов.', $name),
                ]);
            }

            /** @var Tag $tag */
            $tag = $matches->first();
            $resolved[(int) $tag->id] = $tag;
        }

        return $resolved;
    }

    /**
     * @param  list<int>  $left
     * @param  list<int>  $right
     */
    protected function guardAgainstOverlap(array $left, array $right, string $leftColumn, string $rightColumn, string $message): void
    {
        if (array_intersect($left, $right) === []) {
            return;
        }

        throw ValidationException::withMessages([
            $leftColumn => $message,
            $rightColumn => $message,
        ]);
    }

    /**
     * @param  array<int, Tag>  $tags
     * @param  list<int>  $currentTagIds
     */
    protected function guardInactiveTags(array $tags, array $currentTagIds, string $column): void
    {
        foreach ($tags as $tag) {
            if ($tag->is_active || in_array((int) $tag->id, $currentTagIds, true)) {
                continue;
            }

            throw ValidationException::withMessages([
                $column => 'Можно использовать только активные теги. Отключённый тег можно оставить только если он уже сохранён в правиле.',
            ]);
        }
    }

    /**
     * @return list<AutoReplyRuleWorkbookRowErrorData>
     */
    protected function buildRowErrors(int $rowNumber, ValidationException $exception): array
    {
        $errors = [];

        foreach ($exception->errors() as $column => $messages) {
            foreach (Arr::wrap($messages) as $message) {
                $errors[] = new AutoReplyRuleWorkbookRowErrorData(
                    rowNumber: $rowNumber,
                    column: (string) $column,
                    message: trim((string) $message),
                );
            }
        }

        return $errors;
    }

    /**
     * @param  list<AutoReplyRuleWorkbookRowData>  $createRows
     * @param  list<AutoReplyRuleWorkbookRowData>  $updateRows
     * @return list<AutoReplyRuleWorkbookRowErrorData>
     */
    protected function filterRowsWithUniquenessConflicts(array &$createRows, array &$updateRows): array
    {
        $errors = app(ValidateAutoReplyRulesWorkbookUniquenessAction::class)->handle([
            ...$createRows,
            ...$updateRows,
        ]);

        if ($errors === []) {
            return [];
        }

        $erroredRowNumbers = array_values(array_unique(array_map(
            fn (AutoReplyRuleWorkbookRowErrorData $error): int => $error->rowNumber,
            $errors,
        )));

        $createRows = array_values(array_filter(
            $createRows,
            fn (AutoReplyRuleWorkbookRowData $row): bool => ! in_array($row->rowNumber, $erroredRowNumbers, true),
        ));
        $updateRows = array_values(array_filter(
            $updateRows,
            fn (AutoReplyRuleWorkbookRowData $row): bool => ! in_array($row->rowNumber, $erroredRowNumbers, true),
        ));

        return $errors;
    }
}
