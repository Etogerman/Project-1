<?php

return [
    'default_auto_reply_text' => env(
        'BOT_AUTO_REPLY_TEXT',
        'Привет бот находится в разработке. Напишите нам чуть позже.'
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
