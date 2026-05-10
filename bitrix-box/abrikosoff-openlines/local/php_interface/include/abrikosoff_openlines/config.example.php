<?php

return [
    'laravel' => [
        'openlines_callback_url' => 'https://project2.abrikosoff.ru/callbacks/bitrix24/openlines',
        'connect_timeout_seconds' => 5,
        'timeout_seconds' => 15,
    ],

    'auth' => [
        'portal_domain' => 'crm.alexlesley.biz',
        'member_id' => 'paste-active-member-id-from-bitrix24_connections',
        'application_token' => 'paste-active-application-token-from-bitrix24_connections',
        'client_endpoint' => '',
        'server_endpoint' => '',
        'status' => 'L',
    ],

    'route_registry' => [
        'enabled' => false,
        'storage_dir' => '/home/bitrix/.abrikosoff_openlines',
        'transition_fallback_routes' => [
            'abc_telegram:32',
            'abc_telegram:33',
            'abc_max:31',
        ],
    ],

    'connectors' => [
        'abc_telegram' => [
            'name' => 'ABC Telegram',
            'component' => 'abrikosoff:imconnector.telegram',
            'line_id' => '32',
            'line_name' => '<line-id> Локальный бот телеграм - <profile-name>',
            'lines' => [
                '32' => [
                    'line_name' => '32 Локальный бот телеграм - <profile-name>',
                    'owner_profile_key' => 'staging',
                    'owner_callback_base_url' => 'https://project2.abrikosoff.ru',
                ],
                '33' => [
                    'line_name' => '33 Второй локальный бот телеграм - <profile-name>',
                    'owner_profile_key' => 'staging',
                    'owner_callback_base_url' => 'https://project2.abrikosoff.ru',
                ],
            ],
            'color' => '#27A7E7',
            'label' => 'TG',
        ],
        'abc_max' => [
            'name' => 'ABC MAX',
            'component' => 'abrikosoff:imconnector.max',
            'line_id' => '31',
            'line_name' => '<line-id> Локальный бот MAX - <profile-name>',
            'lines' => [
                '31' => [
                    'line_name' => '31 Локальный бот MAX - <profile-name>',
                    'owner_profile_key' => 'staging',
                    'owner_callback_base_url' => 'https://project2.abrikosoff.ru',
                ],
            ],
            'color' => '#7B4DFF',
            'label' => 'MX',
        ],
    ],

    'crm_rebinding' => [
        'enabled' => false,
        'log_payload' => false,
        'log_file' => '',
    ],
];
