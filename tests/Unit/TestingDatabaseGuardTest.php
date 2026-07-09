<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use Tests\Support\TestingDatabaseGuard;

class TestingDatabaseGuardTest extends TestCase
{
    public function test_database_url_parameter_is_marked_as_sensitive(): void
    {
        $parameter = null;

        foreach ((new ReflectionMethod(TestingDatabaseGuard::class, 'assertSafe'))->getParameters() as $candidate) {
            if ($candidate->getName() === 'databaseUrl') {
                $parameter = $candidate;

                break;
            }
        }

        if ($parameter === null) {
            $this->fail('The databaseUrl parameter was not found.');
        }

        $this->assertNotEmpty($parameter->getAttributes(SensitiveParameter::class));
    }

    public function test_database_url_cannot_override_safe_database_name_with_runtime_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('abrikosoff_connector" resolved from DB_URL');

        TestingDatabaseGuard::assertSafe(
            'pgsql',
            'abrikosoff_connector_test',
            'postgresql://user:password@db.example/abrikosoff_connector',
        );
    }

    public function test_database_url_query_options_are_validated_like_laravel_configuration(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('abrikosoff_connector_recovery" resolved from DB_URL');

        TestingDatabaseGuard::assertSafe(
            'pgsql',
            'abrikosoff_connector_test',
            'postgresql://user:password@db.example/abrikosoff_connector_test?database=abrikosoff_connector_recovery',
        );
    }

    public function test_postgres_connect_via_database_cannot_override_safe_database_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('abrikosoff_connector" resolved from DB_URL');

        TestingDatabaseGuard::assertSafe(
            'pgsql',
            'abrikosoff_connector_test',
            'postgresql://user:password@db.example/abrikosoff_connector_test?connect_via_database=abrikosoff_connector',
        );
    }

    public function test_test_database_from_database_url_is_allowed(): void
    {
        TestingDatabaseGuard::assertSafe(
            'pgsql',
            'abrikosoff_connector_test',
            'postgresql://user:password@db.example/abrikosoff_connector_testing',
        );

        $this->addToAssertionCount(1);
    }

    public function test_sqlite_memory_database_is_allowed(): void
    {
        TestingDatabaseGuard::assertSafe('sqlite', ':memory:', null);

        $this->addToAssertionCount(1);
    }

    public function test_sqlite_memory_database_url_is_allowed(): void
    {
        TestingDatabaseGuard::assertSafe(null, null, 'sqlite:///:memory:');

        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeDatabaseProvider')]
    public function test_runtime_database_names_are_rejected(string $database): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('resolved from DB_DATABASE');

        TestingDatabaseGuard::assertSafe('pgsql', $database, null);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeDatabaseProvider(): array
    {
        return [
            'runtime' => ['abrikosoff_connector'],
            'recovery' => ['abrikosoff_connector_recovery'],
            'recovered snapshot' => ['abrikosoff_connector_recovered_testing_snapshot'],
            'restored snapshot' => ['abrikosoff_connector_restored_testing_snapshot'],
        ];
    }

    public function test_malformed_database_url_is_rejected_without_exposing_it(): void
    {
        $databaseUrl = 'postgresql://user:secret@:bad';

        try {
            TestingDatabaseGuard::assertSafe('pgsql', 'abrikosoff_connector_test', $databaseUrl);
            $this->fail('Malformed DB_URL was not rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('DB_URL is malformed', $exception->getMessage());
            $this->assertStringNotContainsString($databaseUrl, $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function test_existing_default_configuration_cache_is_rejected(): void
    {
        $projectRoot = sys_get_temp_dir().'/testing-database-guard-'.bin2hex(random_bytes(8));
        $cacheDirectory = $projectRoot.'/bootstrap/cache';
        mkdir($cacheDirectory, 0777, true);
        file_put_contents($cacheDirectory.'/config.php', '<?php return [];');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Laravel configuration is cached');

            TestingDatabaseGuard::assertConfigurationIsNotCached($projectRoot, null);
        } finally {
            unlink($cacheDirectory.'/config.php');
            rmdir($cacheDirectory);
            rmdir($projectRoot.'/bootstrap');
            rmdir($projectRoot);
        }
    }

    public function test_existing_relative_custom_configuration_cache_is_rejected(): void
    {
        $projectRoot = sys_get_temp_dir().'/testing-database-guard-'.bin2hex(random_bytes(8));
        $cacheDirectory = $projectRoot.'/var/cache';
        mkdir($cacheDirectory, 0777, true);
        file_put_contents($cacheDirectory.'/config.php', '<?php return [];');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Laravel configuration is cached');

            TestingDatabaseGuard::assertConfigurationIsNotCached($projectRoot, 'var/cache/config.php');
        } finally {
            unlink($cacheDirectory.'/config.php');
            rmdir($cacheDirectory);
            rmdir($projectRoot.'/var');
            rmdir($projectRoot);
        }
    }
}
