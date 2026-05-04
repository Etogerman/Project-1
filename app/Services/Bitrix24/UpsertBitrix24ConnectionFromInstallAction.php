<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24InstallPayloadData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;
use Carbon\CarbonImmutable;

class UpsertBitrix24ConnectionFromInstallAction
{
    public function __construct(
        private readonly HashBitrix24ApplicationTokenAction $hashApplicationToken,
        private readonly SanitizeBitrix24ApplicationTokenPayloadAction $sanitizePayload,
    ) {}

    /**
     * @param  array<string, mixed>  $applicationInfo
     */
    public function handle(Bitrix24Profile $profile, Bitrix24InstallPayloadData $payload, array $applicationInfo = []): Bitrix24Connection
    {
        $connection = Bitrix24Connection::query()->firstOrNew([
            'profile_id' => $profile->id,
        ]);

        $connection->fill([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'application_name' => $this->resolveApplicationDisplayName($applicationInfo, $payload->rawPayload)
                ?? (string) config('bitrix24.application.name'),
            'client_id' => $profile->client_id,
            'member_id' => $payload->memberId,
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_expires_at' => $this->resolveExpiresAt($payload->expiresAt),
            'scope' => $payload->scope,
            'client_endpoint' => $payload->clientEndpoint,
            'server_endpoint' => $this->resolveTrustedOauthServerUrl(),
            'install_payload' => $this->sanitizePayload->handle($payload->rawPayload),
            'installed_at' => now(),
            'last_install_callback_at' => now(),
            'last_error_at' => null,
            'last_error_message' => null,
        ]);

        $connection->forceFill([
            'application_token_hash' => $this->hashApplicationToken->handle($payload->applicationToken),
            'access_token_encrypted' => $payload->accessToken,
            'refresh_token_encrypted' => $payload->refreshToken,
        ]);

        $connection->save();

        return $connection;
    }

    private function resolveExpiresAt(?string $expiresAt): ?CarbonImmutable
    {
        if (! filled($expiresAt)) {
            return null;
        }

        if (ctype_digit($expiresAt)) {
            return CarbonImmutable::createFromTimestampUTC((int) $expiresAt);
        }

        try {
            return CarbonImmutable::parse($expiresAt);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveTrustedOauthServerUrl(): ?string
    {
        $value = config('bitrix24.oauth.server_url');

        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $applicationInfo
     * @param  array<string, mixed>  $payload
     */
    private function resolveApplicationDisplayName(array $applicationInfo, array $payload): ?string
    {
        foreach ([
            $applicationInfo['NAME'] ?? null,
            $applicationInfo['APP_NAME'] ?? null,
            $applicationInfo['TITLE'] ?? null,
            $applicationInfo['LANG']['ru']['NAME'] ?? null,
            $applicationInfo['LANG']['en']['NAME'] ?? null,
            $applicationInfo['LANG']['NAME'] ?? null,
            $applicationInfo['LANGUAGE']['ru']['NAME'] ?? null,
            $applicationInfo['LANGUAGE']['en']['NAME'] ?? null,
            $applicationInfo['LANGUAGE']['NAME'] ?? null,
            $payload['application_name'] ?? null,
            $payload['APPLICATION_NAME'] ?? null,
            $payload['app_name'] ?? null,
            $payload['APP_NAME'] ?? null,
            $payload['name'] ?? null,
            $payload['NAME'] ?? null,
            $payload['title'] ?? null,
            $payload['TITLE'] ?? null,
        ] as $candidate) {
            $name = $this->nullableString($candidate);

            if ($name !== null) {
                return $name;
            }
        }

        return null;
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
