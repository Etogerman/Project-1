<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessage;
use App\Jobs\SyncContactIdentityAvatarJob;
use App\Models\Channel;
use App\Models\ContactIdentity;

class QueueContactIdentityAvatarSyncAction
{
    public function handle(Channel $channel, ContactIdentity $identity, IncomingBotMessage $message): void
    {
        if (! $this->shouldQueue($channel, $identity, $message)) {
            return;
        }

        SyncContactIdentityAvatarJob::dispatch(
            $identity->id,
            $channel->platform === Channel::PLATFORM_MAX ? $message->avatarUrl : null,
            $channel->platform === Channel::PLATFORM_MAX ? $message->externalChatId : null,
        )->afterCommit();
    }

    protected function shouldQueue(Channel $channel, ContactIdentity $identity, IncomingBotMessage $message): bool
    {
        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => filled($identity->external_user_id),
            Channel::PLATFORM_MAX => filled($message->avatarUrl) || filled($message->externalChatId),
            default => false,
        };
    }
}
