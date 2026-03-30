<?php

return [
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'temperature' => (float) env('GEMINI_TEMPERATURE', 0.2),
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 512),
        'thinking_budget' => (int) env('GEMINI_THINKING_BUDGET', 0),
        'debug_logging' => (bool) env('BOT_GEMINI_DEBUG_LOGGING', false),
    ],

    'data_collection' => [
        'enabled' => env('BOT_DATA_COLLECTION_ENABLED', true),
        'first_question' => env('BOT_DATA_COLLECTION_FIRST_QUESTION', 'Как вас зовут?'),
        'completion_message' => env('BOT_DATA_COLLECTION_COMPLETION_MESSAGE', 'Спасибо, данные сохранили.'),
        'first_name' => [
            'question' => env('BOT_DATA_COLLECTION_FIRST_NAME_QUESTION', env('BOT_DATA_COLLECTION_FIRST_QUESTION', 'Как вас зовут?')),
            'retry_message' => env('BOT_DATA_COLLECTION_FIRST_NAME_RETRY_MESSAGE', 'Подскажите, пожалуйста, как к вам обращаться? Можно только имя.'),
            'skip_message' => env('BOT_DATA_COLLECTION_FIRST_NAME_SKIP_MESSAGE', 'Хорошо, имя пока пропустим.'),
            'fallback_error_message' => env('BOT_DATA_COLLECTION_FIRST_NAME_FALLBACK_ERROR_MESSAGE', 'Не смогли распознать имя. Напишите, пожалуйста, только имя.'),
            'max_attempts' => (int) env('BOT_DATA_COLLECTION_FIRST_NAME_MAX_ATTEMPTS', 2),
            'skip_commands' => [
                'пропустить',
                'skip',
            ],
        ],
        'residence_city' => [
            'question' => env('BOT_DATA_COLLECTION_RESIDENCE_CITY_QUESTION', 'В каком городе вы живёте?'),
            'retry_message' => env('BOT_DATA_COLLECTION_RESIDENCE_CITY_RETRY_MESSAGE', 'Подскажите, пожалуйста, город, где вы живёте. Например: Будапешт, Найроби, Алматы.'),
            'skip_message' => env('BOT_DATA_COLLECTION_RESIDENCE_CITY_SKIP_MESSAGE', 'Хорошо, город проживания пока пропустим.'),
            'fallback_error_message' => env('BOT_DATA_COLLECTION_RESIDENCE_CITY_FALLBACK_ERROR_MESSAGE', 'Не удалось распознать город. Напишите, пожалуйста, только название города.'),
            'max_attempts' => (int) env('BOT_DATA_COLLECTION_RESIDENCE_CITY_MAX_ATTEMPTS', 2),
            'skip_commands' => [
                'пропустить',
                'skip',
            ],
        ],
        'country' => [
            'question' => env('BOT_DATA_COLLECTION_COUNTRY_QUESTION', 'В какой стране вы живёте?'),
            'retry_message' => env('BOT_DATA_COLLECTION_COUNTRY_RETRY_MESSAGE', 'Подскажите, пожалуйста, страну, где вы живёте. Например: Венгрия, Кения, Казахстан.'),
            'after_residence_city_question' => env('BOT_DATA_COLLECTION_COUNTRY_AFTER_RESIDENCE_CITY_QUESTION', 'Подскажите, пожалуйста, страну, где вы живёте. Для города «{city}» это нужно уточнить.'),
            'city_mismatch_message' => env('BOT_DATA_COLLECTION_COUNTRY_CITY_MISMATCH_MESSAGE', 'Похоже, город «{city}» не относится к стране «{country}». Подскажите, пожалуйста, страну, где вы живёте.'),
            'skip_message' => env('BOT_DATA_COLLECTION_COUNTRY_SKIP_MESSAGE', 'Хорошо, страну пока пропустим.'),
            'fallback_error_message' => env('BOT_DATA_COLLECTION_COUNTRY_FALLBACK_ERROR_MESSAGE', 'Не смогли распознать страну. Напишите, пожалуйста, только название страны.'),
            'max_attempts' => (int) env('BOT_DATA_COLLECTION_COUNTRY_MAX_ATTEMPTS', 2),
            'skip_commands' => [
                'пропустить',
                'skip',
            ],
        ],
        'city' => [
            'question' => env('BOT_DATA_COLLECTION_CITY_QUESTION', 'В каком городе вы живёте?'),
            'retry_message' => env('BOT_DATA_COLLECTION_CITY_RETRY_MESSAGE', 'Подскажите, пожалуйста, город, где вы живёте. Например: Москва, Алматы, Берлин.'),
            'skip_message' => env('BOT_DATA_COLLECTION_CITY_SKIP_MESSAGE', 'Хорошо, город пока пропустим.'),
            'fallback_error_message' => env('BOT_DATA_COLLECTION_CITY_FALLBACK_ERROR_MESSAGE', 'Не смогли распознать город. Напишите, пожалуйста, только название города.'),
            'max_attempts' => (int) env('BOT_DATA_COLLECTION_CITY_MAX_ATTEMPTS', 2),
            'skip_commands' => [
                'пропустить',
                'skip',
            ],
        ],
        'age_range' => [
            'question' => env(
                'BOT_DATA_COLLECTION_AGE_RANGE_QUESTION',
                "Укажите ваш возраст:\n1. До 18 лет\n2. 18 - 23 года\n3. 24 - 29 лет\n4. 30 - 39 лет\n5. Больше 40 лет"
            ),
            'telegram_question' => env(
                'BOT_DATA_COLLECTION_AGE_RANGE_TELEGRAM_QUESTION',
                'Укажите ваш возраст:'
            ),
            'max_question' => env(
                'BOT_DATA_COLLECTION_AGE_RANGE_MAX_QUESTION',
                'Укажите ваш возраст:'
            ),
            'retry_message' => env(
                'BOT_DATA_COLLECTION_AGE_RANGE_RETRY_MESSAGE',
                'Пожалуйста, выберите один из вариантов: 1, 2, 3, 4 или 5.'
            ),
            'skip_message' => env('BOT_DATA_COLLECTION_AGE_RANGE_SKIP_MESSAGE', 'Хорошо, возраст пропустим.'),
            'fallback_error_message' => env(
                'BOT_DATA_COLLECTION_AGE_RANGE_FALLBACK_ERROR_MESSAGE',
                'Пожалуйста, укажите возраст одним из вариантов: 1, 2, 3, 4 или 5.'
            ),
            'max_attempts' => (int) env('BOT_DATA_COLLECTION_AGE_RANGE_MAX_ATTEMPTS', 2),
            'skip_commands' => [
                'пропустить',
                'skip',
            ],
            'options' => [
                ['value' => 'under_18', 'label' => 'До 18 лет', 'aliases' => ['1']],
                ['value' => '18_23', 'label' => '18 - 23 года', 'aliases' => ['2']],
                ['value' => '24_29', 'label' => '24 - 29 лет', 'aliases' => ['3']],
                ['value' => '30_39', 'label' => '30 - 39 лет', 'aliases' => ['4']],
                ['value' => 'over_40', 'label' => 'Больше 40 лет', 'aliases' => ['5']],
            ],
        ],
    ],

    'phone_capture_confirmation_text' => env(
        'BOT_PHONE_CAPTURE_CONFIRMATION_TEXT',
        'Спасибо, номер получили.'
    ),

    'webhook_secret_length' => 40,

    'telegram' => [
        'webhook_secret_header' => 'X-Telegram-Bot-Api-Secret-Token',
        'allowed_updates' => [
            'message',
            'callback_query',
        ],
    ],

    'max' => [
        'webhook_secret_header' => 'X-Max-Bot-Api-Secret',
        'update_types' => [
            'message_created',
        ],
    ],
];
