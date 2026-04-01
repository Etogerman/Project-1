<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class Bitrix24ApiClient
{
    public function __construct(
        private readonly ResolveActiveBitrix24ConnectionAction $resolveActiveConnection,
        private readonly BuildBitrix24RestUrlAction $buildRestUrl,
        private readonly ShouldRefreshBitrix24TokenAction $shouldRefreshToken,
        private readonly RefreshBitrix24AccessTokenAction $refreshAccessToken,
        private readonly LogBitrix24ApiCallAction $logApiCall,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function call(string $method, array $params = [], ?Bitrix24Connection $connection = null): Bitrix24RestResponseData
    {
        $connection ??= $this->resolveActiveConnection->handle();
        $attemptedRefresh = false;

        if ($this->shouldRefreshToken->handle($connection)) {
            $connection = $this->refreshAccessToken->handle($connection);
            $attemptedRefresh = true;
        }

        $response = $this->performRestCall($connection, $method, $params, $attemptedRefresh ? 'rest_call_after_refresh' : 'rest_call');

        if (! $attemptedRefresh && $this->shouldRefreshToken->handle($connection, $response)) {
            $connection = $this->refreshAccessToken->handle($connection);
            $attemptedRefresh = true;
            $response = $this->performRestCall($connection, $method, $params, 'rest_call_after_refresh', true);
        }

        return new Bitrix24RestResponseData(
            successful: $response->successful,
            httpStatus: $response->httpStatus,
            result: $response->result,
            errorCode: $response->errorCode,
            errorMessage: $response->errorMessage,
            raw: $response->raw,
            requestMethod: $response->requestMethod,
            restMethod: $response->restMethod,
            attemptedRefresh: $attemptedRefresh,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function performRestCall(
        Bitrix24Connection $connection,
        string $method,
        array $params,
        string $operation,
        bool $attemptedRefresh = false,
    ): Bitrix24RestResponseData {
        $url = $this->buildRestUrl->handle($connection, $method);
        $requestPayload = array_merge($params, [
            'auth' => $connection->access_token_encrypted,
        ]);

        $response = $this->sendWithRetry($url, $requestPayload);
        /** @var array<string, mixed> $responseData */
        $responseData = $response->json() ?? [];
        $dto = new Bitrix24RestResponseData(
            successful: $response->successful() && ! filled($this->extractErrorCode($responseData)),
            httpStatus: $response->status(),
            result: $responseData['result'] ?? null,
            errorCode: $this->extractErrorCode($responseData),
            errorMessage: $this->extractErrorMessage($responseData),
            raw: $responseData,
            requestMethod: 'POST',
            restMethod: $method,
            attemptedRefresh: $attemptedRefresh,
        );

        $this->logApiCall->handle(
            direction: Bitrix24SyncLog::DIRECTION_OUTBOUND,
            operation: $operation,
            status: $dto->successful ? Bitrix24SyncLog::STATUS_SUCCESS : Bitrix24SyncLog::STATUS_FAILED,
            requestPayload: [
                'url' => $url,
                'method' => $method,
                'params' => $requestPayload,
            ],
            responsePayload: $responseData,
            connection: $connection,
            httpStatus: $dto->httpStatus,
            errorCode: $dto->errorCode,
            errorMessage: $dto->errorMessage,
            entityType: 'rest_method',
            entityId: $method,
            fingerprint: hash('sha256', json_encode([$method, $params], JSON_THROW_ON_ERROR)),
        );

        return $dto;
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     */
    private function sendWithRetry(string $url, array $requestPayload): Response
    {
        $retrySleepMilliseconds = (int) config('bitrix24.http.retry_sleep_milliseconds', 200);
        $lastConnectionException = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::asForm()
                    ->acceptJson()
                    ->timeout((int) config('bitrix24.http.timeout_seconds', 15))
                    ->connectTimeout((int) config('bitrix24.http.connect_timeout_seconds', 5))
                    ->post($url, $requestPayload);
            } catch (ConnectionException $exception) {
                $lastConnectionException = $exception;

                if ($attempt === 2) {
                    throw new Bitrix24ApiException('Bitrix24 REST call failed after retry attempts.', previous: $exception);
                }

                usleep($retrySleepMilliseconds * 1000);

                continue;
            }

            if ($response->serverError() && $attempt === 1) {
                usleep($retrySleepMilliseconds * 1000);

                continue;
            }

            return $response;
        }

        throw new Bitrix24ApiException(
            'Bitrix24 REST call failed without a response.',
            previous: $lastConnectionException,
        );
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function extractErrorCode(array $responseData): ?string
    {
        $errorCode = $responseData['error'] ?? null;

        if (! is_scalar($errorCode)) {
            return null;
        }

        $trimmed = trim((string) $errorCode);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function extractErrorMessage(array $responseData): ?string
    {
        foreach (['error_description', 'error_message'] as $key) {
            $value = $responseData[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $trimmed = trim((string) $value);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }
}
