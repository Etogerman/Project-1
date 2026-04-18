<?php

namespace App\Services\Bots;

use App\Data\Bots\DownloadedAvatarData;
use App\Data\Bots\MaxChatAvatarData;
use App\Data\Bots\TelegramChatAvatarFetchResult;
use App\Models\Channel;
use App\Models\ContactIdentity;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class SyncContactIdentityAvatarAction
{
    public function __construct(
        protected MaxBotApiService $maxBotApiService,
        protected TelegramBotApiService $telegramBotApiService,
        protected ChannelActivityLogger $channelActivityLogger,
        protected StoreContactIdentityAvatarAction $storeContactIdentityAvatarAction,
    ) {}

    public function handle(
        int $contactIdentityId,
        ?string $maxAvatarUrl = null,
        ?string $maxExternalChatId = null,
    ): void {
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

        $shouldClearTelegramAvatar = false;

        try {
            $avatar = match ($channel->platform) {
                Channel::PLATFORM_TELEGRAM => $this->resolveTelegramAvatar(
                    $channel,
                    $identity,
                    $shouldClearTelegramAvatar,
                ),
                Channel::PLATFORM_MAX => $this->resolveMaxAvatar(
                    $channel,
                    $maxAvatarUrl,
                    $maxExternalChatId,
                ),
                default => null,
            };

            if (! $avatar instanceof DownloadedAvatarData) {
                if ($shouldClearTelegramAvatar) {
                    $this->storeContactIdentityAvatarAction->clear($identity);
                }

                return;
            }

            $this->storeContactIdentityAvatarAction->handle($identity, $avatar);
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
    }

    protected function downloadTelegramAvatar(Channel $channel, ContactIdentity $identity): ?TelegramChatAvatarFetchResult
    {
        if (! filled($identity->external_user_id)) {
            return null;
        }

        return $this->telegramBotApiService->downloadChatAvatar($channel, (string) $identity->external_user_id);
    }

    protected function resolveTelegramAvatar(
        Channel $channel,
        ContactIdentity $identity,
        bool &$shouldClearTelegramAvatar,
    ): ?DownloadedAvatarData {
        $result = $this->downloadTelegramAvatar($channel, $identity);

        if (! $result instanceof TelegramChatAvatarFetchResult) {
            return null;
        }

        $shouldClearTelegramAvatar = $result->photoMissing;

        return $result->avatar;
    }

    protected function resolveMaxAvatar(
        Channel $channel,
        ?string $avatarUrl,
        ?string $externalChatId,
    ): ?DownloadedAvatarData {
        if (filled($avatarUrl)) {
            return $this->downloadMaxAvatar($avatarUrl);
        }

        if (! filled($externalChatId)) {
            return null;
        }

        $avatarData = $this->maxBotApiService->fetchChatAvatarData($channel, $externalChatId);

        if (! $avatarData instanceof MaxChatAvatarData) {
            return null;
        }

        return $this->downloadMaxAvatar($avatarData->preferredAvatarUrl());
    }

    protected function downloadMaxAvatar(?string $avatarUrl): ?DownloadedAvatarData
    {
        if (! filled($avatarUrl)) {
            return null;
        }

        $trustedAvatarUrl = $this->validateTrustedMaxAvatarUrl($avatarUrl);

        $response = Http::withoutRedirecting()
            ->timeout(15)
            ->get($trustedAvatarUrl)
            ->throw();

        if ($response->body() === '') {
            throw new InvalidArgumentException('MAX avatar download returned an empty body.');
        }

        return new DownloadedAvatarData(
            contents: $response->body(),
            contentType: $response->header('Content-Type'),
            filenameHint: null,
        );
    }

    protected function validateTrustedMaxAvatarUrl(string $avatarUrl): string
    {
        $parts = parse_url($avatarUrl);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('MAX avatar URL is malformed.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            throw new InvalidArgumentException('MAX avatar URL must use HTTPS and a trusted host.');
        }

        if (isset($parts['port'], $parts['user'], $parts['pass'])) {
            throw new InvalidArgumentException('MAX avatar URL contains unsupported connection parts.');
        }

        $trustedHosts = array_values(array_filter(
            (array) config('bots.max.trusted_avatar_hosts', ['max.ru']),
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));

        foreach ($trustedHosts as $trustedHost) {
            $normalizedTrustedHost = strtolower(trim($trustedHost));

            if ($host === $normalizedTrustedHost || str_ends_with($host, '.'.$normalizedTrustedHost)) {
                return $avatarUrl;
            }
        }

        throw new InvalidArgumentException('MAX avatar URL host is not trusted.');
    }
}
