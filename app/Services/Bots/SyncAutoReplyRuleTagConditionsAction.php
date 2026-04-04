<?php

namespace App\Services\Bots;

use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagCondition;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncAutoReplyRuleTagConditionsAction
{
    /**
     * @param  list<int|string>  $requiredTagIds
     * @param  list<int|string>  $excludedTagIds
     */
    public function handle(AutoReplyRule $rule, array $requiredTagIds, array $excludedTagIds): void
    {
        $requiredTagIds = $this->normalizeTagIds($requiredTagIds);
        $excludedTagIds = $this->normalizeTagIds($excludedTagIds);

        $this->guardAgainstConflict($requiredTagIds, $excludedTagIds);
        $this->guardTagSelection($rule, array_values(array_unique([...$requiredTagIds, ...$excludedTagIds])));

        DB::transaction(function () use ($rule, $requiredTagIds, $excludedTagIds): void {
            AutoReplyRuleTagCondition::query()
                ->where('auto_reply_rule_id', $rule->id)
                ->delete();

            $rows = [
                ...$this->buildRows($rule->id, $requiredTagIds, AutoReplyRuleTagCondition::CONDITION_REQUIRED),
                ...$this->buildRows($rule->id, $excludedTagIds, AutoReplyRuleTagCondition::CONDITION_EXCLUDED),
            ];

            if ($rows !== []) {
                AutoReplyRuleTagCondition::query()->insert($rows);
            }
        });
    }

    /**
     * @param  list<int|string>  $tagIds
     * @return list<int>
     */
    protected function normalizeTagIds(array $tagIds): array
    {
        return collect($tagIds)
            ->map(fn (mixed $tagId): int => (int) $tagId)
            ->filter(fn (int $tagId): bool => $tagId > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $requiredTagIds
     * @param  list<int>  $excludedTagIds
     */
    protected function guardAgainstConflict(array $requiredTagIds, array $excludedTagIds): void
    {
        $overlap = array_values(array_intersect($requiredTagIds, $excludedTagIds));

        if ($overlap === []) {
            return;
        }

        throw ValidationException::withMessages([
            'required_tag_ids' => 'Один и тот же тег нельзя одновременно сделать обязательным и исключающим в одном правиле.',
            'excluded_tag_ids' => 'Один и тот же тег нельзя одновременно сделать обязательным и исключающим в одном правиле.',
        ]);
    }

    /**
     * @param  list<int>  $tagIds
     */
    protected function guardTagSelection(AutoReplyRule $rule, array $tagIds): void
    {
        if ($tagIds === []) {
            return;
        }

        $currentTagIds = $rule->exists
            ? $rule->tagConditions()->pluck('tag_id')->map(fn (mixed $tagId): int => (int) $tagId)->all()
            : [];

        $allowedTagIds = Tag::query()
            ->whereIn('id', $tagIds)
            ->where(function ($query) use ($currentTagIds): void {
                $query->where('is_active', true);

                if ($currentTagIds !== []) {
                    $query->orWhereIn('id', $currentTagIds);
                }
            })
            ->pluck('id')
            ->map(fn (mixed $tagId): int => (int) $tagId)
            ->all();

        if (count($allowedTagIds) === count($tagIds)) {
            return;
        }

        throw ValidationException::withMessages([
            'required_tag_ids' => 'Можно выбрать только активные теги. Отключённые теги можно оставить только если они уже были сохранены в правиле.',
            'excluded_tag_ids' => 'Можно выбрать только активные теги. Отключённые теги можно оставить только если они уже были сохранены в правиле.',
        ]);
    }

    /**
     * @param  list<int>  $tagIds
     * @return list<array<string, mixed>>
     */
    protected function buildRows(int $ruleId, array $tagIds, string $condition): array
    {
        $timestamp = now();

        return collect($tagIds)
            ->map(fn (int $tagId): array => [
                'auto_reply_rule_id' => $ruleId,
                'tag_id' => $tagId,
                'condition' => $condition,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->all();
    }
}
