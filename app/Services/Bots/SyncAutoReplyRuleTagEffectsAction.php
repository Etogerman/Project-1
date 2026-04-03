<?php

namespace App\Services\Bots;

use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagEffect;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncAutoReplyRuleTagEffectsAction
{
    /**
     * @param  list<int|string>  $assignTagIds
     * @param  list<int|string>  $removeTagIds
     */
    public function handle(AutoReplyRule $rule, array $assignTagIds, array $removeTagIds): void
    {
        $assignTagIds = $this->normalizeTagIds($assignTagIds);
        $removeTagIds = $this->normalizeTagIds($removeTagIds);

        $this->guardAgainstConflict($assignTagIds, $removeTagIds);
        $this->guardTagSelection($rule, array_values(array_unique([...$assignTagIds, ...$removeTagIds])));

        DB::transaction(function () use ($rule, $assignTagIds, $removeTagIds): void {
            AutoReplyRuleTagEffect::query()
                ->where('auto_reply_rule_id', $rule->id)
                ->delete();

            $rows = [
                ...$this->buildRows($rule->id, $assignTagIds, AutoReplyRuleTagEffect::EFFECT_ASSIGN),
                ...$this->buildRows($rule->id, $removeTagIds, AutoReplyRuleTagEffect::EFFECT_REMOVE),
            ];

            if ($rows !== []) {
                AutoReplyRuleTagEffect::query()->insert($rows);
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
     * @param  list<int>  $assignTagIds
     * @param  list<int>  $removeTagIds
     */
    protected function guardAgainstConflict(array $assignTagIds, array $removeTagIds): void
    {
        $overlap = array_values(array_intersect($assignTagIds, $removeTagIds));

        if ($overlap === []) {
            return;
        }

        throw ValidationException::withMessages([
            'assign_tag_ids' => 'Один и тот же тег нельзя одновременно назначать и снимать в одном правиле.',
            'remove_tag_ids' => 'Один и тот же тег нельзя одновременно назначать и снимать в одном правиле.',
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
            ? $rule->tagEffects()->pluck('tag_id')->map(fn (mixed $tagId): int => (int) $tagId)->all()
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
            'assign_tag_ids' => 'Можно выбрать только активные теги. Отключённые теги можно оставить только если они уже были сохранены в правиле.',
            'remove_tag_ids' => 'Можно выбрать только активные теги. Отключённые теги можно оставить только если они уже были сохранены в правиле.',
        ]);
    }

    /**
     * @param  list<int>  $tagIds
     * @return list<array<string, mixed>>
     */
    protected function buildRows(int $ruleId, array $tagIds, string $effect): array
    {
        $timestamp = now();

        return collect($tagIds)
            ->map(fn (int $tagId): array => [
                'auto_reply_rule_id' => $ruleId,
                'tag_id' => $tagId,
                'effect' => $effect,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->all();
    }
}
