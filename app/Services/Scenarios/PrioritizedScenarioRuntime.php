<?php

namespace App\Services\Scenarios;

use App\Models\Message;
use App\Models\ScenarioRun;

interface PrioritizedScenarioRuntime
{
    public function shouldStartBeforeActiveRun(Message $message): bool;

    public function shouldCancelActiveRunOnStart(Message $message): bool;

    public function startBeforeActiveRunWithoutCancelling(Message $message, ScenarioRun $activeRun): bool;
}
