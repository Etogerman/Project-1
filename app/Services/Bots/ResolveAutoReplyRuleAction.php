<?php

namespace App\Services\Bots;

use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagCondition;
use App\Models\Channel;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ResolveAutoReplyRuleAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(
        Channel $channel,
        Contact $contact,
        ?string $messageText,
        ?string $messageParameter = null,
    ): Collection
    {
        $contactHasPhone = $contact->phoneNumbers()->exists();
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $rootContactTagIds = $rootContact->tags()
            ->pluck('tags.id')
            ->map(fn (mixed $tagId): int => (int) $tagId)
            ->all();
        $normalizedText = AutoReplyRule::normalizeKeyword($messageText);
        $normalizedParameter = AutoReplyRule::normalizeKeyword($messageParameter);
        $matchedRules = collect();

        if (filled($normalizedParameter)) {
            $matchedRules = $matchedRules->concat($this->resolveExactRules(
                $channel,
                $contactHasPhone,
                $rootContactTagIds,
                $normalizedParameter,
                AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            ));
            $matchedRules = $matchedRules->concat($this->resolveExactRules(
                $channel,
                $contactHasPhone,
                $rootContactTagIds,
                $normalizedParameter,
                AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
            ));
        }

        if (filled($normalizedText)) {
            $matchedRules = $matchedRules->concat($this->resolveExactRules(
                $channel,
                $contactHasPhone,
                $rootContactTagIds,
                $normalizedText,
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            ));
            $matchedRules = $matchedRules->concat($this->resolveExactRules(
                $channel,
                $contactHasPhone,
                $rootContactTagIds,
                $normalizedText,
                AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
            ));

            $matchedRules = $matchedRules->concat(
                AutoReplyRule::query()
                ->active()
                ->forChannel($channel)
                ->where('match_scope', AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT)
                ->where(fn (Builder $query) => $this->applyPhoneConditionFilter($query, $contactHasPhone))
                ->where(fn (Builder $query) => $this->applyTagConditionFilter($query, $rootContactTagIds))
                ->orderByRaw('char_length(normalized_keyword) desc')
                ->orderBy('id')
                ->get()
                ->filter(fn (AutoReplyRule $rule): bool => filled($rule->normalized_keyword)
                    && str_contains($normalizedText, (string) $rule->normalized_keyword))
            );
        }

        $matchedRules = $matchedRules->concat(
            AutoReplyRule::query()
            ->active()
            ->forChannel($channel)
            ->where('match_scope', AutoReplyRule::MATCH_SCOPE_ANY_INBOUND)
            ->where(fn (Builder $query) => $this->applyPhoneConditionFilter($query, $contactHasPhone))
            ->where(fn (Builder $query) => $this->applyTagConditionFilter($query, $rootContactTagIds))
            ->get()
        );

        return $matchedRules
            ->unique(fn (AutoReplyRule $rule): int => (int) $rule->getKey())
            ->sortBy([
                ['priority', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
    }

    public function resolveDelayedFinalRule(
        Channel $channel,
        Contact $contact,
        ?string $messageParameter,
    ): ?AutoReplyRule {
        $normalizedParameter = AutoReplyRule::normalizeKeyword($messageParameter);

        if (! filled($normalizedParameter) || ! $contact->phoneNumbers()->exists()) {
            return null;
        }

        $rootContact = $this->resolveRootContactAction->handle($contact);
        $rootContactTagIds = $rootContact->tags()
            ->pluck('tags.id')
            ->map(fn (mixed $tagId): int => (int) $tagId)
            ->all();

        return $this->resolveDelayedFinalRules(
            $channel,
            $rootContactTagIds,
            $normalizedParameter,
        )->first();
    }

    /**
     * @param  list<int>  $rootContactTagIds
     */
    protected function resolveExactRules(
        Channel $channel,
        bool $contactHasPhone,
        array $rootContactTagIds,
        string $normalizedValue,
        string $matchScope,
    ): Collection {
        return AutoReplyRule::query()
            ->active()
            ->forChannel($channel)
            ->where('match_scope', $matchScope)
            ->where('normalized_keyword', $normalizedValue)
            ->where(fn (Builder $query) => $this->applyPhoneConditionFilter($query, $contactHasPhone))
            ->where(fn (Builder $query) => $this->applyTagConditionFilter($query, $rootContactTagIds))
            ->get();
    }

    /**
     * @param  list<int>  $rootContactTagIds
     */
    protected function resolveDelayedFinalRules(
        Channel $channel,
        array $rootContactTagIds,
        string $normalizedParameter,
    ): Collection {
        return collect([
            AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
        ])->flatMap(fn (string $matchScope): Collection => AutoReplyRule::query()
            ->active()
            ->forChannel($channel)
            ->where('match_scope', $matchScope)
            ->where('normalized_keyword', $normalizedParameter)
            ->where('contact_phone_condition', AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE)
            ->where(fn (Builder $query) => $this->applyTagConditionFilter($query, $rootContactTagIds))
            ->get())
            ->unique(fn (AutoReplyRule $rule): int => (int) $rule->getKey())
            ->sortBy([
                ['priority', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
    }

    protected function applyPhoneConditionFilter(Builder $query, bool $contactHasPhone): void
    {
        $query->whereNull('contact_phone_condition')
            ->orWhere(
                'contact_phone_condition',
                $contactHasPhone
                    ? AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE
                    : AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE
            );
    }

    /**
     * @param  list<int>  $contactTagIds
     */
    protected function applyTagConditionFilter(Builder $query, array $contactTagIds): void
    {
        if ($contactTagIds === []) {
            $query->whereDoesntHave('tagConditions', function (Builder $query): void {
                $query->where('condition', AutoReplyRuleTagCondition::CONDITION_REQUIRED);
            });

            return;
        }

        $query->whereDoesntHave('tagConditions', function (Builder $query) use ($contactTagIds): void {
            $query->where('condition', AutoReplyRuleTagCondition::CONDITION_EXCLUDED)
                ->whereIn('tag_id', $contactTagIds);
        });

        $query->whereDoesntHave('tagConditions', function (Builder $query) use ($contactTagIds): void {
            $query->where('condition', AutoReplyRuleTagCondition::CONDITION_REQUIRED)
                ->whereNotIn('tag_id', $contactTagIds);
        });
    }
}
