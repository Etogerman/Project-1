<?php

return [
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'temperature' => (float) env('GEMINI_TEMPERATURE', 0.2),
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 128),
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
        'country' => [
            'question' => env('BOT_DATA_COLLECTION_COUNTRY_QUESTION', 'В какой стране вы находитесь?'),
            'retry_message' => env('BOT_DATA_COLLECTION_COUNTRY_RETRY_MESSAGE', 'Подскажите, пожалуйста, страну. Например: Россия, Казахстан, Германия.'),
            'skip_message' => env('BOT_DATA_COLLECTION_COUNTRY_SKIP_MESSAGE', 'Хорошо, страну пока пропустим.'),
            'fallback_error_message' => env('BOT_DATA_COLLECTION_COUNTRY_FALLBACK_ERROR_MESSAGE', 'Не смогли распознать страну. Напишите, пожалуйста, только название страны.'),
            'max_attempts' => (int) env('BOT_DATA_COLLECTION_COUNTRY_MAX_ATTEMPTS', 2),
            'skip_commands' => [
                'пропустить',
                'skip',
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
        ],
    ],

    'max' => [
        'webhook_secret_header' => 'X-Max-Bot-Api-Secret',
        'update_types' => [
            'message_created',
        ],
    ],
];
