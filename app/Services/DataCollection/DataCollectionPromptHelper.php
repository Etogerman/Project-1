<?php

namespace App\Services\DataCollection;

use App\Models\Channel;
use App\Models\Contact;

class DataCollectionPromptHelper
{
    public const RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS = 'candidate_buttons';

    public const RUSSIAN_REGION_CONFIRM_MODE_FREE_TEXT = 'free_text_region';

    /**
     * @param  mixed  $default
     */
    public function fieldConfig(?string $field, string $key, mixed $default = null): mixed
    {
        if (! is_string($field) || $field === '') {
            return $default;
        }

        return config("bots.data_collection.{$field}.{$key}", $default);
    }

    public function questionText(string $field, ?string $platform = null): string
    {
        return match ($field) {
            Contact::DATA_COLLECTION_FIELD_FIRST_NAME => (string) config(
                'bots.data_collection.first_name.question',
                config('bots.data_collection.first_question', 'Как вас зовут?')
            ),
            Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY => (string) config(
                'bots.data_collection.residence_city.question',
                'В каком городе вы живёте?'
            ),
            Contact::DATA_COLLECTION_FIELD_COUNTRY => (string) config(
                'bots.data_collection.country.question',
                'В какой стране вы живёте?'
            ),
            Contact::DATA_COLLECTION_FIELD_CITY => (string) config(
                'bots.data_collection.city.question',
                'В каком городе вы живёте?'
            ),
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE => (string) config(
                match ($platform) {
                    Channel::PLATFORM_TELEGRAM => 'bots.data_collection.age_range.telegram_question',
                    Channel::PLATFORM_MAX => 'bots.data_collection.age_range.max_question',
                    default => 'bots.data_collection.age_range.question',
                },
                match ($platform) {
                    Channel::PLATFORM_TELEGRAM, Channel::PLATFORM_MAX => 'Укажите ваш возраст:',
                    default => "Укажите ваш возраст:\n1. До 18 лет\n2. 18 - 23 года\n3. 24 - 29 лет\n4. 30 - 39 лет\n5. Больше 40 лет",
                }
            ),
            default => '',
        };
    }

    public function retryMessage(?string $field): string
    {
        return (string) $this->fieldConfig($field, 'retry_message', 'Подскажите, пожалуйста, как к вам обращаться? Можно только имя.');
    }

    public function skipMessage(?string $field): string
    {
        return (string) $this->fieldConfig($field, 'skip_message', 'Хорошо, имя пока пропустим.');
    }

    public function fallbackErrorMessage(?string $field): string
    {
        return (string) $this->fieldConfig($field, 'fallback_error_message', 'Не смогли распознать значение. Повторите ответ, пожалуйста.');
    }

    public function completionMessage(): string
    {
        return (string) config('bots.data_collection.completion_message', 'Спасибо, данные сохранили.');
    }

    public function countryCityMismatchMessage(?string $city, string $country): string
    {
        $template = (string) config(
            'bots.data_collection.country.city_mismatch_message',
            'Похоже, город «{city}» не относится к стране «{country}». Подскажите, пожалуйста, страну, где вы живёте.'
        );

        return str_replace(
            ['{city}', '{country}'],
            [$city ?: 'указанный город', $country],
            $template,
        );
    }

    public function countryQuestionAfterResidenceCity(string $city): string
    {
        $template = (string) config(
            'bots.data_collection.country.after_residence_city_question',
            'Подскажите, пожалуйста, страну, где вы живёте. Для города «{city}» это нужно уточнить.'
        );

        return str_replace('{city}', $city, $template);
    }

