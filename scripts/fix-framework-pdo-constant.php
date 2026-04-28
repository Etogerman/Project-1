<?php

if (PHP_VERSION_ID < 80500) {
    return;
}

$frameworkConfigPath = dirname(__DIR__).'/vendor/laravel/framework/config/database.php';

if (! file_exists($frameworkConfigPath)) {
    return;
}

$deprecatedConstant = 'PDO::MYSQL_ATTR_SSL_CA';
$replacement = '(PHP_VERSION_ID >= 80500 ? Pdo\\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA)';
$contents = file_get_contents($frameworkConfigPath);

if ($contents === false || str_contains($contents, $replacement) || ! str_contains($contents, $deprecatedConstant)) {
    return;
}

file_put_contents(
    $frameworkConfigPath,
    str_replace($deprecatedConstant, $replacement, $contents),
);
