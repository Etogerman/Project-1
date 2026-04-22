<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;

class BackfillBitrix24ConnectionProfilesAction
{
    public function __construct(
        private readonly NormalizeBitrix24CallbackBaseUrlAction $normalizeCallbackBaseUrl,
    ) {}

    public function handle(): void
    {
        $portalDomain = $this->normalizePortalDomain(config('bitrix24.portal_domain'));
        $callbackBaseUrl = $this->resolveLegacyCallbackBaseUrl();

        if ($portalDomain === null || $callbackBaseUrl === null) {
            return;
        }

        $profile = Bitrix24Profile::query()->firstOrCreate(
            [
                'portal_domain' => $portalDomain,
                'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            ],
            [
                'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
                'display_name' => 'Staging',
                'client_id' => $this->nullableString(config('bitrix24.application.client_id')),
                'application_code' => $this->nullableString(config('bitrix24.application.code')),
                'callback_base_url' => $callbackBaseUrl,
            ],
        );

        Bitrix24Connection::query()
            ->whereNull('profile_id')
            ->get()
            ->each(function (Bitrix24Connection $connection) use ($portalDomain, $profile): void {
                if ($this->normalizePortalDomain($connection->portal_domain) !== $portalDomain) {
                    return;
                }

                $connection->forceFill([
                    'profile_id' => $profile->id,
                ])->save();
            });
    }

    private function resolveLegacyCallbackBaseUrl(): ?string
    {
        foreach ([
            config('bitrix24.callbacks.install_url'),
            config('bitrix24.callbacks.events_url'),
            config('bitrix24.callbacks.openlines_url'),
        ] as $candidate) {
            $normalized = $this->normalizeCallbackBaseUrl->handle($this->stripKnownCallbackPath($this->nullableString($candidate)));

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function stripKnownCallbackPath(?string $url): ?string
    {
        if ($url === null || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        $normalizedPath = match ($path) {
            Bitrix24Profile::INSTALL_CALLBACK_PATH,
            Bitrix24Profile::EVENTS_CALLBACK_PATH,
            Bitrix24Profile::OPENLINES_CALLBACK_PATH => '',
            default => is_string($path) ? rtrim('/'.ltrim($path, '/'), '/') : '',
        };

        $normalizedPort = is_int($port) ? ':'.$port : '';

        return mb_strtolower($scheme).'://'.mb_strtolower($host).$normalizedPort.$normalizedPath;
    }

    private function normalizePortalDomain(mixed $value): ?string
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

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
