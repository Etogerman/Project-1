<?php

return [
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
