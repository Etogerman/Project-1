<?php

namespace App\Support;

class AppVersion
{
    /**
     * @var list<string>
     */
    protected const ENVIRONMENT_KEYS = [
        'APP_VERSION',
        'APP_BUILD_VERSION',
        'LARAVEL_CLOUD_GIT_COMMIT_SHA',
        'LARAVEL_CLOUD_COMMIT_SHA',
        'GIT_COMMIT_SHA',
        'GITHUB_SHA',
        'VERCEL_GIT_COMMIT_SHA',
    ];

    public static function resolve(): ?string
    {
        foreach (self::ENVIRONMENT_KEYS as $key) {
            $version = static::normalize(static::readEnvironmentValue($key));

            if ($version !== null) {
                return $version;
            }
        }

        return static::resolveFromGitDirectory(base_path('.git'))
            ?? static::resolveFromFile(base_path('VERSION'));
    }

    public static function display(): ?string
    {
        return static::displayFromVersion(static::resolve());
    }

    public static function displayFromVersion(?string $version): ?string
    {
        $normalized = static::normalize($version);

        if ($normalized === null) {
            return null;
        }

        return static::looksLikeCommitHash($normalized)
            ? 'rev '.$normalized
            : $normalized;
    }

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        if (static::looksLikeCommitHash($normalized)) {
            return strtolower(substr($normalized, 0, 7));
        }

        return $normalized;
    }

    public static function resolveFromGitDirectory(string $path): ?string
    {
        $gitDirectory = static::resolveGitDirectory($path);

        if ($gitDirectory === null) {
            return null;
        }

        $head = static::readFile($gitDirectory.DIRECTORY_SEPARATOR.'HEAD');

        if ($head === null) {
            return null;
        }

        if (str_starts_with($head, 'ref: ')) {
            $reference = trim(substr($head, 5));

            return static::normalize(static::resolveGitReference($gitDirectory, $reference));
        }

        return static::normalize($head);
    }

    public static function resolveFromFile(string $path): ?string
    {
        return static::normalize(static::readFile($path));
    }

    protected static function looksLikeCommitHash(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{7,40}$/i', $value);
    }

    protected static function resolveGitDirectory(string $path): ?string
    {
        if (is_dir($path)) {
            return $path;
        }

        if (! is_file($path)) {
            return null;
        }

        $contents = static::readFile($path);

        if ($contents === null || ! str_starts_with($contents, 'gitdir:')) {
            return null;
        }

        $directory = trim(substr($contents, 7));

        if ($directory === '') {
            return null;
        }

        if (str_starts_with($directory, DIRECTORY_SEPARATOR)) {
            return is_dir($directory) ? $directory : null;
        }

        $resolvedDirectory = dirname($path).DIRECTORY_SEPARATOR.$directory;

        return is_dir($resolvedDirectory) ? $resolvedDirectory : null;
    }

    protected static function resolveGitReference(string $gitDirectory, string $reference): ?string
    {
        $version = static::readFile($gitDirectory.DIRECTORY_SEPARATOR.$reference);

        if ($version !== null) {
            return $version;
        }

        $commonGitDirectory = static::resolveCommonGitDirectory($gitDirectory);

        if ($commonGitDirectory === null) {
            return null;
        }

        return static::readFile($commonGitDirectory.DIRECTORY_SEPARATOR.$reference)
            ?? static::readPackedReference($commonGitDirectory, $reference);
    }

    protected static function resolveCommonGitDirectory(string $gitDirectory): ?string
    {
        $commonDirectory = static::readFile($gitDirectory.DIRECTORY_SEPARATOR.'commondir');

        if ($commonDirectory === null || $commonDirectory === '') {
            return null;
        }

        $candidate = str_starts_with($commonDirectory, DIRECTORY_SEPARATOR)
            ? $commonDirectory
            : $gitDirectory.DIRECTORY_SEPARATOR.$commonDirectory;

        $resolvedDirectory = realpath($candidate);

        return $resolvedDirectory !== false && is_dir($resolvedDirectory)
            ? $resolvedDirectory
            : null;
    }

    protected static function readPackedReference(string $gitDirectory, string $reference): ?string
    {
        $packedRefs = static::readFile($gitDirectory.DIRECTORY_SEPARATOR.'packed-refs');

        if ($packedRefs === null) {
            return null;
        }

        foreach (preg_split('/\R/', $packedRefs) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '^')) {
                continue;
            }

            [$hash, $packedReference] = array_pad(preg_split('/\s+/', $line, 2) ?: [], 2, null);

            if ($packedReference === $reference && is_string($hash)) {
                return $hash;
            }
        }

        return null;
    }

    protected static function readEnvironmentValue(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? $value : null;
    }

    protected static function readFile(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return trim($contents);
    }
}
