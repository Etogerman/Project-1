<?php

namespace App\Services\Bots;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

class ResolveAutoReplyRuleAction
{
    public function handle(Channel $channel, Contact $contact, ?string $messageText): ?AutoReplyRule
    {
        $contactHasPhone = $contact->phoneNumbers()->exists();
        $normalizedText = AutoReplyRule::normalizeKeyword($messageText);

        if (filled($normalizedText)) {
            $exactRule = AutoReplyRule::query()
                ->active()
                ->where('channel_id', $channel->id)
                ->where('match_scope', AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD)
                ->where('normalized_keyword', $normalizedText)
                ->where(fn (Builder $query) => $this->applyPhoneConditionFilter($query, $contactHasPhone))
                ->first();

            if ($exactRule instanceof AutoReplyRule) {
                return $exactRule;
            }
        }

        return AutoReplyRule::query()
            ->active()
            ->where('channel_id', $channel->id)
            ->where('match_scope', AutoReplyRule::MATCH_SCOPE_ANY_INBOUND)
            ->where(fn (Builder $query) => $this->applyPhoneConditionFilter($query, $contactHasPhone))
            ->orderByRaw(
                'CASE WHEN contact_phone_condition = ? THEN 0 WHEN contact_phone_condition IS NULL THEN 1 ELSE 2 END',
                [$contactHasPhone
                    ? AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE
                    : AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE]
            )
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
}
