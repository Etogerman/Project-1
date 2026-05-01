<?php

return [
    'laravel' => [
        'openlines_callback_url' => 'https://project2.abrikosoff.ru/callbacks/bitrix24/openlines',
        'connect_timeout_seconds' => 5,
        'timeout_seconds' => 15,
    ],

    'auth' => [
        'portal_domain' => 'stagecrm.fvds.ru',
        'member_id' => 'paste-active-member-id-from-bitrix24_connections',
        'application_token' => 'paste-active-application-token-from-bitrix24_connections',
        'client_endpoint' => '',
        'server_endpoint' => '',
        'status' => 'L',
    ],

    'connectors' => [
        'abrikosoff_telegram' => [
            'name' => 'Abrikosoff Telegram',
            'component' => 'abrikosoff:imconnector.telegram',
            'line_id' => '32',
            'line_name' => 'ABR Телеграм бот <bot-name>',
            'lines' => [
                '32' => [
                    'line_name' => 'ABR Телеграм бот <bot-name>',
                    'owner_profile_key' => 'staging',
                    'owner_callback_base_url' => 'https://project2.abrikosoff.ru',
                ],
                '33' => [
                    'line_name' => 'ABR Телеграм бот <second-bot-name>',
                    'owner_profile_key' => 'staging',
                    'owner_callback_base_url' => 'https://project2.abrikosoff.ru',
                ],
            ],
            'color' => '#27A7E7',
            'label' => 'TG',
        ],
        'abrikosoff_max' => [
            'name' => 'Abrikosoff MAX',
            'component' => 'abrikosoff:imconnector.max',
            'line_id' => '31',
            'line_name' => 'ABR MAX бот <bot-name>',
            'lines' => [
                '31' => [
                    'line_name' => 'ABR MAX бот <bot-name>',
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
