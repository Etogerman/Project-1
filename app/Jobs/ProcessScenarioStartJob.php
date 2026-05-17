<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Services\Scenarios\PrioritizedScenarioRuntime;
use App\Services\Dialogs\CanSendThroughDialogAction;
use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessScenarioStartJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $inboundMessageId,
        public ?int $dialogId,
        public string $scenarioCode,
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
            (new WithoutOverlapping($this->overlapKey()))->expireAfter(180),
        ];
    }

    public function handle(
        ScenarioRegistry $scenarioRegistry,
        ?CanSendThroughDialogAction $canSendThroughDialogAction = null,
    ): void
    {
        $canSendThroughDialogAction ??= app(CanSendThroughDialogAction::class);

        $message = Message::query()
            ->with(['channel', 'contact', 'contactIdentity', 'dialog.channel', 'dialog.currentContactIdentity'])
            ->find($this->inboundMessageId);

        if (! $message instanceof Message || $message->message_kind !== Message::KIND_INBOUND_USER || $message->dialog_id === null) {
            return;
        }

        $snapshotDialogId = $this->snapshotDialogId();
        $effectiveDialogId = $snapshotDialogId ?? (int) $message->dialog_id;

        if ($snapshotDialogId !== null && (int) $message->dialog_id !== $snapshotDialogId) {
            return;
        }

        if ($message->contact?->isInDataCollection()) {
            return;
        }

        if (! $message->dialog instanceof \App\Models\Dialog || ! $canSendThroughDialogAction->handle($message->dialog)) {
            return;
        }

        $binding = ScenarioChannelBinding::query()
            ->active()
            ->where('channel_id', $message->channel_id)
            ->where('scenario_code', $this->scenarioCode)
            ->first();

        if (! $binding instanceof ScenarioChannelBinding) {
            return;
        }

        $runtime = $scenarioRegistry->makeRuntime($binding->scenario_code);

        if ($runtime === null || ! $runtime->shouldStart($message)) {
            return;
        }

        $activeRun = $this->activeRun($effectiveDialogId);

        if ($activeRun instanceof ScenarioRun) {
            if (! $runtime instanceof PrioritizedScenarioRuntime) {
                return;
            }

            if (! $runtime->shouldCancelActiveRunOnStart($message)) {
                $runtime->startBeforeActiveRunWithoutCancelling($message, $activeRun);

                return;
            }

            $this->cancelActiveRuns($effectiveDialogId, $binding->scenario_code, $message->id);
        }

        try {
            $run = ScenarioRun::query()->create([
                'dialog_id' => $effectiveDialogId,
                'scenario_code' => $binding->scenario_code,
                'status' => ScenarioRun::STATUS_ACTIVE,
                'current_step' => null,
                'state_payload' => [],
                'started_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($this->wasUniqueConstraintViolation($exception) && $this->activeRunExists($effectiveDialogId)) {
                return;
            }

            throw $exception;
        }

        try {
            $runtime->start($run, $message);
        } catch (Throwable $throwable) {
            $message->channel?->markError($throwable);

            $run->forceFill([
                'status' => ScenarioRun::STATUS_FAILED,
                'current_step' => null,
                'exit_outcome' => 'start_failed',
                'finished_at' => now(),
            ])->save();

            throw $throwable;
        }
    }

    protected function activeRunExists(int $dialogId): bool
    {
        return $this->activeRun($dialogId) instanceof ScenarioRun;
    }

    protected function activeRun(int $dialogId): ?ScenarioRun
    {
        return ScenarioRun::query()
            ->active()
            ->where('dialog_id', $dialogId)
            ->orderBy('id')
            ->first();
    }

    protected function cancelActiveRuns(int $dialogId, string $newScenarioCode, int $messageId): void
    {
        $activeRuns = ScenarioRun::query()
            ->active()
            ->where('dialog_id', $dialogId)
            ->lockForUpdate()
            ->get();

        foreach ($activeRuns as $activeRun) {
            /** @var ScenarioRun $activeRun */
            $oldScenarioCode = (string) $activeRun->scenario_code;

            $activeRun->forceFill([
                'status' => ScenarioRun::STATUS_CANCELLED,
                'current_step' => null,
                'exit_outcome' => 'interrupted_by_v3_start',
                'finished_at' => now(),
            ])->save();

            Log::info('scenario.v3_start_interrupted_active_run', [
                'dialog_id' => $dialogId,
                'message_id' => $messageId,
                'old_scenario_code' => $oldScenarioCode,
                'new_scenario_code' => $newScenarioCode,
                'old_run_id' => $activeRun->id,
            ]);
        }
    }

    protected function wasUniqueConstraintViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505';
    }

    protected function overlapKey(): string
    {
        $dialogId = $this->snapshotDialogId();

        if ($dialogId !== null) {
            return "scenario-start:dialog:{$dialogId}";
        }

        return "scenario-start:message:{$this->inboundMessageId}";
    }

    protected function snapshotDialogId(): ?int
    {
        return isset($this->dialogId) ? $this->dialogId : null;
    }
}
