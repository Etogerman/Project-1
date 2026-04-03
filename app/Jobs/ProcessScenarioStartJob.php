<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\ScenarioChannelBinding;
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

class ProcessScenarioStartJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $inboundMessageId,
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
            (new WithoutOverlapping("scenario-start:message:{$this->inboundMessageId}"))->expireAfter(180),
        ];
    }

    public function handle(ScenarioRegistry $scenarioRegistry): void
    {
        $message = Message::query()
            ->with(['channel', 'contact', 'contactIdentity', 'dialog'])
            ->find($this->inboundMessageId);

        if (! $message instanceof Message || $message->message_kind !== Message::KIND_INBOUND_USER || $message->dialog_id === null) {
            return;
        }

        if ($message->contact?->isInDataCollection()) {
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

        $handler = $scenarioRegistry->make($binding->scenario_code);

        if (! $handler instanceof ScenarioHandler || ! $handler->shouldStart($message)) {
            return;
        }

        if (ScenarioRun::query()->active()->where('dialog_id', $message->dialog_id)->exists()) {
            return;
        }

        $run = ScenarioRun::query()->create([
            'dialog_id' => $message->dialog_id,
            'scenario_code' => $binding->scenario_code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => null,
            'state_payload' => [],
            'started_at' => now(),
        ]);

        try {
            $handler->start($run, $message);
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
}
