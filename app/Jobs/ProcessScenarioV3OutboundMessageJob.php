<?php

namespace App\Jobs;

use App\Models\ScenarioV3OutboundMessage;
use App\Services\Scenarios\GenericDbScenarioRuntime;
use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessScenarioV3OutboundMessageJob implements ShouldQueue
{
    public const DEFAULT_QUEUE = 'bot-replies';

    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $outboundMessageId,
    ) {
        $this->onQueue(self::queueName());
    }

    public static function queueName(): string
    {
        $queue = trim((string) config('bots.scenario_queue', self::DEFAULT_QUEUE));

        return $queue !== '' ? $queue : self::DEFAULT_QUEUE;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("scenario-v3-outbound:{$this->outboundMessageId}"))->expireAfter(180),
        ];
    }

    public function handle(ScenarioRegistry $scenarioRegistry): void
    {
        $outboundMessage = ScenarioV3OutboundMessage::query()->find($this->outboundMessageId);

        if (! $outboundMessage instanceof ScenarioV3OutboundMessage) {
            return;
        }

        $runtime = $scenarioRegistry->makeRuntimeForVersion(
            $outboundMessage->scenario_code,
            (int) $outboundMessage->published_version_id,
        );

        if (! $runtime instanceof GenericDbScenarioRuntime) {
            GenericDbScenarioRuntime::failV3OutboundMessageWithoutRuntime($outboundMessage, 'Версия сценария недоступна.');

            return;
        }

        $runtime->handleV3OutboundMessage($outboundMessage);
    }
}
