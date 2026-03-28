<?php

namespace App\Services\Bots;

use App\Models\AutoReplyRule;
use App\Models\Channel;

class ResolveAutoReplyRuleAction
{
    public function handle(Channel $channel, ?string $messageText): ?AutoReplyRule
    {
        $normalizedText = AutoReplyRule::normalizeKeyword($messageText);

        if (! filled($normalizedText)) {
            return null;
        }

        return AutoReplyRule::query()
            ->active()
            ->where('channel_id', $channel->id)
            ->where('normalized_keyword', $normalizedText)
            ->first();
    }
}
