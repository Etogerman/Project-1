<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24AdminOAuthStartData;
use App\Models\Bitrix24Profile;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BuildBitrix24AdminOAuthAuthorizeUrlAction
{
    private const STATE_TTL_SECONDS = 600;

    public function __construct(
        private readonly BackfillBitrix24ConnectionProfilesAction $backfillProfiles,
        private readonly ResolveCurrentBitrix24ProfileAction $resolveCurrentProfile,
    ) {}

    public function handle(User $user): Bitrix24AdminOAuthStartData
    {
        if (! $user->is_active || ! $user->isSuperadmin()) {
            throw new Bitrix24AdminOAuthException('Подключать Bitrix24 может только главный админ.');
        }

        try {
            $profile = $this->resolveCurrentProfile->handle();
        } catch (Bitrix24ConnectionStateException $exception) {
            $this->backfillProfiles->handle();

            try {
                $profile = $this->resolveCurrentProfile->handle();
            } catch (Bitrix24ConnectionStateException $secondException) {
                throw new Bitrix24AdminOAuthException(
                    'Не удалось начать подключение: профиль Bitrix24 не настроен.',
                    previous: $secondException,
                );
            }
        }

        $this->assertProfileReady($profile);

        $state = Str::random(48);

        Cache::put(
            $this->cacheKey($state),
            [
                'user_id' => $user->id,
                'profile_id' => $profile->id,
            ],
            self::STATE_TTL_SECONDS,
        );

        return new Bitrix24AdminOAuthStartData(
            profile: $profile,
            authorizationUrl: $this->buildAuthorizationUrl($profile, $state),
            state: $state,
        );
    }

    public static function cacheKey(string $state): string
    {
        return 'bitrix24:admin-oauth-state:'.hash('sha256', $state);
    }

    private function assertProfileReady(Bitrix24Profile $profile): void
    {
        foreach ([
            'адрес портала' => $profile->portal_domain,
            'client_id' => $profile->client_id,
            'код приложения' => $profile->application_code,
            'публичный callback-адрес' => $profile->callback_base_url,
        ] as $label => $value) {
            if (filled($value)) {
                continue;
            }

            throw new Bitrix24AdminOAuthException('Не удалось начать подключение: не заполнен '.$label.'.');
        }

        if (! filled(config('bitrix24.application.client_secret'))) {
            throw new Bitrix24AdminOAuthException('Не удалось начать подключение: не настроен секрет приложения Bitrix24.');
        }

        if (! $this->isHttpsUrl(config('bitrix24.oauth.server_url'))) {
            throw new Bitrix24AdminOAuthException('Не удалось начать подключение: не настроен доверенный OAuth-сервер Bitrix24.');
        }
    }

    private function buildAuthorizationUrl(Bitrix24Profile $profile, string $state): string
    {
        return 'https://'.$this->normalizePortalDomain($profile->portal_domain).'/oauth/authorize/?'.http_build_query([
            'client_id' => (string) $profile->client_id,
            'redirect_uri' => $profile->adminOAuthCallbackUrl(),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function normalizePortalDomain(string $value): string
    {
        $host = parse_url($value, PHP_URL_HOST);

        if (is_string($host) && trim($host) !== '') {
            return mb_strtolower(trim($host));
        }

        return mb_strtolower(trim($value, "/ \t\n\r\0\x0B"));
    }

    private function isHttpsUrl(mixed $endpoint): bool
    {
        if (! is_scalar($endpoint) || trim((string) $endpoint) === '') {
            return false;
        }

        $scheme = parse_url((string) $endpoint, PHP_URL_SCHEME);
        $host = parse_url((string) $endpoint, PHP_URL_HOST);

        return is_string($scheme)
            && is_string($host)
            && mb_strtolower($scheme) === 'https'
            && trim($host) !== '';
    }
}
