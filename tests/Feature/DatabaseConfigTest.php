<?php

namespace Tests\Feature;

use ErrorException;
use Tests\TestCase;

class DatabaseConfigTest extends TestCase
{
    public function test_database_config_file_does_not_trigger_mysql_ssl_ca_deprecation(): void
    {
        $capturedDeprecations = [];

        set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0) use (&$capturedDeprecations): bool {
            if ($severity === E_DEPRECATED) {
                $capturedDeprecations[] = $message;

                throw new ErrorException($message, 0, $severity, $file, $line);
            }

            return false;
        });

        try {
            $config = require config_path('database.php');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $capturedDeprecations);
        $this->assertIsArray($config);
        $this->assertIsArray($config['connections']['mysql']['options'] ?? null);
        $this->assertIsArray($config['connections']['mariadb']['options'] ?? null);
    }

    public function test_database_config_no_longer_references_deprecated_pdo_mysql_ssl_constant(): void
    {
        $configContents = (string) file_get_contents(config_path('database.php'));

        $this->assertStringNotContainsString('PDO::MYSQL_ATTR_SSL_CA', $configContents);
        $this->assertStringContainsString('Pdo\\\\Mysql::ATTR_SSL_CA', $configContents);
    }

    public function test_framework_database_config_uses_non_deprecated_mysql_ssl_constant(): void
    {
        $frameworkConfigContents = (string) file_get_contents(base_path('vendor/laravel/framework/config/database.php'));

        $this->assertStringNotContainsString('PDO::MYSQL_ATTR_SSL_CA =>', $frameworkConfigContents);
        $this->assertTrue(
            str_contains($frameworkConfigContents, 'Pdo\\Mysql::ATTR_SSL_CA')
            || (
                str_contains($frameworkConfigContents, 'use Pdo\\Mysql;')
                && str_contains($frameworkConfigContents, 'Mysql::ATTR_SSL_CA')
            ),
        );
    }
}
