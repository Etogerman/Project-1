<?php

namespace App\Services\Dialogs;

use App\Models\Channel;
use App\Models\ChannelRuntimeState;
use App\Models\Dialog;

class DialogRoutePredicate
{
    /**
     * @return list<string>
     */
    public function supportedPlatforms(): array
    {
        return [
            Channel::PLATFORM_TELEGRAM,
            Channel::PLATFORM_MAX,
        ];
    }

    public function hasChannel(Dialog $dialog): bool
    {
        return $dialog->channel instanceof Channel;
    }

    public function isActiveChannel(Channel $channel): bool
    {
        return $channel->is_active;
    }

    public function isBotChannel(Channel $channel): bool
    {
        return $channel->connection_type === Channel::CONNECTION_TYPE_BOT;
    }

    public function isTelegramAccountChannel(Channel $channel): bool
    {
        return $channel->connection_type === Channel::CONNECTION_TYPE_ACCOUNT
            && $channel->platform === Channel::PLATFORM_TELEGRAM;
    }

    public function hasConfiguredToken(Channel $channel): bool
    {
        return $channel->hasBotTokenConfigured();
    }

    public function supportsPlatform(Channel $channel): bool
    {
        return in_array($channel->platform, $this->supportedPlatforms(), true);
    }

    public function hasTelegramRouteSource(Dialog $dialog): bool
    {
        return filled($dialog->external_chat_id);
    }

    public function isBlockedByUser(Dialog $dialog): bool
    {
        return $dialog->isBotBlockedByUser();
    }

    public function hasMaxRouteSource(Dialog $dialog): bool
    {
        return filled($dialog->external_chat_id) || filled($dialog->currentContactIdentity?->external_user_id);
    }

    public function hasSupportedRouteSource(Dialog $dialog): bool
    {
        $dialog->loadMissing(['channel', 'currentContactIdentity']);

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            return false;
        }

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->hasTelegramRouteSource($dialog),
            Channel::PLATFORM_MAX => $this->hasMaxRouteSource($dialog),
            default => false,
        };
    }

    public function hasReadyTelegramAccountRuntime(Channel $channel): bool
    {
        if (! $this->isTelegramAccountChannel($channel)) {
            return false;
        }

        $channel->loadMissing('runtimeState');
        $runtimeState = $channel->runtimeState;

        if (! $runtimeState instanceof ChannelRuntimeState) {
            return false;
        }

        return $runtimeState->auth_status === ChannelRuntimeState::AUTH_STATUS_AUTHORIZED
            && $runtimeState->authorization_state === ChannelRuntimeState::AUTHORIZATION_STATE_READY
            && $runtimeState->sync_status === ChannelRuntimeState::SYNC_STATUS_LIVE
            && data_get($runtimeState->runtime_payload, 'gateway_capabilities.outgoing_replies') === true;
    }

    public function isReady(Dialog $dialog): bool
    {
        $dialog->loadMissing(['channel', 'currentContactIdentity']);

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            return false;
        }

        if (! $this->isActiveChannel($channel)) {
            return false;
        }

        if ($this->isTelegramAccountChannel($channel)) {
            return $this->hasReadyTelegramAccountRuntime($channel)
                && $this->hasTelegramRouteSource($dialog);
        }

        return $this->isBotChannel($channel)
            && $this->hasConfiguredToken($channel)
            && $this->supportsPlatform($channel)
            && ! $this->isBlockedByUser($dialog)
            && $this->hasSupportedRouteSource($dialog);
    }
}
