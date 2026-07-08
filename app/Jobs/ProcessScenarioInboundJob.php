<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\ScenarioRun;
use App\Services\Dialogs\DialogAutomationGate;
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
    public const DEFAULT_QUEUE = 'bot-replies';

    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $inboundMessageId,
        public int $scenarioRunId,
    ) {
        $this->onQueue(self::queueName());
    }

    public static function queueName(): string
    {
        $queue = trim((string) config('bots.scenario_queue', self::DEFAULT_QUEUE));

        return $queue !== '' ? $queue : self::DEFAULT_QUEUE;
    }

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

    public function handle(
        ScenarioRegistry $scenarioRegistry,
        ?DialogAutomationGate $dialogAutomationGate = null,
    ): void {
        $dialogAutomationGate ??= app(DialogAutomationGate::class);

        $message = Message::query()
            ->with(['channel', 'contact', 'contactIdentity', 'dialog.dialogStage'])
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

        if (! $dialogAutomationGate->acceptsMessage($message)) {
            $this->cancelRunBecauseDialogBlacklisted($run);

            return;
        }

        $runtime = $scenarioRegistry->makeRuntime($run->scenario_code);

        if ($runtime === null) {
            return;
        }

        try {
            $result = $runtime->handleInbound($run, $message);
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

        if (! $result->persisted) {
            $run->forceFill([
                'status' => $result->status,
                'current_step' => $result->currentStep,
                'state_payload' => $result->statePayload,
                'exit_outcome' => $result->exitOutcome,
                'finished_at' => $result->status === ScenarioRun::STATUS_ACTIVE ? null : now(),
            ])->save();
        }

        if (! $result->consumed) {
            ProcessAutoReplyJob::dispatch($message->id)->afterCommit();
        }
    }

    private function cancelRunBecauseDialogBlacklisted(ScenarioRun $run): void
    {
        if (! $run->isActive()) {
            return;
        }

        $run->forceFill([
            'status' => ScenarioRun::STATUS_CANCELLED,
            'current_step' => null,
            'exit_outcome' => DialogAutomationGate::REASON_BLACKLIST_STAGE,
            'finished_at' => now(),
        ])->save();
    }
}
