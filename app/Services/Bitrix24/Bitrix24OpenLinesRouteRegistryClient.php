<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Profile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Bitrix24OpenLinesRouteRegistryClient
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(Bitrix24Profile $profile): array
    {
        return $this->request($profile, 'GET', 'action=snapshot', null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function publish(Bitrix24Profile $profile, array $payload): array
    {
        return $this->request($profile, 'POST', '', $payload);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function request(Bitrix24Profile $profile, string $method, string $query, ?array $payload): array
    {
        $secret = trim((string) $profile->openlines_route_registry_secret_encrypted);

        if ($secret === '') {
            throw new Bitrix24OpenLinesRouteRegistryException(
                'route_registry_secret_missing',
                'Registry secret для профиля Bitrix24 не настроен.',
            );
        }

        $endpoint = $profile->openLinesRouteRegistryEndpointUrl();
        $path = parse_url($endpoint, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            throw new Bitrix24OpenLinesRouteRegistryException(
                'route_registry_endpoint_invalid',
                'Endpoint OpenLines registry построен некорректно.',
            );
        }

        $method = strtoupper($method);
        $rawBody = $payload === null
            ? ''
            : (json_encode($this->payloadForEncoding($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $timestamp = (string) now()->timestamp;
        $requestId = (string) Str::uuid();
        $signature = $this->signature($secret, $method, $path, $query, $timestamp, $requestId, $rawBody);
        $url = $query === '' ? $endpoint : $endpoint.'?'.$query;

        $pending = Http::acceptJson()
            ->timeout(15)
            ->connectTimeout(5)
            ->withHeaders([
                'X-ABR-Timestamp' => $timestamp,
                'X-ABR-Request-Id' => $requestId,
                'X-ABR-Signature' => $signature,
            ]);

        if ($payload !== null) {
            $pending = $pending->withBody($rawBody, 'application/json');
        }

        try {
            $response = $pending->send($method, $url);
        } catch (ConnectionException) {
            throw new Bitrix24OpenLinesRouteRegistryException(
                'route_registry_transport_failed',
                'Не удалось подключиться к Bitrix OpenLines registry endpoint.',
            );
        }

        return $this->decodeResponse($response);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payloadForEncoding(array $payload): array
    {
        if (is_array($payload['connectors'] ?? null)) {
            $payload['connectors'] = (object) $payload['connectors'];
        }

        return $payload;
    }

    private function signature(
        string $secret,
        string $method,
        string $path,
        string $query,
        string $timestamp,
        string $requestId,
        string $rawBody,
    ): string {
        $canonical = implode("\n", [
            $method,
            $path,
            $query,
            $timestamp,
            $requestId,
            hash('sha256', $rawBody),
        ]);

        return 'sha256='.hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response): array
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if (! $response->successful() || ($body['ok'] ?? false) !== true) {
            $errorCode = is_scalar($body['error_code'] ?? null)
                ? (string) $body['error_code']
                : 'route_registry_http_'.$response->status();

            throw new Bitrix24OpenLinesRouteRegistryException($errorCode);
        }

        return $body;
    }
}
