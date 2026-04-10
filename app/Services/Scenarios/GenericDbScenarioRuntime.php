<?php

namespace App\Services\Scenarios;

use App\Data\Scenarios\ScenarioInboundResult;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioRun;
use App\Models\ScenarioVersion;

class GenericDbScenarioRuntime implements ResolvedScenarioRuntime
{
    public function __construct(
        private readonly Scenario $scenario,
        private readonly ScenarioVersion $publishedVersion,
    ) {}

    public function code(): string
    {
        return (string) $this->scenario->code;
    }

    public function shouldStart(Message $message): bool
    {
        return false;
    }

    public function start(ScenarioRun $run, Message $message): void
    {
        // Slice 0 only wires DB-backed scenarios into runtime discovery.
    }

    public function handleInbound(ScenarioRun $run, Message $message): ScenarioInboundResult
    {
        return new ScenarioInboundResult(
            consumed: false,
            status: ScenarioRun::STATUS_ACTIVE,
            currentStep: $run->current_step,
            statePayload: is_array($run->state_payload) ? $run->state_payload : [],
            exitOutcome: null,
        );
    }
}
