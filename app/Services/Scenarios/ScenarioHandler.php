<?php

namespace App\Services\Scenarios;

use App\Data\Scenarios\ScenarioInboundResult;
use App\Models\Message;
use App\Models\ScenarioRun;

interface ScenarioHandler
{
    public static function code(): string;

    public function shouldStart(Message $message): bool;

    public function start(ScenarioRun $run, Message $message): void;

    public function handleInbound(ScenarioRun $run, Message $message): ScenarioInboundResult;
}
