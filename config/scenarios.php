<?php

use App\Models\Channel;

return [
    /*
    |--------------------------------------------------------------------------
    | Scenario Registry
    |--------------------------------------------------------------------------
    |
    | Scenario runtime is code-defined. Keys are stable business codes used by
    | storage and future routing layers. Values are handler class names.
    |
    */
    'warmup' => [
        'handler' => \App\Services\Scenarios\WarmupScenario::class,
        'platforms' => [
            Channel::PLATFORM_TELEGRAM,
        ],
    ],
];
