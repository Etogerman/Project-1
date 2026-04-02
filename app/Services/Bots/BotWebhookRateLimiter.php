<?php

namespace App\Services\Bots;

use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class BotWebhookRateLimiter
{
    public function check(Request $request, Channel $channel): ?int
    {
        $maxPerMinute = $this->resolveMaxPerMinute($channel);

        if ($maxPerMinute <= 0) {
            return null;
        }

        $key = $this->resolveKey($channel);

        if (RateLimiter::tooManyAttempts($key, $maxPerMinute)) {
            return max(1, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    public function resolveMaxPerMinute(Channel $channel): int
    {
        return max(0, (int) config("bots.rate_limit.{$channel->platform}.max_per_minute", 0));
    }

    private function resolveKey(Channel $channel): string
    {
        return sprintf('bot-webhook:%d', $channel->id);
    }
}
