<?php

namespace App\Services\Scenarios;

use App\Models\ScenarioRun;

interface SupportsTelegramScenarioCallbackContinuation
{
    public function supportsTelegramScenarioCallbackContinuation(ScenarioRun $run, string $callbackData): bool;
}
