<?php

$pdoConstantFixScript = __DIR__.'/../scripts/fix-framework-pdo-constant.php';

if (file_exists($pdoConstantFixScript)) {
    require $pdoConstantFixScript;
}

require __DIR__.'/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$testingEnvPath = $projectRoot.'/.env.testing';
$testingEnvExamplePath = $projectRoot.'/.env.testing.example';
$appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV');

if ($appEnv === 'testing' && ! file_exists($testingEnvPath) && file_exists($testingEnvExamplePath)) {
    Dotenv\Dotenv::createImmutable($projectRoot, '.env.testing.example')->safeLoad();
}

$envValue = static function (string $key): ?string {
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return null;
    }

    return (string) $value;
};

$connection = strtolower($envValue('DB_CONNECTION') ?? '');
$database = $envValue('DB_DATABASE');
$isSqliteMemory = $connection === 'sqlite' && $database === ':memory:';
$isExplicitTestDatabase = is_string($database)
    && preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database) === 1;
$knownRuntimeDatabasePatterns = [
    '/^abrikosoff_connector$/',
    '/^abrikosoff_connector_recovery$/',
    '/^abrikosoff_connector_recovered_/i',
    '/^abrikosoff_connector_restored_/i',
];
$isKnownRuntimeDatabase = false;

if (is_string($database)) {
    foreach ($knownRuntimeDatabasePatterns as $pattern) {
        if (preg_match($pattern, $database) === 1) {
            $isKnownRuntimeDatabase = true;
            break;
        }
    }
}

if (
    ! $isSqliteMemory
    && (
        ! $isExplicitTestDatabase
        || $isKnownRuntimeDatabase
    )
) {
    throw new RuntimeException(sprintf(
        'Refusing to run tests against non-test database "%s". Configure DB_DATABASE to a dedicated *_test database.',
        $database ?? '<empty>'
    ));
}
