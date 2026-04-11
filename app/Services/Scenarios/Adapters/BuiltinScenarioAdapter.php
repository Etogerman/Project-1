<?php

namespace App\Services\Scenarios\Adapters;

use App\Data\Scenarios\ScenarioInboundResult;
use App\Models\Message;
use App\Models\ScenarioRun;
use App\Services\Scenarios\ResolvedScenarioRuntime;
use App\Services\Scenarios\ScenarioHandler;
use App\Services\Scenarios\SupportsTelegramScenarioCallbackContinuation;

class BuiltinScenarioAdapter implements ResolvedScenarioRuntime
{
    public function __construct(
        private readonly string $scenarioCode,
        private readonly ScenarioHandler $handler,
    ) {}

    public function code(): string
    {
        return $this->scenarioCode;
    }

    public function shouldStart(Message $message): bool
    {
        return $this->handler->shouldStart($message);
    }

    public function start(ScenarioRun $run, Message $message): void
    {
        $this->handler->start($run, $message);
    }

    public function supportsContactShareContinuation(ScenarioRun $run): bool
    {
        return false;
    }

    public function supportsTelegramCallbackContinuation(ScenarioRun $run, string $callbackData): bool
    {
        if (! $this->handler instanceof SupportsTelegramScenarioCallbackContinuation) {
            return false;
        }

        return $this->handler->supportsTelegramScenarioCallbackContinuation($run, $callbackData);
    }

    public function handleInbound(ScenarioRun $run, Message $message): ScenarioInboundResult
    {
        return $this->handler->handleInbound($run, $message);
    }
}
