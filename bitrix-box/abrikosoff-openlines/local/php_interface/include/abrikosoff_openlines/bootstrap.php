<?php

if (! defined('ABRIKOSOFF_OPENLINES_BOOTSTRAP_INCLUDED')) {
    define('ABRIKOSOFF_OPENLINES_BOOTSTRAP_INCLUDED', true);

    require_once __DIR__.'/src/Runtime.php';

    \Abrikosoff\BitrixBox\OpenLines\Runtime::register();
}
