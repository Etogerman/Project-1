<?php

namespace App\Services\Bots;

use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagCondition;
use App\Models\Channel;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Database\Eloquent\Builder;

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
    ): ?AutoReplyRule
    {
        $contactHasPhone = $contact->phoneNumbers()->exists();
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $rootContactTagIds = $rootContact->tags()
            ->pluck('tags.id')
            ->map(fn (mixed $tagId): int => (int) $tagId)
            ->all();
        $normalizedText = AutoReplyRule::normalizeKeyword($messageText);
        $normalizedParameter = AutoReplyRule::normalizeKeyword($messageParameter);

        if (filled($normalizedParameter)) {
            $parameterRule = $this->resolveExactRule(
                $channel,
                $contactHasPhone,
                $rootContactTagIds,
                $normalizedParameter,
                AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            );

            if ($parameterRule instanceof AutoReplyRule) {
                return $parameterRule;
            }

            $combinedParameterRule = $this->resolveExactRule(
                $channel,
                $contactHasPhone,
                $rootContactTagIds,
                $normalizedParameter,
                AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
            );

            if ($combinedParameterRule instanceof AutoReplyRule) {
                return $combinedParameterRule;
            }
        }

        if (filled($normalizedText)) {
            $exactRule = $this->resolveExactRule(
                $channel,
                $contactHasPhone,
                $rootContactTagIds,
                $normalizedText,
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            );

            if ($exactRule instanceof AutoReplyRule) {
                return $exactRule;
            }

            $combinedTextRule = $this->resolveExactRule(
                $channel,
                $contactHasPhone,
                $rootContactTagIds,
                $normalizedText,
                AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
            );

            if ($combinedTextRule instanceof AutoReplyRule) {
                return $combinedTextRule;
            }

            $containsRule = AutoReplyRule::query()
                ->active()
                ->where('channel_id', $channel->id)
                ->where('match_scope', AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT)
                ->where(fn (Builder $query) => $this->applyPhoneConditionFilter($query, $contactHasPhone))
                ->where(fn (Builder $query) => $this->applyTagConditionFilter($query, $rootContactTagIds))
                ->orderByRaw('char_length(normalized_keyword) desc')
                ->orderBy('id')
                ->get()
                ->first(fn (AutoReplyRule $rule): bool => filled($rule->normalized_keyword)
                    && str_contains($normalizedText, (string) $rule->normalized_keyword));

            if ($containsRule instanceof AutoReplyRule) {
                return $containsRule;
            }
        }

        return AutoReplyRule::query()
            ->active()
            ->where('channel_id', $channel->id)
            ->where('match_scope', AutoReplyRule::MATCH_SCOPE_ANY_INBOUND)
            ->where(fn (Builder $query) => $this->applyPhoneConditionFilter($query, $contactHasPhone))
            ->where(fn (Builder $query) => $this->applyTagConditionFilter($query, $rootContactTagIds))
            ->orderByRaw(
                'CASE WHEN contact_phone_condition = ? THEN 0 WHEN contact_phone_condition IS NULL THEN 1 ELSE 2 END',
                [$contactHasPhone
                    ? AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE
                    : AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE]
            )
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<int>  $rootContactTagIds
     */
    protected function resolveExactRule(
        Channel $channel,
        bool $contactHasPhone,
        array $rootContactTagIds,
        string $normalizedValue,
        string $matchScope,
    ): ?AutoReplyRule {
        return AutoReplyRule::query()
            ->active()
            ->where('channel_id', $channel->id)
            ->where('match_scope', $matchScope)
            ->where('normalized_keyword', $normalizedValue)
            ->where(fn (Builder $query) => $this->applyPhoneConditionFilter($query, $contactHasPhone))
            ->where(fn (Builder $query) => $this->applyTagConditionFilter($query, $rootContactTagIds))
            ->orderBy('id')
            ->first();
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
