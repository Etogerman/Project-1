<?php

namespace App\Services\Bots;

use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagEffect;
use App\Models\Contact;

class ApplyAutoReplyRuleTagEffectsAction
{
    public function __construct(
        private readonly \App\Services\Contacts\ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact $contact, AutoReplyRule $rule): Contact
    {
        $contact = $this->resolveRootContactAction->handle($contact);
        $rule->loadMissing('tagEffects');

        $assignTagIds = $rule->tagEffects
            ->where('effect', AutoReplyRuleTagEffect::EFFECT_ASSIGN)
            ->pluck('tag_id')
            ->map(fn (mixed $tagId): int => (int) $tagId)
            ->filter(fn (int $tagId): bool => $tagId > 0)
            ->unique()
            ->values()
            ->all();

        $removeTagIds = $rule->tagEffects
            ->where('effect', AutoReplyRuleTagEffect::EFFECT_REMOVE)
            ->pluck('tag_id')
            ->map(fn (mixed $tagId): int => (int) $tagId)
            ->filter(fn (int $tagId): bool => $tagId > 0)
            ->unique()
            ->values()
            ->all();

        if ($assignTagIds !== []) {
            $existingAssignedTagIds = $contact->tags()
                ->whereIn('tags.id', $assignTagIds)
                ->pluck('tags.id')
                ->map(fn (mixed $tagId): int => (int) $tagId)
                ->all();

            $tagIdsToAttach = array_values(array_diff($assignTagIds, $existingAssignedTagIds));

            if ($tagIdsToAttach !== []) {
                $timestamp = now();

                $contact->tags()->attach(collect($tagIdsToAttach)
                    ->mapWithKeys(fn (int $tagId): array => [$tagId => [
                        'assigned_at' => $timestamp,
                        'assigned_by_user_id' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]])
                    ->all());
            }
        }

        if ($removeTagIds !== []) {
            $contact->tags()->detach($removeTagIds);
        }

        return $contact->refresh();
    }
}
