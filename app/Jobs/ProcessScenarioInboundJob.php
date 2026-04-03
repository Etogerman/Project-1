<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\ScenarioRun;
use App\Services\Scenarios\ScenarioHandler;
use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessScenarioInboundJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $inboundMessageId,
        public int $scenarioRunId,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("scenario-inbound:run:{$this->scenarioRunId}"))->expireAfter(180),
        ];
    }

    public function handle(ScenarioRegistry $scenarioRegistry): void
    {
        $message = Message::query()
            ->with(['channel', 'contact', 'contactIdentity', 'dialog'])
            ->find($this->inboundMessageId);

        $run = ScenarioRun::query()->find($this->scenarioRunId);

        if (
            ! $message instanceof Message
            || ! $run instanceof ScenarioRun
            || ! $run->isActive()
            || $message->dialog_id === null
            || (int) $run->dialog_id !== (int) $message->dialog_id
        ) {
            return;
        }

        $handler = $scenarioRegistry->make($run->scenario_code);

        if (! $handler instanceof ScenarioHandler) {
            return;
        }

        try {
            $result = $handler->handleInbound($run, $message);
        } catch (Throwable $throwable) {
            $message->channel?->markError($throwable);

            $run->forceFill([
                'status' => ScenarioRun::STATUS_FAILED,
                'current_step' => null,
                'exit_outcome' => 'inbound_failed',
                'finished_at' => now(),
            ])->save();

            throw $throwable;
        }

        $run->forceFill([
            'status' => $result->status,
            'current_step' => $result->currentStep,
            'state_payload' => $result->statePayload,
            'exit_outcome' => $result->exitOutcome,
            'finished_at' => $result->status === ScenarioRun::STATUS_ACTIVE ? null : now(),
        ])->save();

        if (! $result->consumed) {
            ProcessAutoReplyJob::dispatch($message->id)->afterCommit();
        }
    }
}
