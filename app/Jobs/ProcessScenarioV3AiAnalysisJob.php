<?php

namespace App\Jobs;

use App\Services\Scenarios\GenericDbScenarioRuntime;
use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessScenarioV3AiAnalysisJob implements ShouldQueue
{
    public const DEFAULT_QUEUE = 'bot-replies';

    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $scenarioRunId,
        public int $dialogId,
        public int $inboundMessageId,
        public string $scenarioCode,
        public int $publishedVersionId,
        public string $blockId,
        public string $token,
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
        $runtime = $scenarioRegistry->makeRuntimeForVersion(
            $this->scenarioCode,
            $this->publishedVersionId,
        );

        if (! $runtime instanceof GenericDbScenarioRuntime) {
            Log::info('scenario.v3_ai_analysis.cancelled', [
                'scenario_code' => $this->scenarioCode,
                'scenario_run_id' => $this->scenarioRunId,
                'dialog_id' => $this->dialogId,
                'block_id' => $this->blockId,
                'reason' => 'runtime_unavailable',
            ]);

            return;
        }

        $runtime->handleDelayedV3AiAnalysis(
            $this->scenarioRunId,
            $this->dialogId,
            $this->inboundMessageId,
            $this->blockId,
            $this->token,
        );
    }
}
