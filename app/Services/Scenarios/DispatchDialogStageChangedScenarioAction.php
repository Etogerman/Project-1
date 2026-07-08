<?php

namespace App\Services\Scenarios;

use App\Jobs\ProcessScenarioStartJob;
use App\Models\Message;
use App\Models\ScenarioChannelBinding;
use Illuminate\Support\Facades\DB;

class DispatchDialogStageChangedScenarioAction
{
    private const DISPATCH_PAYLOAD_KEY = 'scenario_stage_changed_dispatch';

    public function __construct(
        private readonly ScenarioRegistry $scenarioRegistry,
    ) {}

    public function handle(Message $stageChangedMessage): bool
    {
        return DB::transaction(function () use ($stageChangedMessage): bool {
            $lockedMessage = Message::query()
                ->whereKey($stageChangedMessage->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedMessage instanceof Message || ! $this->isStageChangedMessage($lockedMessage)) {
                return false;
            }

            if ($this->wasAlreadyDispatched($lockedMessage)) {
                return false;
            }

            $lockedMessage->loadMissing(['contact', 'channel', 'contactIdentity', 'dialog']);

            foreach ($this->activeBindingsForChannel((int) $lockedMessage->channel_id) as $binding) {
                if (! $this->scenarioRegistry->enabledForNewStarts($binding->scenario_code)) {
                    continue;
                }

                $runtime = $this->scenarioRegistry->makeRuntime($binding->scenario_code);

                if ($runtime === null || ! $runtime->shouldStart($lockedMessage)) {
                    continue;
                }

                $this->markDispatched($lockedMessage, (string) $binding->scenario_code);

                ProcessScenarioStartJob::dispatch(
                    $lockedMessage->id,
                    $lockedMessage->dialog_id,
                    $binding->scenario_code,
                )->afterCommit();

                return true;
            }

            return false;
        });
    }

    /**
     * @return iterable<int, ScenarioChannelBinding>
     */
    private function activeBindingsForChannel(int $channelId): iterable
    {
        return ScenarioChannelBinding::query()
            ->active()
            ->where('channel_id', $channelId)
            ->orderBy('id')
            ->get();
    }

    private function isStageChangedMessage(Message $message): bool
    {
        return $message->channel_id !== null
            && $message->dialog_id !== null
            && $message->message_kind === Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE
            && $message->sent_by_system_code === Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE
            && (string) data_get($message->raw_payload, 'event', '') === Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE
            && filled(data_get($message->raw_payload, 'to_stage'));
    }

    private function wasAlreadyDispatched(Message $message): bool
    {
        return filled(data_get($message->raw_payload, self::DISPATCH_PAYLOAD_KEY.'.scenario_code'));
    }

    private function markDispatched(Message $message, string $scenarioCode): void
    {
        $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
        data_set($payload, self::DISPATCH_PAYLOAD_KEY, [
            'scenario_code' => $scenarioCode,
            'dispatched_at' => now()->toIso8601String(),
        ]);

        $message->forceFill([
            'raw_payload' => $payload,
        ])->save();
    }
}
