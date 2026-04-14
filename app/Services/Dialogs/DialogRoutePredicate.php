<?php

namespace App\Services\Dialogs;

use App\Models\Channel;
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

    public function isReady(Dialog $dialog): bool
    {
        $dialog->loadMissing(['channel', 'currentContactIdentity']);

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            return false;
        }

        return $this->isActiveChannel($channel)
            && $this->isBotChannel($channel)
            && $this->hasConfiguredToken($channel)
            && $this->supportsPlatform($channel)
            && ! $this->isBlockedByUser($dialog)
            && $this->hasSupportedRouteSource($dialog);
    }
}
