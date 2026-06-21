<?php

return [
    'portal_domain' => env('BITRIX24_PORTAL_DOMAIN', 'crm.alexlesley.biz'),

    'application' => [
        'name' => env('BITRIX24_APP_NAME', 'Abrikosoff Connector'),
        'client_id' => env('BITRIX24_CLIENT_ID'),
        'code' => env('BITRIX24_APP_CODE'),
        'client_secret' => env('BITRIX24_CLIENT_SECRET'),
        'scopes' => [
            'crm',
            'task',
            'tasks',
            'im',
            'imopenlines',
            'imconnector',
            'imbot',
        ],
    ],

    'oauth' => [
        'server_url' => env('BITRIX24_AUTH_SERVER_URL'),
    ],

    'callbacks' => [
        'install_url' => env('BITRIX24_INSTALL_CALLBACK_URL'),
        'events_url' => env('BITRIX24_EVENTS_CALLBACK_URL'),
        'openlines_url' => env('BITRIX24_OPENLINES_CALLBACK_URL'),
    ],

    'install_validation' => [
        'allow_uninstalled_app_probe' => env('BITRIX24_ALLOW_UNINSTALLED_APP_PROBE', env('APP_ENV') === 'local'),
    ],

    'features' => [
        'contacts_sync_enabled' => (bool) env('BITRIX24_CONTACTS_SYNC_ENABLED', false),
        'deals_sync_enabled' => (bool) env('BITRIX24_DEALS_SYNC_ENABLED', false),
        'openlines_enabled' => (bool) env('BITRIX24_OPENLINES_ENABLED', false),
        'fake_happy_path_enabled' => (bool) env('BITRIX24_FAKE_HAPPY_PATH_ENABLED', false),
        'fast_inbound_export_enabled' => filter_var(
            env('BITRIX24_OPENLINES_FAST_INBOUND_EXPORT_ENABLED', env('APP_ENV') === 'local'),
            FILTER_VALIDATE_BOOL,
        ),
        'timeline_history_import_enabled' => filter_var(
            env('BITRIX24_TIMELINE_HISTORY_IMPORT_ENABLED', true),
            FILTER_VALIDATE_BOOL,
        ),
        'reverse_sync_enabled' => (bool) env('BITRIX24_REVERSE_SYNC_ENABLED', false),
    ],

    'defaults' => [
        'assigned_user_id' => (int) env('BITRIX24_DEFAULT_ASSIGNED_USER_ID', 1),
        'deal_category_id' => (int) env('BITRIX24_DEFAULT_DEAL_CATEGORY_ID', 22),
        'deal_stage_id' => env('BITRIX24_DEFAULT_DEAL_STAGE_ID', 'C22:NEW'),
    ],

    'sources' => [
        'telegram_id' => env('BITRIX24_TELEGRAM_SOURCE_ID'),
        'max_id' => env('BITRIX24_MAX_SOURCE_ID'),
    ],

    'openlines' => [
        'telegram_connector_code' => env('BITRIX24_TELEGRAM_CONNECTOR_CODE'),
        'max_connector_code' => env('BITRIX24_MAX_CONNECTOR_CODE'),
        'official_connector_codes' => array_values(array_unique(array_filter(array_map(
            static fn (string $connectorCode): string => trim($connectorCode),
            explode(',', (string) env('BITRIX24_OPENLINES_OFFICIAL_CONNECTOR_CODES', 'abrikosoff_telegram,abrikosoff_max')),
        )))),
        'allow_official_connectors_in_local' => filter_var(
            env('BITRIX24_OPENLINES_ALLOW_OFFICIAL_CONNECTORS_IN_LOCAL', false),
            FILTER_VALIDATE_BOOL,
        ),
        'live_export_queue' => env('BITRIX24_OPENLINES_LIVE_EXPORT_QUEUE', 'bitrix-live'),
        'runtime_application_token_hashes' => array_values(array_filter(array_map(
            static fn (string $hash): string => trim($hash),
            explode(',', (string) env(
                'BITRIX24_OPENLINES_RUNTIME_APPLICATION_TOKEN_HASHES',
                env('BITRIX24_OPENLINES_RUNTIME_APPLICATION_TOKEN_HASH', ''),
            )),
        ))),
        'service_user_id' => (int) env('BITRIX24_OPENLINES_SERVICE_USER_ID', 0),
        'session_finish_event_names' => [
            'OnSessionFinish',
        ],
    ],

    'duplicate_phone_diagnostic' => [
        'enabled' => (bool) env('BITRIX24_DUPLICATE_PHONE_DIAGNOSTIC_ENABLED', false),
        'delay_seconds' => (int) env('BITRIX24_DUPLICATE_PHONE_DIAGNOSTIC_DELAY_SECONDS', 90),
    ],

    'http' => [
        'timeout_seconds' => (int) env('BITRIX24_HTTP_TIMEOUT_SECONDS', 15),
        'connect_timeout_seconds' => (int) env('BITRIX24_HTTP_CONNECT_TIMEOUT_SECONDS', 5),
        'retry_sleep_milliseconds' => (int) env('BITRIX24_HTTP_RETRY_SLEEP_MILLISECONDS', 200),
    ],

    'rate_limits' => [
        'install' => [
            'max_per_minute' => (int) env('BITRIX24_INSTALL_CALLBACK_MAX_PER_MINUTE', 30),
        ],
        'events' => [
            'max_per_minute' => (int) env('BITRIX24_EVENTS_CALLBACK_MAX_PER_MINUTE', 300),
        ],
        'openlines' => [
            'max_per_minute' => (int) env('BITRIX24_OPENLINES_CALLBACK_MAX_PER_MINUTE', 300),
        ],
    ],

    'fields' => [
        'name_source' => env('BITRIX24_FIELD_NAME_SOURCE', 'UF_CRM_64D7457E4DC07'),
        'age_exact' => env('BITRIX24_FIELD_AGE_EXACT', 'UF_CRM_1606901533'),
        'gender' => env('BITRIX24_FIELD_GENDER', 'UF_CRM_5EEB7355C13B1'),
        'age_range' => env('BITRIX24_FIELD_AGE_RANGE', 'UF_CRM_ABRIKOSOFF_AGE_RANGE'),
        'contact_id' => env('BITRIX24_FIELD_CONTACT_ID', 'UF_CRM_ABRIKOSOFF_CONTACT_ID'),
        'channel_id' => env('BITRIX24_FIELD_CHANNEL_ID', 'UF_CRM_ABRIKOSOFF_CHANNEL_ID'),
        'channel_name' => env('BITRIX24_FIELD_CHANNEL_NAME', 'UF_CRM_ABRIKOSOFF_CHANNEL_NAME'),
        'platform' => env('BITRIX24_FIELD_PLATFORM', 'UF_CRM_ABRIKOSOFF_PLATFORM'),
        'bot_code' => env('BITRIX24_FIELD_BOT_CODE', 'UF_CRM_ABRIKOSOFF_BOT_CODE'),
        'bot_name' => env('BITRIX24_FIELD_BOT_NAME', 'UF_CRM_ABRIKOSOFF_BOT_NAME'),
        'alt_first_name' => env('BITRIX24_FIELD_ALT_FIRST_NAME', 'UF_CRM_ABRIKOSOFF_ALT_FIRST_NAME'),
        'alt_last_name' => env('BITRIX24_FIELD_ALT_LAST_NAME', 'UF_CRM_ABRIKOSOFF_ALT_LAST_NAME'),
        'name_conflict' => env('BITRIX24_FIELD_NAME_CONFLICT', 'UF_CRM_ABRIKOSOFF_NAME_CONFLICT'),
    ],

    'values' => [
        'name_source' => [
            'automatic_information_id' => (int) env('BITRIX24_NAME_SOURCE_AUTOMATIC_ID', 26),
            'self_reported_id' => (int) env('BITRIX24_NAME_SOURCE_SELF_REPORTED_ID', 27),
            'training_verified_id' => (int) env('BITRIX24_NAME_SOURCE_TRAINING_VERIFIED_ID', 28),
        ],
        'gender' => [
            'male_id' => (int) env('BITRIX24_GENDER_MALE_ID', 29),
            'female_id' => (int) env('BITRIX24_GENDER_FEMALE_ID', 30),
            'unknown_id' => (int) env('BITRIX24_GENDER_UNKNOWN_ID', 31),
        ],
    ],
];
