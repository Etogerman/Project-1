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

        $runtime = $scenarioRegistry->makeRuntime($transition->scenario_code);

        if (! $runtime instanceof GenericDbScenarioRuntime) {
            return;
        }

        $runtime->handleScheduledV3Transition($transition);
    }
}
