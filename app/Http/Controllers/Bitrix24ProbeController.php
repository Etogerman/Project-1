<?php

namespace App\Http\Controllers;

use App\Services\Bitrix24\HashBitrix24ApplicationTokenAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class Bitrix24ProbeController extends Controller
{
    public function __construct(
        private readonly HashBitrix24ApplicationTokenAction $hashApplicationToken,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        $sanitizedPayload = $this->sanitizePayload($payload);
        $this->storeLatestBitrixAuth($payload);

        Log::channel('bitrix24_probe')->info('bitrix24 probe callback received', [
            'method' => $request->getMethod(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
            'query' => $request->query(),
            'payload' => $sanitizedPayload,
            'bitrix_event' => $payload['event'] ?? $request->query('event'),
            'bitrix_auth' => $this->extractBitrixAuth($payload),
        ]);

        return response()->json([
            'ok' => true,
            'received' => true,
            'method' => $request->getMethod(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function extractBitrixAuth(array $payload): array
    {
        $auth = $payload['auth'] ?? [];

        if (! is_array($auth)) {
            return [];
        }

        $sanitized = collect($auth)
            ->only([
                'domain',
                'application_token',
                'member_id',
                'client_endpoint',
                'server_endpoint',
                'status',
            ])
            ->all();

        $applicationTokenHash = $this->hashApplicationToken->handle($sanitized['application_token'] ?? null);

        unset($sanitized['application_token']);

        if ($applicationTokenHash !== null) {
            $sanitized['application_token_hash'] = $applicationTokenHash;
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function sanitizePayload(array $payload): array
    {
        if (! array_key_exists('auth', $payload)) {
            return $payload;
        }

        $payload['auth'] = $this->extractBitrixAuth($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function storeLatestBitrixAuth(array $payload): void
    {
        $auth = $this->extractBitrixAuth($payload);

        if ($auth === []) {
            return;
        }

        Storage::disk('local')->put('bitrix24-probe/latest-auth.json', json_encode([
            'event' => $payload['event'] ?? null,
            'captured_at' => now()->toIso8601String(),
            'auth' => $auth,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    protected function sanitizeHeaders(array $headers): array
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
