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
