<?php

namespace App\Services\AutoReplyRules;

use App\Data\AutoReplyRules\AutoReplyRuleWorkbookRowData;
use App\Data\AutoReplyRules\AutoReplyRuleWorkbookRowErrorData;
use App\Models\AutoReplyRule;
use App\Models\Channel;

class ValidateAutoReplyRulesWorkbookUniquenessAction
{
    /**
     * @param  list<AutoReplyRuleWorkbookRowData>  $rows
     * @return list<AutoReplyRuleWorkbookRowErrorData>
     */
    public function handle(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $channels = Channel::query()
            ->whereIn('id', $this->collectChannelIds($rows))
            ->get()
            ->keyBy(fn (Channel $channel): int => (int) $channel->id)
            ->all();

        $preparedRows = array_values(array_filter(array_map(
            fn (AutoReplyRuleWorkbookRowData $row): ?array => $this->prepareRow($row, $channels),
            $rows,
        )));

        if ($preparedRows === []) {
            return [];
        }

        $errorsByRowNumber = [];

        foreach ($this->buildWorkbookConflictErrors($preparedRows) as $error) {
            $errorsByRowNumber[$error->rowNumber] = $error;
        }

        $existingRulesBySignature = $this->loadExistingRulesBySignature($preparedRows);

        foreach ($preparedRows as $preparedRow) {
            /** @var AutoReplyRuleWorkbookRowData $row */
            $row = $preparedRow['row'];

            if (array_key_exists($row->rowNumber, $errorsByRowNumber)) {
                continue;
            }

            foreach ($existingRulesBySignature[$preparedRow['signature']] ?? [] as $existingRule) {
                if ($row->id !== null && (int) $existingRule->id === $row->id) {
                    continue;
                }

                $errorsByRowNumber[$row->rowNumber] = new AutoReplyRuleWorkbookRowErrorData(
                    rowNumber: $row->rowNumber,
                    column: 'id',
                    message: $this->buildExistingRuleConflictMessage($row, $existingRule),
                );

                break;
            }
        }

        ksort($errorsByRowNumber);

        return array_values($errorsByRowNumber);
    }

    /**
     * @param  list<AutoReplyRuleWorkbookRowData>  $rows
     * @return list<int>
     */
    protected function collectChannelIds(array $rows): array
    {
        return array_values(array_unique(array_merge(
            [],
            ...array_map(
                fn (AutoReplyRuleWorkbookRowData $row): array => $row->channelIds,
                $rows,
            ),
        )));
    }

    /**
     * @param  array<int, Channel>  $channels
     * @return array{row:AutoReplyRuleWorkbookRowData,signature:string,primary_channel_id:int,match_scope:string,normalized_keyword:string}|null
     */
    protected function prepareRow(AutoReplyRuleWorkbookRowData $row, array $channels): ?array
    {
        if ($row->matchScope === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND) {
            return null;
        }

        $normalizedKeyword = AutoReplyRule::normalizeKeyword($row->keyword);

        if ($normalizedKeyword === null) {
            return null;
        }

        $primaryChannelId = $this->resolvePrimaryChannelId($row, $channels);

        if ($primaryChannelId === null) {
            return null;
        }

        return [
            'row' => $row,
            'signature' => $this->makeSignature($primaryChannelId, $row->matchScope, $normalizedKeyword),
            'primary_channel_id' => $primaryChannelId,
            'match_scope' => $row->matchScope,
            'normalized_keyword' => $normalizedKeyword,
        ];
    }