    public function resolveAgeRangeValue(string $replyText): ?string
    {
        $normalizedReply = $this->normalizeAgeRangeInput($replyText);

        if ($normalizedReply === '') {
            return null;
        }

        $options = config('bots.data_collection.age_range.options', []);

        if (! is_array($options)) {
            return null;
        }

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $value = (string) ($option['value'] ?? '');

            if ($value === '') {
                continue;
            }

            $candidates = [
                $value,
                (string) ($option['label'] ?? ''),
            ];

            foreach ((array) ($option['aliases'] ?? []) as $alias) {
                if (is_scalar($alias)) {
                    $candidates[] = (string) $alias;
                }
            }

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && $normalizedReply === $this->normalizeAgeRangeInput($candidate)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @return string|'skip'|null
     */
    public function resolveRussianRegionConfirmInput(Contact $contact, string $replyText): string|null
    {
        $mode = $this->russianRegionConfirmMode($contact);
        $candidates = $this->russianRegionCandidates($contact);

        if ($mode === null || $candidates === []) {
            return null;
        }

        $callbackValue = $this->normalizeRussianRegionConfirmCallbackValue($replyText);

        if ($callbackValue === 'skip') {
            return 'skip';
        }

        if ($mode === self::RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS && $callbackValue !== null && ctype_digit($callbackValue)) {
            $candidate = $this->candidateByOneBasedIndex($candidates, (int) $callbackValue);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        $normalizedReply = $this->normalizeAgeRangeInput($replyText);

        if ($normalizedReply === '') {
            return null;
        }

        foreach ($candidates as $index => $candidate) {
            if ($mode === self::RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS && $normalizedReply === (string) ($index + 1)) {
                return $candidate;
            }

            if ($normalizedReply === $this->normalizeAgeRangeInput($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function telegramReplyMarkupForField(?string $field, ?Contact $contact = null): ?array
    {
        $keyboard = match ($field) {
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE => $this->telegramAgeRangeInlineKeyboard(),
            Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM => $contact instanceof Contact
                ? $this->telegramRussianRegionConfirmInlineKeyboard($contact)
                : null,
            default => null,
        };

        if ($keyboard === null) {
            return null;
        }

        return [
            'inline_keyboard' => $keyboard,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function maxAttachmentsForField(?string $field, ?Contact $contact = null): ?array
    {
        return match ($field) {
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE => $this->maxAgeRangeAttachments(),
            Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM => $contact instanceof Contact
                ? $this->maxRussianRegionConfirmAttachments($contact)
                : null,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    public function telegramReplyMarkupForCompletion(Contact $contact): ?array
    {
        return $contact->data_collection_current_field === Contact::DATA_COLLECTION_FIELD_AGE_RANGE
            ? ['remove_keyboard' => true]
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function telegramReplyMarkupForTerminalSkip(?string $field): ?array
    {
        return $field === Contact::DATA_COLLECTION_FIELD_AGE_RANGE
            ? ['remove_keyboard' => true]
            : null;
    }

    public function shouldAskRussianRegionConfirmation(Contact $contact): bool
    {
        return ! filled($contact->region)
            && $this->russianRegionConfirmMode($contact) !== null
            && in_array($contact->region_status, [
                Contact::REGION_STATUS_CLARIFICATION_PENDING,
                Contact::REGION_STATUS_AMBIGUOUS,
            ], true);
    }

    /**
     * @return list<string>
     */
    public function russianRegionCandidates(Contact $contact): array
    {
        $candidates = $contact->pending_region_candidates;

        if (! is_array($candidates)) {
            return [];
        }

        $normalized = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);
            $key = $this->normalizeComparableText($trimmed);

            if ($key === '' || array_key_exists($key, $normalized)) {
                continue;
            }

            $normalized[$key] = $trimmed;
        }

        $values = array_values($normalized);

        usort($values, fn (string $left, string $right): int => strnatcasecmp(
            $this->normalizeComparableText($left),
            $this->normalizeComparableText($right),
        ));

        return $values;
    }

    public function russianRegionConfirmQuestionText(Contact $contact): string
    {
        return match ($this->russianRegionConfirmMode($contact)) {
            self::RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS => (string) config(
                'bots.data_collection.russian_region_confirm.question_candidate_buttons',
                config('bots.data_collection.russian_region_confirm.question', 'Уточните, пожалуйста, ваш регион проживания.')
            ),
            self::RUSSIAN_REGION_CONFIRM_MODE_FREE_TEXT => (string) config(
                'bots.data_collection.russian_region_confirm.question_free_text',
                'Уточните, пожалуйста, регион проживания. В какой области, крае или республике находится ваш город?'
            ),
            default => (string) config('bots.data_collection.russian_region_confirm.question', 'Уточните, пожалуйста, ваш регион проживания.'),
        };
    }

    public function russianRegionConfirmRetryText(Contact $contact): string
    {
        return match ($this->russianRegionConfirmMode($contact)) {
            self::RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS => (string) config(
                'bots.data_collection.russian_region_confirm.retry_candidate_buttons',
                config('bots.data_collection.russian_region_confirm.retry_message', 'Уточните, пожалуйста, ваш регион проживания.')
            ),
            self::RUSSIAN_REGION_CONFIRM_MODE_FREE_TEXT => (string) config(
                'bots.data_collection.russian_region_confirm.retry_free_text',
                'Уточните, пожалуйста, регион проживания. В какой области, крае или республике находится ваш город?'
            ),
            default => (string) config('bots.data_collection.russian_region_confirm.retry_message', 'Уточните, пожалуйста, ваш регион проживания.'),
        };
    }

    public function russianRegionFallbackToCityMessage(): string
    {
        return (string) config(
            'bots.data_collection.russian_region_confirm.fallback_to_city_message',
            'Не смогли точно определить регион. Уточните, пожалуйста, город проживания ещё раз.'
        );
    }

    public function russianRegionConfirmMode(Contact $contact): ?string
    {
        $candidateCount = count($this->russianRegionCandidates($contact));

        if ($candidateCount >= 2 && $candidateCount <= 4) {
            return self::RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS;
        }

        if ($candidateCount >= 5) {
            return self::RUSSIAN_REGION_CONFIRM_MODE_FREE_TEXT;
        }

        return null;
    }

    public function normalizeComparableText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace('ё', 'е', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    protected function normalizeAgeRangeInput(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace('ё', 'е', $normalized);
        $normalized = preg_replace('/[–—−]/u', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*-\s*/u', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    protected function normalizeRussianRegionConfirmCallbackValue(string $replyText): ?string
    {
        $normalized = trim($replyText);

        if (! str_starts_with($normalized, 'russian_region_confirm:')) {
            return null;
        }

        $value = trim(substr($normalized, strlen('russian_region_confirm:')));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  list<string>  $candidates
     */
    protected function candidateByOneBasedIndex(array $candidates, int $index): ?string
    {
        if ($index < 1) {
            return null;
        }

        $position = $index - 1;

        return $candidates[$position] ?? null;
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>|null
     */
    private function telegramAgeRangeInlineKeyboard(): ?array
    {
        $optionsByValue = [];

        foreach ((array) config('bots.data_collection.age_range.options', []) as $option) {
            if (! is_array($option) || ! filled($option['label'] ?? null) || ! filled($option['value'] ?? null)) {
                continue;
            }

            $optionsByValue[(string) $option['value']] = (string) $option['label'];
        }

        $rows = [
            ['under_18', '18_23'],
            ['24_29', '30_39'],
            ['over_40'],
        ];

        $keyboard = [];

        foreach ($rows as $rowValues) {
            $row = [];

            foreach ($rowValues as $value) {
                $label = $optionsByValue[$value] ?? null;

                if (! filled($label)) {
                    continue;
                }

                $row[] = [
                    'text' => $label,
                    'callback_data' => 'age_range:'.$value,
                ];
            }

            if ($row !== []) {
                $keyboard[] = $row;
            }
        }

        return $keyboard !== [] ? $keyboard : null;
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>|null
     */
    private function telegramRussianRegionConfirmInlineKeyboard(Contact $contact): ?array
    {
        if ($this->russianRegionConfirmMode($contact) !== self::RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS) {
            return null;
        }

        $candidates = $this->russianRegionCandidates($contact);

        $keyboard = [];

        foreach ($candidates as $index => $candidate) {
            $keyboard[] = [[
                'text' => $candidate,
                'callback_data' => 'russian_region_confirm:'.($index + 1),
            ]];
        }

        $keyboard[] = [[
            'text' => (string) config('bots.data_collection.russian_region_confirm.skip_button_label', 'Пропустить'),
            'callback_data' => 'russian_region_confirm:skip',
        ]];

        return $keyboard;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function maxAgeRangeAttachments(): ?array
    {
        $optionsByValue = [];

        foreach ((array) config('bots.data_collection.age_range.options', []) as $option) {
            if (! is_array($option) || ! filled($option['label'] ?? null) || ! filled($option['value'] ?? null)) {
                continue;
            }

            $optionsByValue[(string) $option['value']] = (string) $option['label'];
        }

        $rows = [
            ['under_18', '18_23'],
            ['24_29', '30_39'],
            ['over_40'],
        ];

        $buttons = [];

        foreach ($rows as $rowValues) {
            $row = [];

            foreach ($rowValues as $value) {
                $label = $optionsByValue[$value] ?? null;

                if (! filled($label)) {
                    continue;
                }

                $row[] = [
                    'type' => 'message',
                    'text' => $label,
                ];
            }

            if ($row !== []) {
                $buttons[] = $row;
            }
        }

        if ($buttons === []) {
            return null;
        }

        return [[
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => $buttons,
            ],
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function maxRussianRegionConfirmAttachments(Contact $contact): ?array
    {
        if ($this->russianRegionConfirmMode($contact) !== self::RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS) {
            return null;
        }

        $candidates = $this->russianRegionCandidates($contact);

        $buttons = [];

        foreach ($candidates as $candidate) {
            $buttons[] = [[
                'type' => 'message',
                'text' => $candidate,
            ]];
        }

        $buttons[] = [[
            'type' => 'message',
            'text' => (string) config('bots.data_collection.russian_region_confirm.skip_button_label', 'Пропустить'),
        ]];

        return [[
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => $buttons,
            ],
        ]];
    }
}
