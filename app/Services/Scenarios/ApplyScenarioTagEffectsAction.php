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
        $actionSlugs = collect($actions)
            ->pluck('value')
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();

        $tagsBySlug = Tag::query()
            ->active()
            ->whereIn('slug', $actionSlugs)
            ->get(['id', 'slug'])
            ->keyBy('slug');

        $assignedTagIds = $contact->tags()
            ->whereIn('tags.slug', $actionSlugs)
            ->pluck('tags.id')
            ->map(fn (mixed $tagId): int => (int) $tagId)
            ->all();

        foreach ($actions as $action) {
            $tagSlug = is_string($action['value'] ?? null)
                ? trim((string) $action['value'])
                : '';

            if ($tagSlug === '') {
                continue;
            }

            $tag = $tagsBySlug->get($tagSlug);

            if (! $tag instanceof Tag) {
                continue;
            }

            $tagId = (int) $tag->id;

            if (($action['type'] ?? null) === 'set_tag') {
                if (! in_array($tagId, $assignedTagIds, true)) {
                    $timestamp = now();

                    $contact->tags()->attach($tagId, [
                        'assigned_at' => $timestamp,
                        'assigned_by_user_id' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                    $assignedTagIds[] = $tagId;
                }

                continue;
            }

            if (($action['type'] ?? null) === 'remove_tag' && in_array($tagId, $assignedTagIds, true)) {
                $contact->tags()->detach($tagId);
                $assignedTagIds = array_values(array_diff($assignedTagIds, [$tagId]));
            }
        }

        return $contact->refresh();
    }
}
