<?php

return [
    'auto_reply_queue' => env('BOT_AUTO_REPLY_QUEUE', 'bot-replies'),
    'scenario_queue' => env('BOT_SCENARIO_QUEUE', env('BOT_AUTO_REPLY_QUEUE', 'bot-replies')),

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
        'profile_collection_engine' => env('BOT_PROFILE_COLLECTION_ENGINE', 'legacy_collector'),
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
        'russian_region' => [
            'allowed_regions' => [
                'Республика Адыгея',
                'Республика Алтай',
                'Республика Башкортостан',
                'Республика Бурятия',
                'Республика Дагестан',
                'Республика Ингушетия',
                'Кабардино-Балкарская Республика',
                'Республика Калмыкия',
                'Карачаево-Черкесская Республика',
                'Республика Карелия',
                'Республика Коми',
                'Республика Крым',
                'Республика Марий Эл',
                'Республика Мордовия',
                'Республика Саха (Якутия)',
                'Республика Северная Осетия — Алания',
                'Республика Татарстан',
                'Республика Тыва',
                'Удмуртская Республика',
                'Республика Хакасия',
                'Чеченская Республика',
                'Чувашская Республика',
                'Алтайский край',
                'Забайкальский край',
                'Камчатский край',
                'Краснодарский край',
                'Красноярский край',
                'Пермский край',
                'Приморский край',
                'Ставропольский край',
                'Хабаровский край',
                'Амурская область',
                'Архангельская область',
                'Астраханская область',
                'Белгородская область',
                'Брянская область',
                'Владимирская область',
                'Волгоградская область',
                'Вологодская область',
                'Воронежская область',
                'Ивановская область',
                'Иркутская область',
                'Калининградская область',
                'Калужская область',
                'Кемеровская область',
                'Кировская область',
                'Костромская область',
                'Курганская область',
                'Курская область',
                'Ленинградская область',
                'Липецкая область',
                'Магаданская область',
                'Московская область',
                'Мурманская область',
                'Нижегородская область',
                'Новгородская область',
                'Новосибирская область',
                'Омская область',
                'Оренбургская область',
                'Орловская область',
                'Пензенская область',
                'Псковская область',
                'Ростовская область',
                'Рязанская область',
                'Самарская область',
                'Саратовская область',
                'Сахалинская область',
                'Свердловская область',
                'Смоленская область',
                'Тамбовская область',
                'Тверская область',
                'Томская область',
                'Тульская область',
                'Тюменская область',
                'Ульяновская область',
                'Челябинская область',
                'Ярославская область',
                'Москва',
                'Санкт-Петербург',
                'Севастополь',
                'Еврейская автономная область',
                'Ненецкий автономный округ',
                'Ханты-Мансийский автономный округ — Югра',
                'Чукотский автономный округ',
                'Ямало-Ненецкий автономный округ',
            ],
        ],
        'russian_region_confirm' => [
            'question' => env('BOT_DATA_COLLECTION_RUSSIAN_REGION_CONFIRM_QUESTION', 'Уточните ваш регион:'),
            'retry_message' => env('BOT_DATA_COLLECTION_RUSSIAN_REGION_CONFIRM_RETRY_MESSAGE', 'Уточните ваш регион:'),
            'question_candidate_buttons' => env(
                'BOT_DATA_COLLECTION_RUSSIAN_REGION_CONFIRM_QUESTION_CANDIDATE_BUTTONS',
                env('BOT_DATA_COLLECTION_RUSSIAN_REGION_CONFIRM_QUESTION', 'Уточните, пожалуйста, ваш регион проживания.')
            ),
            'retry_candidate_buttons' => env(
                'BOT_DATA_COLLECTION_RUSSIAN_REGION_CONFIRM_RETRY_CANDIDATE_BUTTONS',
                env('BOT_DATA_COLLECTION_RUSSIAN_REGION_CONFIRM_RETRY_MESSAGE', 'Уточните, пожалуйста, ваш регион проживания.')
            ),
            'question_free_text' => env(
                'BOT_DATA_COLLECTION_RUSSIAN_REGION_CONFIRM_QUESTION_FREE_TEXT',
                'Уточните, пожалуйста, регион проживания. В какой области, крае или республике находится ваш город?'
            ),
            'retry_free_text' => env(
                'BOT_DATA_COLLECTION_RUSSIAN_REGION_CONFIRM_RETRY_FREE_TEXT',
                'Уточните, пожалуйста, регион проживания. В какой области, крае или республике находится ваш город?'
            ),
            'fallback_to_city_message' => env(
                'BOT_DATA_COLLECTION_RUSSIAN_REGION_CONFIRM_FALLBACK_TO_CITY_MESSAGE',
                'Не смогли точно определить регион. Уточните, пожалуйста, город проживания ещё раз.'
            ),
            'skip_message' => env('BOT_DATA_COLLECTION_RUSSIAN_REGION_CONFIRM_SKIP_MESSAGE', 'Хорошо, регион пропустим.'),
            'skip_button_label' => env('BOT_DATA_COLLECTION_RUSSIAN_REGION_CONFIRM_SKIP_BUTTON_LABEL', 'Пропустить'),
            'max_attempts' => (int) env('BOT_DATA_COLLECTION_RUSSIAN_REGION_CONFIRM_MAX_ATTEMPTS', 2),
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

    'phone_capture_recognition_full_profile_text' => env(
        'BOT_PHONE_CAPTURE_RECOGNITION_FULL_PROFILE_TEXT',
        'Спасибо! Мы вас узнали, {name}.'
    ),

    'phone_capture_recognition_continue_text' => env(
        'BOT_PHONE_CAPTURE_RECOGNITION_CONTINUE_TEXT',
        'Спасибо! Мы вас узнали, {name}. У нас осталось несколько вопросов.'
    ),

    'scenarios' => [
        'warmup' => [
            'telegram' => [
                'text' => env(
                    'BOT_WARMUP_TELEGRAM_TEXT',
                    'Прежде чем перейти дальше, подскажите, вам интересно получить несколько коротких материалов?'
                ),
                'buttons' => [
                    'positive' => env('BOT_WARMUP_TELEGRAM_BUTTON_POSITIVE', 'Да, интересно'),
                    'later' => env('BOT_WARMUP_TELEGRAM_BUTTON_LATER', 'Позже'),
                    'decline' => env('BOT_WARMUP_TELEGRAM_BUTTON_DECLINE', 'Не интересно'),
                ],
            ],
            'max' => [
                'text' => env(
                    'BOT_WARMUP_MAX_TEXT',
                    'Прежде чем перейти дальше, подскажите, вам интересно получить несколько коротких материалов?'
                ),
                'buttons' => [
                    'positive' => env('BOT_WARMUP_MAX_BUTTON_POSITIVE', 'Да, интересно'),
                    'later' => env('BOT_WARMUP_MAX_BUTTON_LATER', 'Позже'),
                    'decline' => env('BOT_WARMUP_MAX_BUTTON_DECLINE', 'Не интересно'),
                ],
            ],
        ],
        'needs_discovery' => [
            'primary_goal' => [
                'question' => env(
                    'BOT_NEEDS_DISCOVERY_PRIMARY_GOAL_QUESTION',
                    'Какая задача для вас сейчас самая важная?'
                ),
            ],
            'main_blocker' => [
                'question' => env(
                    'BOT_NEEDS_DISCOVERY_MAIN_BLOCKER_QUESTION',
                    'Что мешает решить её быстрее или проще?'
                ),
            ],
            'completion_message' => env(
                'BOT_NEEDS_DISCOVERY_COMPLETION_MESSAGE',
                'Спасибо, записали.'
            ),
            'skip_commands' => [
                'пропустить',
                'skip',
            ],
        ],
    ],

    'webhook_secret_length' => 40,

    'rate_limit' => [
        'telegram' => [
            'max_per_minute' => (int) env('BOT_TELEGRAM_WEBHOOK_MAX_PER_MINUTE', 300),
        ],
        'max' => [
            'max_per_minute' => (int) env('BOT_MAX_WEBHOOK_MAX_PER_MINUTE', 300),
        ],
    ],

    'telegram' => [
        'webhook_secret_header' => 'X-Telegram-Bot-Api-Secret-Token',
        'webhook_ip_address' => env('TELEGRAM_WEBHOOK_IP_ADDRESS'),
        'allowed_updates' => [
            'message',
            'callback_query',
            'my_chat_member',
        ],
    ],

    'telegram_account' => [
        'gateway_shared_secret' => env('TELEGRAM_ACCOUNT_GATEWAY_SHARED_SECRET'),
        'gateway_rate_limit_per_minute' => (int) env('TELEGRAM_ACCOUNT_GATEWAY_RATE_LIMIT_PER_MINUTE', 120),
    ],

    'max' => [
        'webhook_secret_header' => 'X-Max-Bot-Api-Secret',
        'delayed_webhook_threshold_seconds' => (int) env('BOT_MAX_DELAYED_WEBHOOK_THRESHOLD_SECONDS', 60),
        'trusted_avatar_hosts' => [
            'max.ru',
            'oneme.ru',
        ],
        'update_types' => [
            'message_created',
            'bot_started',
        ],
    ],
];
