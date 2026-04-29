<?php

namespace App\Services\AutoReplyRules;

use App\Data\AutoReplyRules\AutoReplyRuleWorkbookRowData;
use App\Data\AutoReplyRules\AutoReplyRuleWorkbookRowErrorData;
use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagCondition;
use Illuminate\Database\Eloquent\Builder;

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

        $preparedRows = array_values(array_map(
            fn (AutoReplyRuleWorkbookRowData $row): array => $this->prepareRow($row),
            $rows,
        ));

        return $this->simulateWorkbookState($preparedRows);
    }

    /**
     * @return array{row:AutoReplyRuleWorkbookRowData,owner_token:string,future_owner:?array<string, mixed>}
     */
    protected function prepareRow(AutoReplyRuleWorkbookRowData $row): array
    {
        $normalizedKeyword = null;
        $futureOwner = null;

        if ($this->usesKeywordScope($row->matchScope)) {
            $normalizedKeyword = AutoReplyRule::normalizeKeyword($row->keyword);

            if ($row->channelIds !== [] && $normalizedKeyword !== null) {
                $futureOwner = [
                    'owner_token' => $this->makeOwnerToken($row),
                    'rule_id' => $row->id,
                    'row_number' => $row->rowNumber,
                    'source' => 'workbook',
                    'channel_ids' => $this->normalizeIds($row->channelIds),
                    'match_scope' => $row->matchScope,
                    'normalized_keyword' => $normalizedKeyword,
                    'contact_phone_condition' => filled($row->contactPhoneCondition) ? $row->contactPhoneCondition : null,
                    'required_tag_ids' => $this->normalizeIds($row->requiredTagIds),
                    'excluded_tag_ids' => $this->normalizeIds($row->excludedTagIds),
                ];
            }
        }

        return [
            'row' => $row,
            'owner_token' => $this->makeOwnerToken($row),
            'future_owner' => $futureOwner,
        ];
    }

    /**
     * @param  list<array{row:AutoReplyRuleWorkbookRowData,owner_token:string,future_owner:?array<string, mixed>}>  $preparedRows
     * @return array{owners:list<array<string, mixed>>}
     */
    protected function loadExistingState(array $preparedRows): array
    {
        $ruleIds = array_values(array_unique(array_filter(array_map(
            fn (array $preparedRow): ?int => $preparedRow['row']->id,
            $preparedRows,
        ))));
        $futureOwners = array_values(array_filter(
            array_map(
                fn (array $preparedRow): ?array => $preparedRow['future_owner'],
                $preparedRows,
            ),
            fn (?array $owner): bool => $owner !== null,
        ));
        $channelIds = array_values(array_unique(array_merge(
            [],
            ...array_map(
                fn (array $owner): array => $owner['channel_ids'],
                $futureOwners,
            ),
        )));
        $matchScopes = array_values(array_unique(array_filter(array_map(
            fn (array $owner): ?string => $owner['match_scope'],
            $futureOwners,
        ))));
        $normalizedKeywords = array_values(array_unique(array_filter(
            array_map(
                fn (array $owner): ?string => $owner['normalized_keyword'],
                $futureOwners,
            ),
            fn (?string $normalizedKeyword): bool => $normalizedKeyword !== null,
        )));

        if ($ruleIds === [] && ($channelIds === [] || $matchScopes === [] || $normalizedKeywords === [])) {
            return [
                'owners' => [],
            ];
        }

        $rules = AutoReplyRule::query()
            ->select(['id', 'channel_id', 'match_scope', 'normalized_keyword', 'contact_phone_condition'])
            ->with(['channels:id', 'tagConditions:id,auto_reply_rule_id,tag_id,condition'])
            ->where(function (Builder $query) use ($ruleIds, $channelIds, $matchScopes, $normalizedKeywords): void {
                if ($ruleIds !== []) {
                    $query->whereIn('id', $ruleIds);
                }

                if ($channelIds === [] || $matchScopes === [] || $normalizedKeywords === []) {
                    return;
                }

                $signatureConstraint = function (Builder $signatureQuery) use ($channelIds, $matchScopes, $normalizedKeywords): void {
                    $signatureQuery
                        ->whereIn('match_scope', $matchScopes)
                        ->whereIn('normalized_keyword', $normalizedKeywords)
                        ->whereHas('channels', function (Builder $channelQuery) use ($channelIds): void {
                            $channelQuery->whereIn('channels.id', $channelIds);
                        });
                };

                if ($ruleIds !== []) {
                    $query->orWhere($signatureConstraint);

                    return;
                }

                $query->where($signatureConstraint);
            })
            ->get();

        $owners = [];

        foreach ($rules as $rule) {
            $owner = $this->makeDatabaseOwner($rule);

            if (! $this->ownerUsesKeywordScope($owner)) {
                continue;
            }

            $owners[] = $owner;
        }

        return [
            'owners' => $owners,
        ];
    }

    /**
     * @param  list<array{row:AutoReplyRuleWorkbookRowData,owner_token:string,future_owner:?array<string, mixed>}>  $preparedRows
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
            $releasedOwner = $this->releaseOwner($state['owners'], $ownerToken);
            $futureOwner = $preparedRow['future_owner'];

            if ($futureOwner !== null) {
                $owner = $this->findConflictingOwner($futureOwner, $state['owners']);

                if ($owner !== null) {
                    $errors[] = new AutoReplyRuleWorkbookRowErrorData(
                        rowNumber: $row->rowNumber,
                        column: $owner['row_number'] !== null ? 'keyword' : 'id',
                        message: $this->buildConflictMessage($row, $owner),
                    );

                    if ($releasedOwner !== null) {
                        $state['owners'][] = $releasedOwner;
                    }

                    continue;
                }

                $state['owners'][] = $futureOwner;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $owner
     */
    protected function buildConflictMessage(AutoReplyRuleWorkbookRowData $row, array $owner): string
    {
        if ($owner['row_number'] !== null) {
            return sprintf(
                'Строка конфликтует со строкой %d: после применения по порядку правила смогут сработать одновременно для одного текста в пересекающихся каналах.',
                $owner['row_number'],
            );
        }

        return $this->buildExistingRuleConflictMessage($row, (int) $owner['rule_id']);
    }

    protected function buildExistingRuleConflictMessage(AutoReplyRuleWorkbookRowData $row, int $existingRuleId): string
    {
        return $row->id === null
            ? sprintf(
                'В базе уже есть правило #%d, которое может сработать одновременно с этой строкой для одного текста в пересекающихся каналах. Укажите id для обновления или измените данные строки.',
                $existingRuleId,
            )
            : sprintf(
                'Строка конфликтует с существующим правилом #%d: правила могут сработать одновременно для одного текста в пересекающихся каналах. Обновите предпросмотр и повторите импорт.',
                $existingRuleId,
            );
    }

    /**
     * @return array<string, mixed>
     */
    protected function makeDatabaseOwner(AutoReplyRule $rule): array
    {
        $channelIds = $rule->channels
            ->pluck('id')
            ->map(fn (mixed $channelId): int => (int) $channelId)
            ->all();

        if ($channelIds === [] && $rule->channel_id !== null) {
            $channelIds = [(int) $rule->channel_id];
        }

        return [
            'owner_token' => $this->makeRuleOwnerToken((int) $rule->id),
            'rule_id' => (int) $rule->id,
            'row_number' => null,
            'source' => 'database',
            'channel_ids' => $this->normalizeIds($channelIds),
            'match_scope' => (string) $rule->match_scope,
            'normalized_keyword' => filled($rule->normalized_keyword) ? (string) $rule->normalized_keyword : null,
            'contact_phone_condition' => filled($rule->contact_phone_condition) ? (string) $rule->contact_phone_condition : null,
            'required_tag_ids' => $this->tagConditionIds($rule, AutoReplyRuleTagCondition::CONDITION_REQUIRED),
            'excluded_tag_ids' => $this->tagConditionIds($rule, AutoReplyRuleTagCondition::CONDITION_EXCLUDED),
        ];
    }

    /**
     * @return list<int>
     */
    protected function tagConditionIds(AutoReplyRule $rule, string $condition): array
    {
        return $this->normalizeIds(
            $rule->tagConditions
                ->where('condition', $condition)
                ->pluck('tag_id')
                ->map(fn (mixed $tagId): int => (int) $tagId)
                ->all(),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $owners
     * @return array<string, mixed>|null
     */
    protected function releaseOwner(array &$owners, string $ownerToken): ?array
    {
        foreach ($owners as $index => $owner) {
            if (($owner['owner_token'] ?? null) !== $ownerToken) {
                continue;
            }

            unset($owners[$index]);
            $owners = array_values($owners);

            return $owner;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $futureOwner
     * @param  list<array<string, mixed>>  $owners
     * @return array<string, mixed>|null
     */
    protected function findConflictingOwner(array $futureOwner, array $owners): ?array
    {
        foreach ($owners as $owner) {
            if (($owner['owner_token'] ?? null) === ($futureOwner['owner_token'] ?? null)) {
                continue;
            }

            if ($this->ownersCanOverlap($futureOwner, $owner)) {
                return $owner;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    protected function ownersCanOverlap(array $left, array $right): bool
    {
        if (! $this->ownerUsesKeywordScope($left) || ! $this->ownerUsesKeywordScope($right)) {
            return false;
        }

        if ($left['match_scope'] !== $right['match_scope'] || $left['normalized_keyword'] !== $right['normalized_keyword']) {
            return false;
        }

        if (array_intersect($left['channel_ids'], $right['channel_ids']) === []) {
            return false;
        }

        if (! $this->phoneConditionsCanOverlap($left['contact_phone_condition'], $right['contact_phone_condition'])) {
            return false;
        }

        return $this->tagConditionsCanOverlap(
            $left['required_tag_ids'],
            $left['excluded_tag_ids'],
            $right['required_tag_ids'],
            $right['excluded_tag_ids'],
        );
    }

    /**
     * @param  array<string, mixed>  $owner
     */
    protected function ownerUsesKeywordScope(array $owner): bool
    {
        return $owner['channel_ids'] !== []
            && $this->usesKeywordScope((string) $owner['match_scope'])
            && filled($owner['normalized_keyword']);
    }

    protected function usesKeywordScope(string $matchScope): bool
    {
        return $matchScope !== AutoReplyRule::MATCH_SCOPE_ANY_INBOUND;
    }

    protected function phoneConditionsCanOverlap(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return true;
        }

        return $left === $right;
    }

    /**
     * @param  list<int>  $requiredTagIds
     * @param  list<int>  $excludedTagIds
     * @param  list<int>  $existingRequiredTagIds
     * @param  list<int>  $existingExcludedTagIds
     */
    protected function tagConditionsCanOverlap(
        array $requiredTagIds,
        array $excludedTagIds,
        array $existingRequiredTagIds,
        array $existingExcludedTagIds,
    ): bool {
        return array_intersect($requiredTagIds, $existingExcludedTagIds) === []
            && array_intersect($excludedTagIds, $existingRequiredTagIds) === [];
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    protected function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn (mixed $id): int => (int) $id, $ids),
            fn (int $id): bool => $id > 0,
        )));

        sort($ids);

        return $ids;
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
