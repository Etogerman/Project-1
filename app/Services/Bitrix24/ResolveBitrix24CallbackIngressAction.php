<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24CallbackIngressData;
use App\Models\Bitrix24Profile;
use Illuminate\Http\Request;

class ResolveBitrix24CallbackIngressAction
{
    public function __construct(
        private readonly NormalizeBitrix24CallbackBaseUrlAction $normalizeCallbackBaseUrl,
        private readonly BackfillBitrix24ConnectionProfilesAction $backfillConnectionProfiles,
    ) {}

    public function handle(Request $request): Bitrix24CallbackIngressData
    {
        $callbackBaseUrl = $this->normalizeCallbackBaseUrl->handle($request->root());

        if ($callbackBaseUrl === null) {
            return new Bitrix24CallbackIngressData(
                callbackBaseUrl: null,
                profile: null,
            );
        }

        $profile = $this->findProfile($callbackBaseUrl);

        if (! $profile && $this->matchesConfiguredCallbackBaseUrl($callbackBaseUrl)) {
            $this->backfillConnectionProfiles->handle();
            $profile = $this->findProfile($callbackBaseUrl);
        }

        return new Bitrix24CallbackIngressData(
            callbackBaseUrl: $callbackBaseUrl,
            profile: $profile,
        );
    }

    private function findProfile(string $callbackBaseUrl): ?Bitrix24Profile
    {
        return Bitrix24Profile::query()
            ->where('callback_base_url', $callbackBaseUrl)
            ->first();
    }

    private function matchesConfiguredCallbackBaseUrl(string $callbackBaseUrl): bool
    {
        foreach ([
            config('bitrix24.callbacks.install_url'),
            config('bitrix24.callbacks.events_url'),
            config('bitrix24.callbacks.openlines_url'),
        ] as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $normalized = $this->normalizeCallbackBaseUrl->handle($this->stripKnownCallbackPath((string) $candidate));

            if ($normalized === $callbackBaseUrl) {
                return true;
            }
        }

        return false;
    }

    private function stripKnownCallbackPath(string $url): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($scheme) || ! is_string($host)) {
            return $url;
        }

        $normalizedPath = is_string($path)
            ? rtrim('/'.ltrim($path, '/'), '/')
            : '';

        foreach ([
            Bitrix24Profile::INSTALL_CALLBACK_PATH,
            Bitrix24Profile::EVENTS_CALLBACK_PATH,
            Bitrix24Profile::OPENLINES_CALLBACK_PATH,
        ] as $suffix) {
            if (str_ends_with($normalizedPath, $suffix)) {
                $prefixPath = substr($normalizedPath, 0, -strlen($suffix));
                $normalizedPath = $prefixPath === false ? '' : rtrim($prefixPath, '/');

                break;
            }
        }

        $normalizedPort = is_int($port) ? ':'.$port : '';

        return $scheme.'://'.$host.$normalizedPort.$normalizedPath;
    }
}
