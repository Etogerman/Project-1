<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24InstallPayloadData;
use App\Models\Bitrix24Connection;
use Carbon\CarbonImmutable;

class UpsertBitrix24ConnectionFromInstallAction
{
    public function handle(Bitrix24InstallPayloadData $payload): Bitrix24Connection
    {
        $connection = Bitrix24Connection::query()->firstOrNew([
            'portal_domain' => $payload->portalDomain ?? config('bitrix24.portal_domain'),
        ]);

        $connection->fill([
            'application_name' => (string) config('bitrix24.application.name'),
            'client_id' => (string) config('bitrix24.application.client_id'),
            'member_id' => $payload->memberId,
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_expires_at' => $this->resolveExpiresAt($payload->expiresAt),
            'scope' => $payload->scope,
            'client_endpoint' => $payload->clientEndpoint,
            'server_endpoint' => $this->resolveTrustedOauthServerUrl(),
            'install_payload' => $payload->rawPayload,
            'installed_at' => now(),
            'last_install_callback_at' => now(),
            'last_error_at' => null,
            'last_error_message' => null,
        ]);

        $connection->forceFill([
            'application_token' => $payload->applicationToken,
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
}
