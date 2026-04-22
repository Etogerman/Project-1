<?php

namespace App\Services\Bitrix24;

class NormalizeBitrix24CallbackBaseUrlAction
{
    public function handle(?string $value): ?string
    {
        if (! filled($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);
        $port = parse_url($value, PHP_URL_PORT);
        $path = parse_url($value, PHP_URL_PATH);

        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        $normalizedPath = is_string($path)
            ? rtrim('/'.ltrim($path, '/'), '/')
            : '';

        $normalizedPort = is_int($port) ? ':'.$port : '';

        return mb_strtolower($scheme).'://'.mb_strtolower($host).$normalizedPort.$normalizedPath;
    }
}
