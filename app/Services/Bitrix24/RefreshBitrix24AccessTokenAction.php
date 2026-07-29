<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class RefreshBitrix24AccessTokenAction
{
    public function __construct(
        private readonly PersistBitrix24RefreshResultAction $persistRefreshResult,
        private readonly LogBitrix24ApiCallAction $logApiCall,
        private readonly Bitrix24OpenLineRouteMutationFence $mutationFence,
    ) {}

    public function handle(
        Bitrix24Connection $connection,
        ?Bitrix24OpenLineMutationAuthority $mutationAuthority = null,
    ): Bitrix24Connection {
        $clientId = $this->resolveClientId($connection);
        $clientSecret = (string) config('bitrix24.application.client_secret');
        $refreshToken = (string) $connection->refresh_token_encrypted;
        $authServerUrl = $this->resolveAuthServerUrl($connection);

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '' || $authServerUrl === null) {
            $message = 'Bitrix24 refresh flow is missing install-critical auth metadata.';

            $connection = $this->persistRefreshResult->handleFailure(
                $connection,
                Bitrix24Connection::STATUS_NEEDS_REINSTALL,
                $message,
            );
            $this->logApiCall->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'token_refresh_failed',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'auth_server_url' => $authServerUrl,
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                ],
                responsePayload: null,
                connection: $connection,
                errorMessage: $message,
                entityType: 'connection',
                entityId: (string) $connection->id,
            );

            throw new Bitrix24AuthRefreshException($message);
        }

        $requestPayload = [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ];
        $this->assertAuthorityAllowsRequest($mutationAuthority);
        $timeoutSeconds = $mutationAuthority?->deadline->requestTimeoutSeconds(
            (int) config('bitrix24.http.timeout_seconds', 15),
        ) ?? (int) config('bitrix24.http.timeout_seconds', 15);
        $connectTimeoutSeconds = $mutationAuthority?->deadline->requestTimeoutSeconds(
            (int) config('bitrix24.http.connect_timeout_seconds', 5),
        ) ?? (int) config('bitrix24.http.connect_timeout_seconds', 5);

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout($timeoutSeconds)
                ->connectTimeout($connectTimeoutSeconds)
                ->post($authServerUrl, $requestPayload);
        } catch (RequestException|\Throwable $exception) {
            $connection = $this->persistRefreshResult->handleFailure(
                $connection,
                $connection->status,
                $exception->getMessage(),
            );
            $this->logApiCall->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'token_refresh_failed',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'auth_server_url' => $authServerUrl,
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                ],
                responsePayload: null,
                connection: $connection,
                errorMessage: $exception->getMessage(),
                entityType: 'connection',
                entityId: (string) $connection->id,
            );

            throw new Bitrix24AuthRefreshException('Bitrix24 token refresh request failed.', previous: $exception);
        }

        /** @var array<string, mixed> $responseData */
        $responseData = $response->json() ?? [];
        $accessToken = $this->nullableString($responseData['access_token'] ?? null);
        $nextRefreshToken = $this->nullableString($responseData['refresh_token'] ?? null) ?? $refreshToken;

        if (! $response->successful() || ! filled($accessToken)) {
            $errorCode = $this->nullableString($responseData['error'] ?? null);
            $errorMessage = $this->nullableString($responseData['error_description'] ?? $responseData['error_message'] ?? null)
                ?? 'Bitrix24 refresh response did not contain a new access token.';
            $failureStatus = $this->shouldInvalidateRefreshFailure($errorCode)
                ? Bitrix24Connection::STATUS_INVALID
                : $connection->status;

            $connection = $this->persistRefreshResult->handleFailure(
                $connection,
                $failureStatus,
                $errorMessage,
            );
            $this->logApiCall->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'token_refresh_failed',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'auth_server_url' => $authServerUrl,
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                ],
                responsePayload: $responseData,
                connection: $connection,
                httpStatus: $response->status(),
                errorCode: $errorCode,
                errorMessage: $errorMessage,
                entityType: 'connection',
                entityId: (string) $connection->id,
            );

            throw new Bitrix24AuthRefreshException($errorMessage);
        }

        $expiresAt = $this->resolveExpiresAt($responseData);
        $connection = $this->persistRefreshResult->handleSuccess(
            $connection,
            $accessToken,
            $nextRefreshToken,
            $expiresAt,
        );
        $this->logApiCall->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'token_refresh',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'auth_server_url' => $authServerUrl,
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
            ],
            responsePayload: $responseData,
            connection: $connection,
            httpStatus: $response->status(),
            entityType: 'connection',
            entityId: (string) $connection->id,
        );

        return $connection;
    }

    private function assertAuthorityAllowsRequest(?Bitrix24OpenLineMutationAuthority $authority): void
    {
        if (! $authority instanceof Bitrix24OpenLineMutationAuthority) {
            return;
        }

        $authority->deadline->assertAvailableFor(max(
            1,
            (int) config('bitrix24.http.timeout_seconds', 15),
            (int) config('bitrix24.http.connect_timeout_seconds', 5),
        ));
        $this->mutationFence->assertCurrent($authority);
    }

    private function shouldInvalidateRefreshFailure(?string $errorCode): bool
    {
        if (in_array($errorCode, [
            'invalid_grant',
            'invalid_client',
            'unauthorized_client',
        ], true)) {
            return true;
        }

        return false;
    }

    private function resolveAuthServerUrl(Bitrix24Connection $connection): ?string
    {
        $configuredUrl = trim((string) config('bitrix24.oauth.server_url'));

        if ($configuredUrl !== '') {
            return rtrim($configuredUrl, '/').'/oauth/token/';
        }

        return null;
    }

    private function resolveClientId(Bitrix24Connection $connection): string
    {
        $connectionClientId = trim((string) $connection->client_id);

        if ($connectionClientId !== '') {
            return $connectionClientId;
        }

        $profileClientId = trim((string) ($connection->profile?->client_id ?? ''));

        if ($profileClientId !== '') {
            return $profileClientId;
        }

        return trim((string) config('bitrix24.application.client_id'));
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

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
