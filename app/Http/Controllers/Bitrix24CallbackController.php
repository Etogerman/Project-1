<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class Bitrix24CallbackController extends Controller
{
    public function install(Request $request): JsonResponse
    {
        return $this->handle($request, 'install');
    }

    public function events(Request $request): JsonResponse
    {
        return $this->handle($request, 'events');
    }

    public function openlines(Request $request): JsonResponse
    {
        return $this->handle($request, 'openlines');
    }

    private function handle(Request $request, string $callbackType): JsonResponse
    {
        $payload = $request->all();

        Log::channel('bitrix24_callbacks')->info('bitrix24 production callback received', [
            'callback_type' => $callbackType,
            'method' => $request->getMethod(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
            'query' => $request->query(),
            'payload' => $this->sanitizePayload($payload),
            'bitrix_event' => $payload['event'] ?? $request->query('event'),
            'bitrix_auth' => $this->extractBitrixAuth($payload),
        ]);

        return response()->json([
            'ok' => true,
            'received' => true,
            'callback_type' => $callbackType,
            'method' => $request->getMethod(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        if (! array_key_exists('auth', $payload)) {
            return $payload;
        }

        $payload['auth'] = $this->extractBitrixAuth($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractBitrixAuth(array $payload): array
    {
        $auth = $payload['auth'] ?? [];

        if (! is_array($auth)) {
            return [];
        }

        return collect($auth)
            ->only([
                'domain',
                'application_token',
                'member_id',
                'client_endpoint',
                'server_endpoint',
                'status',
            ])
            ->all();
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    private function sanitizeHeaders(array $headers): array
    {
        return Collection::make($headers)
            ->reject(fn (array $values, string $name): bool => in_array(strtolower($name), [
                'authorization',
                'cookie',
                'x-csrf-token',
            ], true))
            ->map(fn (array $values): string => implode(', ', $values))
            ->all();
    }
}
