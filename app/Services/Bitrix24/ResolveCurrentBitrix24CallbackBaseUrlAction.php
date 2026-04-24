<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Profile;

class ResolveCurrentBitrix24CallbackBaseUrlAction
{
    public function __construct(
        private readonly NormalizeBitrix24CallbackBaseUrlAction $normalizeCallbackBaseUrl,
    ) {}

    public function handle(): string
    {
        $resolvedBaseUrls = [];

        foreach ($this->configuredCallbackUrls() as $key => $url) {
            $trimmed = trim($url);

            if ($trimmed === '') {
                continue;
            }

            $callbackBaseUrl = $this->extractCallbackBaseUrl($trimmed);

            if ($callbackBaseUrl === null) {
                throw new Bitrix24ConnectionStateException(sprintf(
                    'Configured Bitrix24 callback `%s` must be an absolute URL ending with a known callback path.',
                    $key,
                ));
            }

            $resolvedBaseUrls[$key] = $callbackBaseUrl;
        }

        if ($resolvedBaseUrls === []) {
            throw new Bitrix24ConnectionStateException(
                'Configured Bitrix24 callbacks do not resolve to a current runtime callback_base_url.',
            );
        }

        $uniqueBaseUrls = array_values(array_unique($resolvedBaseUrls));

        if (count($uniqueBaseUrls) !== 1) {
            throw new Bitrix24ConnectionStateException(sprintf(
                'Configured Bitrix24 callbacks resolve to different callback_base_url values: %s',
                implode(', ', array_map(
                    static fn (string $key, string $value): string => sprintf('%s=%s', $key, $value),
                    array_keys($resolvedBaseUrls),
                    $resolvedBaseUrls,
                )),
            ));
        }

        return $uniqueBaseUrls[0];
    }

    /**
     * @return array<string, string>
     */
    private function configuredCallbackUrls(): array
    {
        return [
            'callbacks.install_url' => (string) config('bitrix24.callbacks.install_url', ''),
            'callbacks.events_url' => (string) config('bitrix24.callbacks.events_url', ''),
            'callbacks.openlines_url' => (string) config('bitrix24.callbacks.openlines_url', ''),
        ];
    }

    private function extractCallbackBaseUrl(string $url): ?string
    {
        $normalizedUrl = $this->normalizeCallbackBaseUrl->handle($url);

        if ($normalizedUrl === null) {
            return null;
        }

        foreach ($this->knownCallbackPaths() as $callbackPath) {
            if (! str_ends_with($normalizedUrl, $callbackPath)) {
                continue;
            }

            $baseUrl = substr($normalizedUrl, 0, -strlen($callbackPath));

            if ($baseUrl === false || $baseUrl === '') {
                return null;
            }

            return $this->normalizeCallbackBaseUrl->handle($baseUrl);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function knownCallbackPaths(): array
    {
        return [
            Bitrix24Profile::INSTALL_CALLBACK_PATH,
            Bitrix24Profile::EVENTS_CALLBACK_PATH,
            Bitrix24Profile::OPENLINES_CALLBACK_PATH,
        ];
    }
}
