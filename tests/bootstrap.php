<?php

$pdoConstantFixScript = __DIR__.'/../scripts/fix-framework-pdo-constant.php';

if (file_exists($pdoConstantFixScript)) {
    require $pdoConstantFixScript;
}

require __DIR__.'/../vendor/autoload.php';
