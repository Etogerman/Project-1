<?php

namespace App\Services\Scenarios;

use App\Jobs\ProcessScenarioInboundJob;
use App\Jobs\ProcessScenarioStartJob;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;

class DispatchStoredInboundScenarioAction
{
    private const IBIZA_SCENARIO_CODE = 'vip_ibiza';

    private const IBIZA_RESTART_PARAMETER = 'vip_ibiza_apply';

    private const IBIZA_RESTART_OUTCOME = 'restart_requested';

    public function __construct(
        private readonly ScenarioRegistry $scenarioRegistry,
    ) {}

    public function handle(Channel $channel, Message $storedMessage): bool
    {
        if (
            ! in_array($storedMessage->message_kind, [
                Message::KIND_INBOUND_USER,
                Message::KIND_INBOUND_CONTACT_SHARE,
            ], true)
            || $storedMessage->dialog_id === null
        ) {
            return false;
        }

        if ($this->restartTelegramVipIbizaRunIfRequested($storedMessage)) {
            return true;
        }

        if ($this->continueActiveRun($storedMessage)) {
            return true;
        }

        if ($storedMessage->message_kind !== Message::KIND_INBOUND_USER) {
            return false;
        }

        return $this->startMatchingScenario($channel, $storedMessage);
    }

    public function continueActiveRun(Message $storedMessage): bool
    {
        if (
            ! in_array($storedMessage->message_kind, [
                Message::KIND_INBOUND_USER,
                Message::KIND_INBOUND_CONTACT_SHARE,
            ], true)
            || $storedMessage->dialog_id === null
        ) {
            return false;
        }

        $activeRun = ScenarioRun::query()
            ->active()
            ->where('dialog_id', $storedMessage->dialog_id)
            ->orderBy('id')
            ->first();

        if ($activeRun instanceof ScenarioRun) {
            if (
                $storedMessage->message_kind === Message::KIND_INBOUND_CONTACT_SHARE
                && ! $this->activeRunSupportsContactShare($activeRun)
            ) {
                return false;
            }

            if (
                $storedMessage->message_kind === Message::KIND_INBOUND_USER
                && $this->isTelegramScenarioCallbackMessage($storedMessage)
                && ! $this->activeRunSupportsTelegramScenarioCallback($activeRun, $storedMessage)
            ) {
                return false;
            }

            ProcessScenarioInboundJob::dispatch($storedMessage->id, $activeRun->id)->afterCommit();

            return true;
        }

        return false;
    }

    public function restartTelegramVipIbizaRunIfRequested(Message $storedMessage): bool
    {
        if (! $this->isTelegramVipIbizaRestartMessage($storedMessage)) {
            return false;
        }

        $activeIbizaRun = ScenarioRun::query()
            ->active()
            ->where('dialog_id', $storedMessage->dialog_id)
            ->where('scenario_code', self::IBIZA_SCENARIO_CODE)
            ->orderBy('id')
            ->first();

        if (! $activeIbizaRun instanceof ScenarioRun) {
            return false;
        }

        $activeIbizaRun->forceFill([
            'status' => ScenarioRun::STATUS_CANCELLED,
            'current_step' => null,
            'exit_outcome' => self::IBIZA_RESTART_OUTCOME,
            'finished_at' => now(),
        ])->save();

        ProcessScenarioStartJob::dispatch(
            $storedMessage->id,
            $storedMessage->dialog_id,
            self::IBIZA_SCENARIO_CODE,
        )->afterCommit();

        return true;
    }

    public function startMatchingScenario(Channel $channel, Message $storedMessage): bool
    {
        if ($storedMessage->message_kind !== Message::KIND_INBOUND_USER || $storedMessage->dialog_id === null) {
            return false;
        }

        if ($this->isStoredTelegramScenarioCallback($channel, $storedMessage)) {
            return true;
        }

        $storedMessage->loadMissing(['contact', 'channel', 'contactIdentity', 'dialog']);

        foreach ($this->activeBindingsForChannel($channel->id) as $binding) {
            $runtime = $this->scenarioRegistry->makeRuntime($binding->scenario_code);

            if ($runtime === null) {
                continue;
            }

            if (! $runtime->shouldStart($storedMessage)) {
                continue;
            }

            ProcessScenarioStartJob::dispatch(
                $storedMessage->id,
                $storedMessage->dialog_id,
                $binding->scenario_code,
            )->afterCommit();

            return true;
        }

        return false;
    }

    private function activeRunSupportsContactShare(ScenarioRun $activeRun): bool
    {
        $runtime = $this->scenarioRegistry->makeRuntime($activeRun->scenario_code);

        if ($runtime === null) {
            return false;
        }

        return $runtime->supportsContactShareContinuation($activeRun);
    }

    private function activeRunSupportsTelegramScenarioCallback(ScenarioRun $activeRun, Message $storedMessage): bool
    {
        $runtime = $this->scenarioRegistry->makeRuntime($activeRun->scenario_code);

        if ($runtime === null) {
            return false;
        }

        return $runtime->supportsTelegramCallbackContinuation(
            $activeRun,
            (string) data_get($storedMessage->raw_payload, 'callback_query.data', ''),
        );
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

    private function isStoredTelegramScenarioCallback(Channel $channel, Message $storedMessage): bool
    {
        return $channel->platform === Channel::PLATFORM_TELEGRAM
            && $this->isTelegramScenarioCallbackMessage($storedMessage);
    }

    private function isTelegramScenarioCallbackMessage(Message $storedMessage): bool
    {
        return is_array($storedMessage->raw_payload)
            && is_array(data_get($storedMessage->raw_payload, 'callback_query'))
            && str_starts_with((string) data_get($storedMessage->raw_payload, 'callback_query.data', ''), 'scenario:');
    }

    private function isTelegramVipIbizaRestartMessage(Message $storedMessage): bool
    {
        $storedMessage->loadMissing('channel');

        return $storedMessage->channel?->platform === Channel::PLATFORM_TELEGRAM
            && $storedMessage->message_kind === Message::KIND_INBOUND_USER
            && $storedMessage->dialog_id !== null
            && trim((string) $storedMessage->message_parameter) === self::IBIZA_RESTART_PARAMETER;
    }
}
