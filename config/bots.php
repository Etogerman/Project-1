<?php

return [
    'data_collection' => [
        'enabled' => env('BOT_DATA_COLLECTION_ENABLED', true),
        'first_question' => env('BOT_DATA_COLLECTION_FIRST_QUESTION', 'Как вас зовут?'),
        'completion_message' => env('BOT_DATA_COLLECTION_COMPLETION_MESSAGE', 'Спасибо, имя сохранили.'),
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