    /**
     * @param  array<int, Channel>  $channels
     */
    protected function resolvePrimaryChannelId(AutoReplyRuleWorkbookRowData $row, array $channels): ?int
    {
        $primaryChannelId = $row->channelIds[0] ?? null;

        if ($primaryChannelId === null || $row->buttonKind !== AutoReplyRuleWorkbookFormat::BUTTON_KIND_REQUEST_PHONE) {
            return $primaryChannelId;
        }

        $orderedChannels = array_values(array_filter(array_map(
            fn (int $channelId): ?Channel => $channels[$channelId] ?? null,
            $row->channelIds,
        )));

        foreach ($orderedChannels as $channel) {
            if ($channel->platform === Channel::PLATFORM_TELEGRAM) {
                return (int) $channel->id;
            }
        }

        foreach ($orderedChannels as $channel) {
            if ($channel->platform === Channel::PLATFORM_MAX) {
                return (int) $channel->id;
            }
        }

        return $primaryChannelId;
    }

    protected function makeSignature(int $primaryChannelId, string $matchScope, string $normalizedKeyword): string
    {
        return serialize([$primaryChannelId, $matchScope, $normalizedKeyword]);
    }

    /**
     * @param  list<array{row:AutoReplyRuleWorkbookRowData,signature:string,primary_channel_id:int,match_scope:string,normalized_keyword:string}>  $preparedRows
     * @return array<string, list<AutoReplyRule>>
     */
    protected function loadExistingRulesBySignature(array $preparedRows): array
    {
        $primaryChannelIds = array_values(array_unique(array_map(
            fn (array $preparedRow): int => $preparedRow['primary_channel_id'],
            $preparedRows,
        )));
        $matchScopes = array_values(array_unique(array_map(
            fn (array $preparedRow): string => $preparedRow['match_scope'],
            $preparedRows,
        )));
        $normalizedKeywords = array_values(array_unique(array_map(
            fn (array $preparedRow): string => $preparedRow['normalized_keyword'],
            $preparedRows,
        )));

        return AutoReplyRule::query()
            ->select(['id', 'channel_id', 'match_scope', 'normalized_keyword'])
            ->whereIn('channel_id', $primaryChannelIds)
            ->whereIn('match_scope', $matchScopes)
            ->whereIn('normalized_keyword', $normalizedKeywords)
            ->get()
            ->groupBy(fn (AutoReplyRule $rule): string => $this->makeSignature(
                (int) $rule->channel_id,
                (string) $rule->match_scope,
                (string) $rule->normalized_keyword,
            ))
            ->map(fn ($group): array => $group->all())
            ->all();
    }

    /**
     * @param  list<array{row:AutoReplyRuleWorkbookRowData,signature:string,primary_channel_id:int,match_scope:string,normalized_keyword:string}>  $preparedRows
     * @return list<AutoReplyRuleWorkbookRowErrorData>
     */
    protected function buildWorkbookConflictErrors(array $preparedRows): array
    {
        $errors = [];
        $groups = [];

        foreach ($preparedRows as $preparedRow) {
            $groups[$preparedRow['signature']][] = $preparedRow['row'];
        }

        foreach ($groups as $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $rowNumbers = array_map(
                fn (AutoReplyRuleWorkbookRowData $row): int => $row->rowNumber,
                $rows,
            );

            foreach ($rows as $row) {
                $otherRows = array_values(array_diff($rowNumbers, [$row->rowNumber]));

                $errors[] = new AutoReplyRuleWorkbookRowErrorData(
                    rowNumber: $row->rowNumber,
                    column: 'keyword',
                    message: sprintf(
                        'Строка конфликтует со строками %s: после сохранения они дадут один и тот же ключ правила (channel_id + match_scope + keyword).',
                        implode(', ', $otherRows),
                    ),
                );
            }
        }

        return $errors;
    }

    protected function buildExistingRuleConflictMessage(AutoReplyRuleWorkbookRowData $row, AutoReplyRule $existingRule): string
    {
        return $row->id === null
            ? sprintf(
                'В базе уже есть правило #%d с тем же ключом (channel_id + match_scope + keyword). Укажите id для обновления или измените данные строки.',
                (int) $existingRule->id,
            )
            : sprintf(
                'Строка конфликтует с существующим правилом #%d по ключу (channel_id + match_scope + keyword). Обновите предпросмотр и повторите импорт.',
                (int) $existingRule->id,
            );
    }
}
