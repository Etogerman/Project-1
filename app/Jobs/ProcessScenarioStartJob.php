<?php

namespace App\Jobs;

use App\Models\Dialog;
use App\Models\Message;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Services\Dialogs\CanSendThroughDialogAction;
use App\Services\Scenarios\PrioritizedScenarioRuntime;
use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessScenarioStartJob implements ShouldQueue
{
    public const DEFAULT_QUEUE = 'bot-replies';

    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $inboundMessageId,
        public ?int $dialogId,
        public string $scenarioCode,
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
            (new WithoutOverlapping($this->overlapKey()))->expireAfter(180),
        ];
    }

    public function handle(
        ScenarioRegistry $scenarioRegistry,
        ?CanSendThroughDialogAction $canSendThroughDialogAction = null,
    ): void {
        $canSendThroughDialogAction ??= app(CanSendThroughDialogAction::class);

        $message = Message::query()
            ->with(['channel', 'contact', 'contactIdentity', 'dialog.channel', 'dialog.currentContactIdentity'])
            ->find($this->inboundMessageId);

        if (! $message instanceof Message || ! $this->canUseMessageAsStartSource($message)) {
            return;
        }

        $snapshotDialogId = $this->snapshotDialogId();
        $effectiveDialogId = $snapshotDialogId ?? (int) $message->dialog_id;

        if ($snapshotDialogId !== null && (int) $message->dialog_id !== $snapshotDialogId) {
            return;
        }

        if (! $message->dialog instanceof Dialog || ! $canSendThroughDialogAction->handle($message->dialog)) {
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

        if (! $scenarioRegistry->enabledForNewStarts($binding->scenario_code)) {
            return;
        }

        $runtime = $scenarioRegistry->makeRuntime($binding->scenario_code);

        if ($runtime === null || ! $runtime->shouldStart($message)) {
            return;
        }

        if ($this->hasRunForStartSource($effectiveDialogId, $binding->scenario_code, $message)) {
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
                'state_payload' => $this->initialStatePayloadForStartSource($message),
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

    protected function canUseMessageAsStartSource(Message $message): bool
    {
        if ($message->dialog_id === null) {
            return false;
        }

        return $message->message_kind === Message::KIND_INBOUND_USER
            || $this->isDialogStageChangeMessage($message);
    }

    protected function isDialogStageChangeMessage(Message $message): bool
    {
        return $message->message_kind === Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE
            && $message->sent_by_system_code === Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE
            && (string) data_get($message->raw_payload, 'event', '') === Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE
            && filled(data_get($message->raw_payload, 'to_stage'));
    }

    protected function hasRunForStartSource(int $dialogId, string $scenarioCode, Message $message): bool
    {
        if (! $this->isDialogStageChangeMessage($message)) {
            return false;
        }

        return ScenarioRun::query()
            ->where('dialog_id', $dialogId)
            ->where('scenario_code', $scenarioCode)
            ->whereRaw(
                "state_payload #>> '{v3,entrypoint,source_event}' = ?",
                [Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE],
            )
            ->whereRaw(
                "state_payload #>> '{v3,entrypoint,source_message_id}' = ?",
                [(string) $message->id],
            )
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function initialStatePayloadForStartSource(Message $message): array
    {
        if (! $this->isDialogStageChangeMessage($message)) {
            return [];
        }

        return [
            'v3' => [
                'entrypoint' => [
                    'source_event' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
                    'source_message_id' => (int) $message->id,
                    'source_stage_from_key' => trim((string) data_get($message->raw_payload, 'from_stage', '')),
                    'source_stage_to_key' => trim((string) data_get($message->raw_payload, 'to_stage', '')),
                ],
            ],
        ];
    }

    protected function activeRun(int $dialogId): ?ScenarioRun
    {
        return ScenarioRun::query()
            ->active()
            ->where('dialog_id', $dialogId)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return list<int>
     */
    protected function cancelActiveRuns(int $dialogId, string $newScenarioCode, int $messageId): array
    {
        $activeRuns = ScenarioRun::query()
            ->active()
            ->where('dialog_id', $dialogId)
            ->lockForUpdate()
            ->get();

        $cancelledRunIds = [];

        foreach ($activeRuns as $activeRun) {
            /** @var ScenarioRun $activeRun */
            $oldScenarioCode = (string) $activeRun->scenario_code;
            $cancelledRunIds[] = (int) $activeRun->id;

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

        return $cancelledRunIds;
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
