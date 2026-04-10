<?php

namespace App\Services\Scenarios;

use App\Jobs\ProcessScenarioInboundJob;
use App\Jobs\ProcessScenarioStartJob;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Services\Scenarios\Adapters\BuiltinScenarioAdapter;

class DispatchStoredInboundScenarioAction
{
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

            ProcessScenarioInboundJob::dispatch($storedMessage->id, $activeRun->id)->afterCommit();

            return true;
        }

        return false;
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

        if ($runtime === null || $runtime instanceof BuiltinScenarioAdapter) {
            return false;
        }

        // Slice 0 wires DB-backed runtimes into discovery only.
        return ! $runtime instanceof GenericDbScenarioRuntime;
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
            && is_array($storedMessage->raw_payload)
            && is_array(data_get($storedMessage->raw_payload, 'callback_query'))
            && str_starts_with((string) data_get($storedMessage->raw_payload, 'callback_query.data', ''), 'scenario:');
    }
}
