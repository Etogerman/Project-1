<?php

namespace Tests\Support;

use Illuminate\Database\ConfigurationUrlParser;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class TestingDatabaseGuard
{
    private const RUNTIME_DATABASE_PATTERNS = [
        '/^abrikosoff_connector$/',
        '/^abrikosoff_connector_recovery$/',
        '/^abrikosoff_connector_recovered_/i',
        '/^abrikosoff_connector_restored_/i',
    ];

    public static function assertSafe(
        ?string $connection,
        ?string $database,
        #[SensitiveParameter]
        ?string $databaseUrl,
    ): void {
        $source = 'DB_DATABASE';
        $connection = strtolower(trim((string) $connection));
        $database = self::normalizeDatabaseName($database);

        if ($databaseUrl !== null && trim($databaseUrl) !== '') {
            $source = 'DB_URL';

            try {
                $configuration = (new ConfigurationUrlParser)->parseConfiguration([
                    'driver' => $connection,
                    'database' => $database,
                    'url' => trim($databaseUrl),
                ]);
            } catch (Throwable) {
                throw new RuntimeException(
                    'Refusing to run tests because DB_URL is malformed or cannot be validated.'
                );
            }

            $connection = strtolower(trim((string) ($configuration['driver'] ?? $connection)));
            $database = self::normalizeDatabaseName(
                $connection === 'pgsql'
                    ? ($configuration['connect_via_database'] ?? $configuration['database'] ?? null)
                    : ($configuration['database'] ?? null)
            );
        }

        $isSqliteMemory = $connection === 'sqlite' && $database === ':memory:';
        $isExplicitTestDatabase = $database !== null
            && preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database) === 1;
        $isKnownRuntimeDatabase = $database !== null
            && self::matchesKnownRuntimeDatabase($database);

        if ($isSqliteMemory || ($isExplicitTestDatabase && ! $isKnownRuntimeDatabase)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests against non-test database "%s" resolved from %s. Configure a dedicated *_test database.',
            $database ?? '<empty>',
            $source,
        ));
    }

    public static function assertConfigurationIsNotCached(
        string $projectRoot,
        ?string $configuredCachePath,
    ): void {
        $cachePath = self::resolveConfigCachePath($projectRoot, $configuredCachePath);

        if (! is_file($cachePath)) {
            return;
        }

        throw new RuntimeException(
            'Refusing to run tests while Laravel configuration is cached. Run php artisan config:clear first.'
        );
    }

    private static function normalizeDatabaseName(mixed $database): ?string
    {
        if (! is_string($database)) {
            return null;
        }

        $database = trim($database, " \t\n\r\0\x0B\"'");

        return $database !== '' ? $database : null;
    }

    private static function matchesKnownRuntimeDatabase(string $database): bool
    {
        foreach (self::RUNTIME_DATABASE_PATTERNS as $pattern) {
            if (preg_match($pattern, $database) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function resolveConfigCachePath(string $projectRoot, ?string $configuredCachePath): string
    {
        $configuredCachePath = self::normalizeDatabaseName($configuredCachePath);

        if ($configuredCachePath === null) {
            return $projectRoot.'/bootstrap/cache/config.php';
        }

        if (str_starts_with($configuredCachePath, '/') || str_starts_with($configuredCachePath, '\\')) {
            return $configuredCachePath;
        }

        return $projectRoot.'/'.$configuredCachePath;
    }
}
