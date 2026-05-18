<?php

namespace App\Jobs;

use App\Models\ScenarioV3ScheduledTransition;
use App\Services\Scenarios\GenericDbScenarioRuntime;
use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessScenarioV3ScheduledTransitionJob implements ShouldQueue
{
    public const DEFAULT_QUEUE = 'bot-replies';

    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $scheduledTransitionId,
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

    public function handle(ScenarioRegistry $scenarioRegistry): void
    {
        $transition = ScenarioV3ScheduledTransition::query()->find($this->scheduledTransitionId);

        if (! $transition instanceof ScenarioV3ScheduledTransition) {
            return;
        }

        $runtime = $scenarioRegistry->makeRuntimeForVersion(
            $transition->scenario_code,
            (int) $transition->published_version_id,
        );

        if (! $runtime instanceof GenericDbScenarioRuntime) {
            $this->cancelTransition($transition, 'Версия сценария недоступна.');

            return;
        }

        $runtime->handleScheduledV3Transition($transition);
    }

    private function cancelTransition(ScenarioV3ScheduledTransition $transition, string $errorMessage): void
    {
        if ($transition->status !== ScenarioV3ScheduledTransition::STATUS_SCHEDULED) {
            return;
        }

        $transition->forceFill([
            'status' => ScenarioV3ScheduledTransition::STATUS_CANCELLED,
            'finished_at' => now(),
            'error_message' => $errorMessage,
        ])->save();

        Log::info('scenario.v3.delayed_transition.finished', [
            'transition_id' => $transition->id,
            'status' => ScenarioV3ScheduledTransition::STATUS_CANCELLED,
            'scenario_code' => $transition->scenario_code,
            'scenario_run_id' => $transition->scenario_run_id,
            'dialog_id' => $transition->dialog_id,
            'published_version_id' => $transition->published_version_id,
            'edge_key' => $transition->edge_key,
            'error_message' => $errorMessage,
        ]);
    }
}
