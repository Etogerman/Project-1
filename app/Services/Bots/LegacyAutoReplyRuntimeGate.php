<?php

namespace App\Services\Bots;

class LegacyAutoReplyRuntimeGate
{
    public function rulesEnabled(): bool
    {
        return (bool) config('bots.legacy_auto_reply_rules_enabled', false);
    }
}
