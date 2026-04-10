<?php

namespace App\Services\Scenarios;

use App\Models\Contact;
use App\Models\Tag;
use App\Services\Contacts\ResolveRootContactAction;

class ApplyScenarioTagEffectsAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    /**
     * @param  list<array{type: 'set_tag'|'remove_tag', value: string}>  $actions
     */
    public function handle(Contact $contact, array $actions): Contact
    {
        if ($actions === []) {
            return $contact;
        }

        $contact = $this->resolveRootContactAction->handle($contact);

        $assignSlugs = collect($actions)
            ->where('type', 'set_tag')
            ->pluck('value')
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();

        $removeSlugs = collect($actions)
            ->where('type', 'remove_tag')
            ->pluck('value')
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();

        if ($assignSlugs !== []) {
            $assignTags = Tag::query()
                ->active()
                ->whereIn('slug', $assignSlugs)
                ->get(['id', 'slug']);

            $assignTagIds = $assignTags
                ->pluck('id')
                ->map(fn (mixed $tagId): int => (int) $tagId)
                ->all();

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

        if ($removeSlugs !== []) {
            $removeTagIds = Tag::query()
                ->whereIn('slug', $removeSlugs)
                ->pluck('id')
                ->map(fn (mixed $tagId): int => (int) $tagId)
                ->all();

            if ($removeTagIds !== []) {
                $contact->tags()->detach($removeTagIds);
            }
        }

        return $contact->refresh();
    }
}
