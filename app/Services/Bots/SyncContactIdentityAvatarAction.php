<?php

namespace App\Services\Bots;

use App\Data\Bots\DownloadedAvatarData;
use App\Models\Channel;
use App\Models\ContactIdentity;
use Illuminate\Support\Facades\Http;
use Throwable;

class SyncContactIdentityAvatarAction
{
    public function __construct(
        protected TelegramBotApiService $telegramBotApiService,
        protected ChannelActivityLogger $channelActivityLogger,
        protected StoreContactIdentityAvatarAction $storeContactIdentityAvatarAction,
    ) {}

    public function handle(int $contactIdentityId, ?string $maxAvatarUrl = null): void
    {
        $identity = ContactIdentity::query()
            ->with('channel')
            ->find($contactIdentityId);

        if (! $identity instanceof ContactIdentity) {
            return;
        }

        $channel = $identity->channel;

        if (! $channel instanceof Channel) {
            return;
        }

        try {
            $avatar = match ($channel->platform) {
                Channel::PLATFORM_TELEGRAM => $this->downloadTelegramAvatar($channel, $identity),
                Channel::PLATFORM_MAX => $this->downloadMaxAvatar($maxAvatarUrl),
                default => null,
            };
        } catch (Throwable $throwable) {
            $this->channelActivityLogger->warning(
                $channel,
                'contact.avatar_sync_failed',
                'Не удалось обновить аватарку contact identity.',
                [
                    'contact_identity_id' => $identity->id,
                    'platform' => $channel->platform,
                    'external_user_id' => $identity->external_user_id,
                    'error' => $throwable->getMessage(),
                ],
            );

            return;
        }

        if (! $avatar instanceof DownloadedAvatarData) {
            if ($channel->platform === Channel::PLATFORM_TELEGRAM) {
                $this->storeContactIdentityAvatarAction->clear($identity);
            }

            return;
        }

        $this->storeContactIdentityAvatarAction->handle($identity, $avatar);
    }

    protected function downloadTelegramAvatar(Channel $channel, ContactIdentity $identity): ?DownloadedAvatarData
    {
        if (! filled($identity->external_user_id)) {
            return null;
        }

        return $this->telegramBotApiService->downloadChatAvatar($channel, (string) $identity->external_user_id);
    }

    protected function downloadMaxAvatar(?string $avatarUrl): ?DownloadedAvatarData
    {
        if (! filled($avatarUrl)) {
            return null;
        }

        $response = Http::timeout(15)
            ->get($avatarUrl)
            ->throw();

        if ($response->body() === '') {
            return null;
        }

        return new DownloadedAvatarData(
            contents: $response->body(),
            contentType: $response->header('Content-Type'),
            filenameHint: $avatarUrl,
        );
    }
}
