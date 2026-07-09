<?php

use Tests\Support\TestingDatabaseGuard;

$pdoConstantFixScript = __DIR__.'/../scripts/fix-framework-pdo-constant.php';

if (file_exists($pdoConstantFixScript)) {
    require $pdoConstantFixScript;
}

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/Support/TestingDatabaseGuard.php';

$projectRoot = dirname(__DIR__);
$testingEnvPath = $projectRoot.'/.env.testing';
$testingEnvExamplePath = $projectRoot.'/.env.testing.example';
$appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV');

if ($appEnv === 'testing' && ! file_exists($testingEnvPath) && file_exists($testingEnvExamplePath)) {
    Dotenv\Dotenv::createImmutable($projectRoot, '.env.testing.example')->safeLoad();
}

$envValue = static function (string $key): ?string {
    foreach ([$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)] as $value) {
        if ($value !== false && $value !== null && $value !== '') {
            return trim((string) $value, " \t\n\r\0\x0B\"'");
        }
    }

    return null;
};

TestingDatabaseGuard::assertSafe(
    $envValue('DB_CONNECTION'),
    $envValue('DB_DATABASE'),
    $envValue('DB_URL'),
);
