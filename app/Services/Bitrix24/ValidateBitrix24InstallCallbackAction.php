<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24InstallPayloadData;
use App\Models\Bitrix24WebhookEvent;
use Illuminate\Support\Facades\Http;
use Throwable;

class ValidateBitrix24InstallCallbackAction
{
    /**
     * @return array{0: string, 1: ?string}
     */
    public function handle(bool $looksLikeBitrix, Bitrix24InstallPayloadData $payload): array
    {
        if (! $looksLikeBitrix) {
            return [Bitrix24WebhookEvent::STATUS_IGNORED, 'Payload does not look like a Bitrix24 install callback.'];
        }

        foreach ([
            'portalDomain' => $payload->portalDomain,
            'memberId' => $payload->memberId,
            'applicationToken' => $payload->applicationToken,
            'clientEndpoint' => $payload->clientEndpoint,
            'serverEndpoint' => $payload->serverEndpoint,
            'accessToken' => $payload->accessToken,
            'refreshToken' => $payload->refreshToken,
        ] as $field => $value) {
            if (! filled($value)) {
                return [Bitrix24WebhookEvent::STATUS_FAILED, sprintf('Missing required install field `%s`.', $field)];
            }
        }

        $trustedPortal = $this->normalizePortalDomain((string) config('bitrix24.portal_domain'));

        if ($trustedPortal === null) {
            return [Bitrix24WebhookEvent::STATUS_FAILED, 'Bitrix24 install validation is missing trusted portal configuration.'];
        }

        $payloadPortal = $this->normalizePortalDomain($payload->portalDomain);

        if ($payloadPortal === null || $payloadPortal !== $trustedPortal) {
            return [Bitrix24WebhookEvent::STATUS_FAILED, 'Install callback portal domain did not match trusted portal.'];
        }

        $expectedAppCode = $this->nullableString(config('bitrix24.application.code'));

        if ($expectedAppCode === null) {
            return [Bitrix24WebhookEvent::STATUS_FAILED, 'Bitrix24 install validation is missing expected app code configuration.'];
        }

        foreach ([
            'clientEndpoint' => $payload->clientEndpoint,
            'serverEndpoint' => $payload->serverEndpoint,
        ] as $field => $endpoint) {
            if (! $this->matchesTrustedPortal($endpoint, $trustedPortal)) {
                return [Bitrix24WebhookEvent::STATUS_FAILED, sprintf('Install callback `%s` did not match trusted portal.', $field)];
            }
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('bitrix24.http.timeout_seconds', 15))
                ->connectTimeout((int) config('bitrix24.http.connect_timeout_seconds', 5))
                ->post($this->buildAppInfoUrl($trustedPortal), [
                    'auth' => $payload->accessToken,
                ]);
        } catch (Throwable $exception) {
            return [Bitrix24WebhookEvent::STATUS_FAILED, 'Bitrix24 install validation probe failed: '.$exception->getMessage()];
        }

        /** @var array<string, mixed> $responseData */
        $responseData = $response->json() ?? [];
        $errorCode = $this->nullableString($responseData['error'] ?? null);
        $errorMessage = $this->nullableString($responseData['error_description'] ?? $responseData['error_message'] ?? null);

        if (! $response->successful() || $errorCode !== null) {
            $reason = $errorCode ?? 'http_'.$response->status();

            if ($errorMessage !== null) {
                $reason .= ': '.$errorMessage;
            }

            return [Bitrix24WebhookEvent::STATUS_FAILED, 'Bitrix24 install probe rejected application context ('.$reason.').'];
        }

        $result = $responseData['result'] ?? null;

        if (! is_array($result)) {
            return [Bitrix24WebhookEvent::STATUS_FAILED, 'Bitrix24 install probe did not return a valid result payload.'];
        }

        $returnedAppCode = $this->nullableString($result['CODE'] ?? null);

        if ($returnedAppCode === null) {
            return [Bitrix24WebhookEvent::STATUS_FAILED, 'Bitrix24 install probe did not return application code.'];
        }

        if ($returnedAppCode !== $expectedAppCode) {
            return [Bitrix24WebhookEvent::STATUS_FAILED, 'Bitrix24 install probe returned unexpected application code.'];
        }

        if (! $this->isInstalled($result['INSTALLED'] ?? null)) {
            return [Bitrix24WebhookEvent::STATUS_FAILED, 'Bitrix24 install probe reported application as not installed.'];
        }

        return [Bitrix24WebhookEvent::STATUS_PENDING, null];
    }

    private function buildAppInfoUrl(string $trustedPortal): string
    {
        return 'https://'.$trustedPortal.'/rest/app.info.json';
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

    private function normalizePortalDomain(?string $value): ?string
    {
        $trimmed = $this->nullableString($value);

        if ($trimmed === null) {
            return null;
        }

        $host = parse_url($trimmed, PHP_URL_HOST);

        if (is_string($host) && trim($host) !== '') {
            return mb_strtolower(trim($host));
        }

        return mb_strtolower(trim($trimmed, "/ \t\n\r\0\x0B"));
    }

    private function isInstalled(mixed $value): bool
    {
        return match (true) {
            $value === true => true,
            is_int($value) => $value === 1,
            is_string($value) => in_array(mb_strtolower(trim($value)), ['1', 'true', 'y', 'yes'], true),
            default => false,
        };
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
