<?php

namespace App\Services\Scenarios;

use App\Jobs\ProcessScenarioInboundJob;
use App\Jobs\ProcessScenarioStartJob;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Services\Dialogs\DialogAutomationGate;

class DispatchStoredInboundScenarioAction
{
    /**
     * @var list<string>
     */
    private const IBIZA_START_PARAMETERS = [
        'vip_ibiza_apply',
        'vip_ibiza_tg1',
        'vip_ibiza_inst1',
    ];

    public function __construct(
        private readonly ScenarioRegistry $scenarioRegistry,
        private readonly DialogAutomationGate $dialogAutomationGate,
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

        if (! $this->dialogAutomationGate->acceptsMessage($storedMessage)) {
            return false;
        }

        if (
            $storedMessage->message_kind === Message::KIND_INBOUND_USER
            && $this->startPriorityScenario($channel, $storedMessage)
        ) {
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

    public function startPriorityScenario(Channel $channel, Message $storedMessage): bool
    {
        if ($storedMessage->message_kind !== Message::KIND_INBOUND_USER || $storedMessage->dialog_id === null) {
            return false;
        }

        if ($this->isStoredTelegramScenarioCallback($channel, $storedMessage)) {
            return false;
        }

        $storedMessage->loadMissing(['contact', 'channel', 'contactIdentity', 'dialog.dialogStage']);

        if (! $this->dialogAutomationGate->acceptsMessage($storedMessage)) {
            return false;
        }

        foreach ($this->activeBindingsForChannel($channel->id) as $binding) {
            if (! $this->scenarioRegistry->enabledForNewStarts($binding->scenario_code)) {
                continue;
            }

            $runtime = $this->scenarioRegistry->makeRuntime($binding->scenario_code);

            if (! $runtime instanceof PrioritizedScenarioRuntime) {
                continue;
            }

            if (! $runtime->shouldStartBeforeActiveRun($storedMessage)) {
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

        if (! $this->dialogAutomationGate->acceptsMessage($storedMessage)) {
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

    public function hasActiveV3Run(Message $storedMessage): bool
    {
        if ($storedMessage->dialog_id === null) {
            return false;
        }

        $activeRun = ScenarioRun::query()
            ->active()
            ->where('dialog_id', $storedMessage->dialog_id)
            ->orderBy('id')
            ->first();

        return $activeRun instanceof ScenarioRun
            && (int) data_get($activeRun->state_payload, 'v3.schema_version') === 3;
    }

    public function shouldBlockVipIbizaParameterStartBecauseBusyState(Message $storedMessage): bool
    {
        if (! $this->isVipIbizaParameterStartMessage($storedMessage)) {
            return false;
        }

        $storedMessage->loadMissing('contact');

        if ($storedMessage->contact?->isInDataCollection()) {
            return true;
        }

        return ScenarioRun::query()
            ->active()
            ->where('dialog_id', $storedMessage->dialog_id)
            ->exists();
    }

    public function startMatchingScenario(Channel $channel, Message $storedMessage): bool
    {
        if ($storedMessage->message_kind !== Message::KIND_INBOUND_USER || $storedMessage->dialog_id === null) {
            return false;
        }

        if ($this->isStoredTelegramScenarioCallback($channel, $storedMessage)) {
            return true;
        }

        $storedMessage->loadMissing(['contact', 'channel', 'contactIdentity', 'dialog.dialogStage']);

        if (! $this->dialogAutomationGate->acceptsMessage($storedMessage)) {
            return false;
        }

        foreach ($this->activeBindingsForChannel($channel->id) as $binding) {
            if (! $this->scenarioRegistry->enabledForNewStarts($binding->scenario_code)) {
                continue;
            }

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
        if (! is_array($storedMessage->raw_payload) || ! is_array(data_get($storedMessage->raw_payload, 'callback_query'))) {
            return false;
        }

        $callbackData = (string) data_get($storedMessage->raw_payload, 'callback_query.data', '');

        return str_starts_with($callbackData, 'scenario:')
            || str_starts_with($callbackData, 'v3b:');
    }

    private function isVipIbizaParameterStartMessage(Message $storedMessage): bool
    {
        $storedMessage->loadMissing('channel');

        if (
            ! in_array($storedMessage->channel?->platform, [Channel::PLATFORM_TELEGRAM, Channel::PLATFORM_MAX], true)
            || $storedMessage->message_kind !== Message::KIND_INBOUND_USER
            || $storedMessage->dialog_id === null
            || ! in_array(trim((string) $storedMessage->message_parameter), self::IBIZA_START_PARAMETERS, true)
        ) {
            return false;
        }

        return match ($storedMessage->channel?->platform) {
            Channel::PLATFORM_TELEGRAM => true,
            Channel::PLATFORM_MAX => data_get($storedMessage->raw_payload, 'update_type') === 'bot_started',
            default => false,
        };
    }
}
