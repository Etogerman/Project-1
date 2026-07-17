<?php

namespace App\Support;

use InvalidArgumentException;

final class TelegramLocalApiConfiguration
{
    public static function normalizedHost(mixed $host): ?string
    {
        if (! is_string($host)) {
            return null;
        }

        $normalized = mb_strtolower($host);

        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, '[')) {
            if (! str_ends_with($normalized, ']')) {
                return null;
            }

            $ipv6Literal = substr($normalized, 1, -1);

            if (filter_var($ipv6Literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                return $ipv6Literal;
            }

            return null;
        }

        $normalized = rtrim($normalized, '.');

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return list<string>
     */
    public static function normalizedTrustedHosts(mixed $hosts): array
    {
        if (! is_array($hosts)) {
            return [];
        }

        $normalized = array_map(
            static fn (mixed $host): ?string => self::normalizedHost(
                is_string($host) ? trim($host) : $host,
            ),
            $hosts,
        );

        return array_values(array_unique(array_filter(
            $normalized,
            static fn (?string $host): bool => $host !== null,
        )));
    }

    public static function absoluteFilesRoot(mixed $configuredRoot): ?string
    {
        if (! is_string($configuredRoot)) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', trim($configuredRoot)), '/');

        if ($root === '' || ! str_starts_with($root, '/') || str_contains($root, "\0")) {
            return null;
        }

        foreach (explode('/', substr($root, 1)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $root;
    }

    public static function relativeFilePath(string $filePath, mixed $configuredRoot): string
    {
        $root = self::absoluteFilesRoot($configuredRoot);
        $path = str_replace('\\', '/', trim($filePath));

        if ($root === null || ! str_starts_with($path, $root.'/')) {
            throw new InvalidArgumentException('Telegram Local Bot API media path is outside the configured root.');
        }

        $relativePath = substr($path, strlen($root) + 1);

        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            throw new InvalidArgumentException('Telegram Local Bot API media path is invalid.');
        }

        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Telegram Local Bot API media path is invalid.');
            }
        }

        return $relativePath;
    }
}
