<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class HandleBitrix24AdminOAuthCallbackAction
{
    public function __construct(
        private readonly BackfillBitrix24ConnectionProfilesAction $backfillProfiles,
        private readonly ResolveCurrentBitrix24ProfileAction $resolveCurrentProfile,
        private readonly SanitizeBitrix24LogPayloadAction $sanitizePayload,
    ) {}

    public function handle(Request $request, User $user): Bitrix24Connection
    {
        if (! $user->is_active || ! $user->isSuperadmin()) {
            throw new Bitrix24AdminOAuthException('Подключать Bitrix24 может только главный админ.');
        }

        $state = $this->nullableString($request->query('state'));
        $code = $this->nullableString($request->query('code'));

        if ($state === null) {
            throw new Bitrix24AdminOAuthException('Подключение устарело, начните заново.');
        }

        /** @var array{user_id?: int, profile_id?: int}|null $statePayload */
        $statePayload = Cache::pull(BuildBitrix24AdminOAuthAuthorizeUrlAction::cacheKey($state));

        if (! is_array($statePayload)
            || (int) ($statePayload['user_id'] ?? 0) !== $user->id
            || (int) ($statePayload['profile_id'] ?? 0) <= 0
        ) {
            throw new Bitrix24AdminOAuthException('Подключение устарело, начните заново.');
        }

        if ($code === null) {
            throw new Bitrix24AdminOAuthException('Bitrix24 не вернул код авторизации.');
        }

        try {
            $profile = $this->resolveCurrentProfile->handle();
        } catch (Bitrix24ConnectionStateException $exception) {
            $this->backfillProfiles->handle();

            try {
                $profile = $this->resolveCurrentProfile->handle();
            } catch (Bitrix24ConnectionStateException $secondException) {
                throw new Bitrix24AdminOAuthException(
                    'Не удалось сохранить подключение: профиль Bitrix24 не настроен.',
                    previous: $secondException,
                );
            }
        }

        if ($profile->id !== (int) $statePayload['profile_id']) {
            throw new Bitrix24AdminOAuthException('Подключение устарело, начните заново.');
        }

        $this->assertProfileReady($profile);
        $this->assertTrustedPortal($profile, $request);

        $tokenPayload = $this->exchangeCodeForTokens($profile, $code);
        $this->assertTrustedPortalFromTokenPayload($profile, $tokenPayload);

        $memberId = $this->nullableString($tokenPayload['member_id'] ?? $request->query('member_id'));

        if ($memberId === null) {
            throw new Bitrix24AdminOAuthException('Bitrix24 не вернул идентификатор портала.');
        }

        $accessToken = $this->nullableString($tokenPayload['access_token'] ?? null);
        $refreshToken = $this->nullableString($tokenPayload['refresh_token'] ?? null);

        if ($accessToken === null || $refreshToken === null) {
            throw new Bitrix24AdminOAuthException('Bitrix24 не вернул ключи доступа.');
        }

        $this->assertApplicationIdentity($profile, $accessToken);

        $connection = Bitrix24Connection::query()->firstOrNew([
            'profile_id' => $profile->id,
        ]);

        $connection->fill([
            'profile_id' => $profile->id,
            'portal_domain' => $this->normalizePortalDomain($profile->portal_domain),
            'application_name' => (string) config('bitrix24.application.name'),
            'client_id' => $profile->client_id,
            'member_id' => $memberId,
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_expires_at' => $this->resolveExpiresAt($tokenPayload),
            'scope' => $this->resolveScope($tokenPayload['scope'] ?? $request->query('scope')),
            'client_endpoint' => $this->resolveClientEndpoint($profile, $tokenPayload),
            'server_endpoint' => $this->trustedOAuthServerUrl(),
            'install_payload' => $this->sanitizePayload->handle($tokenPayload),
            'installed_at' => now(),
            'last_refreshed_at' => now(),
            'last_error_at' => null,
            'last_error_message' => null,
        ]);

        $connection->forceFill([
            'access_token_encrypted' => $accessToken,
            'refresh_token_encrypted' => $refreshToken,
        ]);

        $connection->save();

        return $connection->refresh();
    }

    private function assertProfileReady(Bitrix24Profile $profile): void
    {
        foreach ([
            'адрес портала' => $profile->portal_domain,
            'client_id' => $profile->client_id,
            'код приложения' => $profile->application_code,
        ] as $label => $value) {
            if (filled($value)) {
                continue;
            }

            throw new Bitrix24AdminOAuthException('Не удалось сохранить подключение: не заполнен '.$label.'.');
        }

        if (! filled(config('bitrix24.application.client_secret'))) {
            throw new Bitrix24AdminOAuthException('Не удалось сохранить подключение: не настроен секрет приложения Bitrix24.');
        }

        if (! $this->isHttpsUrl(config('bitrix24.oauth.server_url'))) {
            throw new Bitrix24AdminOAuthException('Не удалось сохранить подключение: не настроен доверенный OAuth-сервер Bitrix24.');
        }
    }

    private function assertTrustedPortal(Bitrix24Profile $profile, Request $request): void
    {
        $trustedPortal = $this->normalizePortalDomain($profile->portal_domain);
        $requestPortal = $this->normalizePortalDomain(
            $this->nullableString($request->query('domain')) ?? $trustedPortal,
        );

        if ($requestPortal !== $trustedPortal) {
            throw new Bitrix24AdminOAuthException(sprintf(
                'Bitrix24 вернул портал `%s`, а в настройках указан `%s`.',
                $requestPortal,
                $trustedPortal,
            ));
        }

        $serverDomain = $this->nullableString($request->query('server_domain'));

        if ($serverDomain === null) {
            return;
        }

        $trustedServerHost = parse_url((string) config('bitrix24.oauth.server_url'), PHP_URL_HOST);
        $requestServerHost = $this->normalizePortalDomain($serverDomain);

        if (! is_string($trustedServerHost) || mb_strtolower($trustedServerHost) !== $requestServerHost) {
            throw new Bitrix24AdminOAuthException(sprintf(
                'Bitrix24 вернул OAuth-сервер `%s`, а в настройках указан `%s`.',
                $requestServerHost,
                is_string($trustedServerHost) ? mb_strtolower($trustedServerHost) : '—',
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCodeForTokens(Bitrix24Profile $profile, string $code): array
    {
        $requestPayload = [
            'grant_type' => 'authorization_code',
            'client_id' => $profile->client_id,
            'client_secret' => (string) config('bitrix24.application.client_secret'),
            'code' => $code,
        ];

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('bitrix24.http.timeout_seconds', 15))
                ->connectTimeout((int) config('bitrix24.http.connect_timeout_seconds', 5))
                ->post($this->trustedTokenUrl(), $requestPayload);
        } catch (Throwable $exception) {
            throw new Bitrix24AdminOAuthException('Не удалось получить ключи доступа от Bitrix24.', previous: $exception);
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        if ($response->successful()
            && $this->nullableString($payload['access_token'] ?? null) !== null
            && $this->nullableString($payload['refresh_token'] ?? null) !== null
        ) {
            return $payload;
        }

        $message = $this->nullableString($payload['error_description'] ?? $payload['error_message'] ?? null)
            ?? 'Bitrix24 не вернул ключи доступа.';

        throw new Bitrix24AdminOAuthException($message);
    }

    /**
     * @param  array<string, mixed>  $tokenPayload
     */
    private function assertTrustedPortalFromTokenPayload(Bitrix24Profile $profile, array $tokenPayload): void
    {
        $trustedPortal = $this->normalizePortalDomain($profile->portal_domain);
        $domain = $this->nullableString($tokenPayload['domain'] ?? null);
        $normalizedDomain = $domain === null ? null : $this->normalizePortalDomain($domain);

        if ($normalizedDomain !== null
            && $normalizedDomain !== $trustedPortal
            && ! $this->isTrustedOAuthServerHost($normalizedDomain)
        ) {
            throw new Bitrix24AdminOAuthException(sprintf(
                'Bitrix24 выдал ключи для портала `%s`, а в настройках указан `%s`.',
                $normalizedDomain,
                $trustedPortal,
            ));
        }

        $clientEndpoint = $this->nullableString($tokenPayload['client_endpoint'] ?? null);

        if ($clientEndpoint !== null
            && ! $this->matchesTrustedPortal($clientEndpoint, $trustedPortal)
            && ! $this->matchesTrustedOAuthServer($clientEndpoint)
        ) {
            throw new Bitrix24AdminOAuthException(sprintf(
                'Bitrix24 выдал REST-адрес `%s`, который не относится к порталу `%s`.',
                $clientEndpoint,
                $trustedPortal,
            ));
        }
    }

    private function assertApplicationIdentity(Bitrix24Profile $profile, string $accessToken): void
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('bitrix24.http.timeout_seconds', 15))
                ->connectTimeout((int) config('bitrix24.http.connect_timeout_seconds', 5))
                ->post('https://'.$this->normalizePortalDomain($profile->portal_domain).'/rest/app.info.json', [
                    'auth' => $accessToken,
                ]);
        } catch (Throwable $exception) {
            throw new Bitrix24AdminOAuthException('Bitrix24 не подтвердил приложение.', previous: $exception);
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];
        $result = $payload['result'] ?? null;
        $error = $this->nullableString($payload['error'] ?? null);

        if (! $response->successful() || $error !== null || ! is_array($result)) {
            throw new Bitrix24AdminOAuthException('Bitrix24 не подтвердил приложение.');
        }

        $returnedCode = $this->nullableString($result['CODE'] ?? null);

        if ($returnedCode === null || $returnedCode !== $profile->application_code) {
            throw new Bitrix24AdminOAuthException('Bitrix24 не подтвердил приложение.');
        }
    }

    private function resolveClientEndpoint(Bitrix24Profile $profile, array $tokenPayload): string
    {
        $endpoint = $this->nullableString($tokenPayload['client_endpoint'] ?? null);
        $trustedPortal = $this->normalizePortalDomain($profile->portal_domain);

        if ($endpoint !== null && $this->matchesTrustedPortal($endpoint, $trustedPortal)) {
            return rtrim($endpoint, '/').'/';
        }

        return 'https://'.$trustedPortal.'/rest/';
    }

    /**
     * @return list<string>
     */
    private function resolveScope(mixed $scope): array
    {
        if (is_string($scope)) {
            return array_values(array_filter(
                preg_split('/[,\s]+/', trim($scope)) ?: [],
                fn (string $value): bool => $value !== '',
            ));
        }

        if (! is_array($scope)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
                $scope,
            ),
            fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveExpiresAt(array $payload): ?CarbonImmutable
    {
        $expiresIn = $payload['expires_in'] ?? null;

        if (is_numeric($expiresIn)) {
            return CarbonImmutable::now()->addSeconds((int) $expiresIn);
        }

        $expires = $payload['expires'] ?? null;

        if (is_numeric($expires)) {
            return CarbonImmutable::createFromTimestamp((int) $expires);
        }

        return null;
    }

    private function matchesTrustedPortal(?string $endpoint, string $trustedPortal): bool
    {
        if (! filled($endpoint)) {
            return false;
        }

        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        $host = parse_url($endpoint, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host)) {
            return false;
        }

        return mb_strtolower($scheme) === 'https'
            && mb_strtolower($host) === $trustedPortal;
    }

    private function matchesTrustedOAuthServer(?string $endpoint): bool
    {
        if (! filled($endpoint)) {
            return false;
        }

        $endpointScheme = parse_url($endpoint, PHP_URL_SCHEME);
        $endpointHost = parse_url($endpoint, PHP_URL_HOST);
        $trustedScheme = parse_url($this->trustedOAuthServerUrl(), PHP_URL_SCHEME);
        $trustedHost = parse_url($this->trustedOAuthServerUrl(), PHP_URL_HOST);

        if (! is_string($endpointScheme)
            || ! is_string($endpointHost)
            || ! is_string($trustedScheme)
            || ! is_string($trustedHost)
        ) {
            return false;
        }

        return mb_strtolower($endpointScheme) === mb_strtolower($trustedScheme)
            && mb_strtolower($endpointHost) === mb_strtolower($trustedHost);
    }

    private function isTrustedOAuthServerHost(string $host): bool
    {
        $trustedHost = parse_url($this->trustedOAuthServerUrl(), PHP_URL_HOST);

        return is_string($trustedHost)
            && mb_strtolower($host) === mb_strtolower($trustedHost);
    }

    private function trustedTokenUrl(): string
    {
        return rtrim($this->trustedOAuthServerUrl(), '/').'/oauth/token/';
    }

    private function trustedOAuthServerUrl(): string
    {
        return rtrim((string) config('bitrix24.oauth.server_url'), '/');
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

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
