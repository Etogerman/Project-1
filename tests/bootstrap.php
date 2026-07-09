<?php

use Tests\Support\TestingDatabaseGuard;
use Tests\Support\TestingEnvironment;

$pdoConstantFixScript = __DIR__.'/../scripts/fix-framework-pdo-constant.php';

if (file_exists($pdoConstantFixScript)) {
    require $pdoConstantFixScript;
}

require __DIR__.'/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);

TestingEnvironment::load($projectRoot, TestingEnvironment::value('APP_ENV'));

TestingDatabaseGuard::assertConfigurationIsNotCached(
    $projectRoot,
    TestingEnvironment::value('APP_CONFIG_CACHE'),
);

TestingDatabaseGuard::assertSafe(
    TestingEnvironment::value('DB_CONNECTION'),
    TestingEnvironment::value('DB_DATABASE'),
    TestingEnvironment::value('DB_URL'),
);
