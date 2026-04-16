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

        usort($rows, fn (AutoReplyRuleWorkbookRowData $left, AutoReplyRuleWorkbookRowData $right): int => $left->rowNumber <=> $right->rowNumber);

        $channels = Channel::query()
            ->whereIn('id', $this->collectChannelIds($rows))
            ->get()
            ->keyBy(fn (Channel $channel): int => (int) $channel->id)
            ->all();

        $preparedRows = array_values(array_map(
            fn (AutoReplyRuleWorkbookRowData $row): array => $this->prepareRow($row, $channels),
            $rows,
        ));

        return $this->simulateWorkbookState($preparedRows);
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
     * @return array{row:AutoReplyRuleWorkbookRowData,owner_token:string,future_signature:?string,primary_channel_id:?int,match_scope:?string,normalized_keyword:?string}
     */
    protected function prepareRow(AutoReplyRuleWorkbookRowData $row, array $channels): array
    {
        $primaryChannelId = null;
        $normalizedKeyword = null;
        $futureSignature = null;

        if ($row->matchScope !== AutoReplyRule::MATCH_SCOPE_ANY_INBOUND) {
            $normalizedKeyword = AutoReplyRule::normalizeKeyword($row->keyword);
            $primaryChannelId = $this->resolvePrimaryChannelId($row, $channels);

            if ($primaryChannelId !== null && $normalizedKeyword !== null) {
                $futureSignature = $this->makeSignature($primaryChannelId, $row->matchScope, $normalizedKeyword);
            }
        }

        return [
            'row' => $row,
            'owner_token' => $this->makeOwnerToken($row),
            'future_signature' => $futureSignature,
            'primary_channel_id' => $primaryChannelId,
            'match_scope' => $futureSignature !== null ? $row->matchScope : null,
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
     * @param  list<array{row:AutoReplyRuleWorkbookRowData,owner_token:string,future_signature:?string,primary_channel_id:?int,match_scope:?string,normalized_keyword:?string}>  $preparedRows
     * @return array{signatures_by_owner:array<string, ?string>, owners_by_signature:array<string, array{owner_token:string,rule_id:?int,row_number:?int,source:string}>}
     */
    protected function loadExistingState(array $preparedRows): array
    {
        $ruleIds = array_values(array_unique(array_filter(array_map(
            fn (array $preparedRow): ?int => $preparedRow['row']->id,
            $preparedRows,
        ))));
        $futureRows = array_values(array_filter(
            $preparedRows,
            fn (array $preparedRow): bool => $preparedRow['future_signature'] !== null,
        ));
        $primaryChannelIds = array_values(array_unique(array_filter(array_map(
            fn (array $preparedRow): ?int => $preparedRow['primary_channel_id'],
            $futureRows,
        ))));
        $matchScopes = array_values(array_unique(array_filter(array_map(
            fn (array $preparedRow): ?string => $preparedRow['match_scope'],
            $futureRows,
        ))));
        $normalizedKeywords = array_values(array_unique(array_filter(
            array_map(
                fn (array $preparedRow): ?string => $preparedRow['normalized_keyword'],
                $futureRows,
            ),
            fn (?string $normalizedKeyword): bool => $normalizedKeyword !== null,
        )));

        if ($ruleIds === [] && ($primaryChannelIds === [] || $matchScopes === [] || $normalizedKeywords === [])) {
            return [
                'signatures_by_owner' => [],
                'owners_by_signature' => [],
            ];
        }

        $rules = AutoReplyRule::query()
            ->select(['id', 'channel_id', 'match_scope', 'normalized_keyword'])
            ->where(function ($query) use ($ruleIds, $primaryChannelIds, $matchScopes, $normalizedKeywords): void {
                if ($ruleIds !== []) {
                    $query->whereIn('id', $ruleIds);
                }

                if ($primaryChannelIds === [] || $matchScopes === [] || $normalizedKeywords === []) {
                    return;
                }

                $query->orWhere(function ($signatureQuery) use ($primaryChannelIds, $matchScopes, $normalizedKeywords): void {
                    $signatureQuery
                        ->whereIn('channel_id', $primaryChannelIds)
                        ->whereIn('match_scope', $matchScopes)
                        ->whereIn('normalized_keyword', $normalizedKeywords);
                });
            })
            ->get();

        $signaturesByOwner = [];
        $ownersBySignature = [];

        foreach ($rules as $rule) {
            $ownerToken = $this->makeRuleOwnerToken((int) $rule->id);
            $signature = filled($rule->normalized_keyword)
                ? $this->makeSignature((int) $rule->channel_id, (string) $rule->match_scope, (string) $rule->normalized_keyword)
                : null;

            $signaturesByOwner[$ownerToken] = $signature;

            if ($signature === null) {
                continue;
            }

            $ownersBySignature[$signature] = [
                'owner_token' => $ownerToken,
                'rule_id' => (int) $rule->id,
                'row_number' => null,
                'source' => 'database',
            ];
        }

        return [
            'signatures_by_owner' => $signaturesByOwner,
            'owners_by_signature' => $ownersBySignature,
        ];
    }

    /**
     * @param  list<array{row:AutoReplyRuleWorkbookRowData,owner_token:string,future_signature:?string,primary_channel_id:?int,match_scope:?string,normalized_keyword:?string}>  $preparedRows
     * @return list<AutoReplyRuleWorkbookRowErrorData>
     */
    protected function simulateWorkbookState(array $preparedRows): array
    {
        $state = $this->loadExistingState($preparedRows);
        $errors = [];

        foreach ($preparedRows as $preparedRow) {
            /** @var AutoReplyRuleWorkbookRowData $row */
            $row = $preparedRow['row'];
            $ownerToken = $preparedRow['owner_token'];
            $currentSignature = $state['signatures_by_owner'][$ownerToken] ?? null;
            $releasedOwner = null;

            if ($currentSignature !== null && (($state['owners_by_signature'][$currentSignature]['owner_token'] ?? null) === $ownerToken)) {
                $releasedOwner = $state['owners_by_signature'][$currentSignature];
                unset($state['owners_by_signature'][$currentSignature]);
            }

            $futureSignature = $preparedRow['future_signature'];

            if ($futureSignature !== null) {
                $owner = $state['owners_by_signature'][$futureSignature] ?? null;

                if (is_array($owner) && $owner['owner_token'] !== $ownerToken) {
                    $errors[] = new AutoReplyRuleWorkbookRowErrorData(
                        rowNumber: $row->rowNumber,
                        column: $owner['row_number'] !== null ? 'keyword' : 'id',
                        message: $this->buildConflictMessage($row, $owner),
                    );

                    if ($releasedOwner !== null) {
                        $state['owners_by_signature'][$currentSignature] = $releasedOwner;
                    }

                    continue;
                }

                $state['owners_by_signature'][$futureSignature] = [
                    'owner_token' => $ownerToken,
                    'rule_id' => $row->id,
                    'row_number' => $row->rowNumber,
                    'source' => 'workbook',
                ];
            }

            $state['signatures_by_owner'][$ownerToken] = $futureSignature;
        }

        return $errors;
    }

    /**
     * @param  array{owner_token:string,rule_id:?int,row_number:?int,source:string}  $owner
     */
    protected function buildConflictMessage(AutoReplyRuleWorkbookRowData $row, array $owner): string
    {
        if ($owner['row_number'] !== null) {
            return sprintf(
                'Строка конфликтует со строкой %d: после применения по порядку они займут один и тот же ключ правила (channel_id + match_scope + keyword).',
                $owner['row_number'],
            );
        }

        return $this->buildExistingRuleConflictMessage($row, (int) $owner['rule_id']);
    }

    protected function buildExistingRuleConflictMessage(AutoReplyRuleWorkbookRowData $row, int $existingRuleId): string
    {
        return $row->id === null
            ? sprintf(
                'В базе уже есть правило #%d с тем же ключом (channel_id + match_scope + keyword). Укажите id для обновления или измените данные строки.',
                $existingRuleId,
            )
            : sprintf(
                'Строка конфликтует с существующим правилом #%d по ключу (channel_id + match_scope + keyword). Обновите предпросмотр и повторите импорт.',
                $existingRuleId,
            );
    }

    protected function makeOwnerToken(AutoReplyRuleWorkbookRowData $row): string
    {
        return $row->id !== null
            ? $this->makeRuleOwnerToken($row->id)
            : 'row:'.$row->rowNumber;
    }

    protected function makeRuleOwnerToken(int $ruleId): string
    {
        return 'rule:'.$ruleId;
    }
}
