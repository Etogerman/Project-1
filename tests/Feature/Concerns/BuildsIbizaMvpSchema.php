<?php

namespace Tests\Feature\Concerns;

trait BuildsIbizaMvpSchema
{
    /**
     * @return array<string, mixed>
     */
    protected function ibizaMvpSchema(
        string $strongTagSlug,
        string $borderlineTagSlug,
        string $weakTagSlug,
    ): array {
        return [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_tg1',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_inst1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'text_format' => 'plain_text',
                    'next' => 'ask_name',
                ],
                'ask_name' => [
                    'type' => 'question',
                    'text' => 'Как вас зовут?',
                    'text_format' => 'plain_text',
                    'expects' => 'text',
                    'save_to' => 'run.first_name',
                    'next' => 'ask_dates',
                ],
                'ask_dates' => [
                    'type' => 'question',
                    'text' => 'Готовы ли вы участвовать в выездной VIP-группе на Ибице?',
                    'text_format' => 'plain_text',
                    'expects' => 'text',
                    'save_to' => 'run.dates_response',
                    'next' => 'ask_goal',
                ],
                'ask_goal' => [
                    'type' => 'question',
                    'text' => 'Какая у вас главная цель от поездки?',
                    'text_format' => 'plain_text',
                    'expects' => 'text',
                    'save_to' => 'run.primary_goal',
                    'next' => 'ask_commitment',
                ],
                'ask_commitment' => [
                    'type' => 'question',
                    'text' => 'Готовы ли вы включиться в программу полностью?',
                    'text_format' => 'plain_text',
                    'expects' => 'text',
                    'save_to' => 'run.commitment',
                    'next' => 'ask_budget',
                ],
                'ask_budget' => [
                    'type' => 'question',
                    'text' => 'Какой у вас ориентир по бюджету?',
                    'text_format' => 'plain_text',
                    'expects' => 'text',
                    'save_to' => 'run.budget_tier',
                    'next' => 'ask_departure_city',
                ],
                'ask_departure_city' => [
                    'type' => 'question',
                    'text' => 'Откуда вы планируете прилететь?',
                    'text_format' => 'plain_text',
                    'expects' => 'text',
                    'save_to' => 'run.departure_city',
                    'next' => 'evaluate_profile',
                ],
                'evaluate_profile' => [
                    'type' => 'condition',
                    'branches' => [
                        [
                            'if' => [
                                'all' => [
                                    [
                                        'var' => 'run.dates_response',
                                        'equals' => 'ready',
                                    ],
                                    [
                                        'var' => 'run.commitment',
                                        'equals' => 'full_commitment',
                                    ],
                                    [
                                        'var' => 'run.budget_tier',
                                        'in' => ['middle', 'high'],
                                    ],
                                ],
                            ],
                            'then' => 'capture_phone_strong',
                        ],
                        [
                            'if' => [
                                'all' => [
                                    [
                                        'var' => 'run.dates_response',
                                        'equals' => 'ready',
                                    ],
                                    [
                                        'var' => 'run.budget_tier',
                                        'equals' => 'low',
                                    ],
                                ],
                            ],
                            'then' => 'capture_phone_borderline',
                        ],
                        [
                            'default' => 'weak_outcome',
                        ],
                    ],
                ],
                'capture_phone_strong' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'text_format' => 'plain_text',
                    'next' => 'ask_instagram',
                ],
                'ask_instagram' => [
                    'type' => 'question',
                    'text' => 'Какой у вас Instagram?',
                    'text_format' => 'plain_text',
                    'expects' => 'text',
                    'save_to' => 'run.instagram_handle',
                    'next' => 'strong_outcome',
                ],
                'strong_outcome' => [
                    'type' => 'message',
                    'text' => 'Спасибо, вы подходите под VIP-формат.',
                    'text_format' => 'plain_text',
                    'actions' => [
                        [
                            'type' => 'set_tag',
                            'value' => $strongTagSlug,
                        ],
                    ],
                    'next' => 'done',
                ],
                'capture_phone_borderline' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'text_format' => 'plain_text',
                    'next' => 'borderline_outcome',
                ],
                'borderline_outcome' => [
                    'type' => 'message',
                    'text' => 'Спасибо, посмотрим формат полегче.',
                    'text_format' => 'plain_text',
                    'actions' => [
                        [
                            'type' => 'set_tag',
                            'value' => $borderlineTagSlug,
                        ],
                    ],
                    'next' => 'done',
                ],
                'weak_outcome' => [
                    'type' => 'message',
                    'text' => 'Спасибо! Пока предложим более мягкий формат участия.',
                    'text_format' => 'plain_text',
                    'actions' => [
                        [
                            'type' => 'set_tag',
                            'value' => $weakTagSlug,
                        ],
                    ],
                    'next' => 'done',
                ],
                'done' => [
                    'type' => 'complete',
                ],
            ],
        ];
    }
}
