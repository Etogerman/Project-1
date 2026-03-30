<?php

namespace App\Services\DataCollection;

use App\Services\AI\GeminiApiService;
use Illuminate\Support\Str;
use RuntimeException;

class ExtractResidenceCityAction
{
    public const DECISION_ACCEPT = 'accept';

    public const DECISION_RETRY = 'retry';

    public const COUNTRY_CONFIDENCE_HIGH = 'high';

    public const COUNTRY_CONFIDENCE_LOW = 'low';

    public function __construct(
        protected GeminiApiService $geminiApiService,
    ) {}

    /**
     * @return array{decision: string, city: ?string, country: ?string, country_confidence: ?string}
     */
    public function handle(string $userReply): array
    {
        $response = $this->geminiApiService->generateStructured(
            $this->systemPrompt(),
            $this->userPrompt($userReply),
            $this->schema(),
        );

        $decision = data_get($response, 'decision');

        if (! in_array($decision, [self::DECISION_ACCEPT, self::DECISION_RETRY], true)) {
            throw new RuntimeException('Gemini residence city extraction returned an invalid decision.');
        }

        if ($decision === self::DECISION_RETRY) {
            return [
                'decision' => self::DECISION_RETRY,
                'city' => null,
                'country' => null,
                'country_confidence' => null,
            ];
        }

        $city = $this->normalizeCity(data_get($response, 'city'));

        if ($city === null) {
            throw new RuntimeException('Gemini residence city extraction accepted the value but did not return a city.');
        }

        $countryConfidence = $this->normalizeCountryConfidence(data_get($response, 'country_confidence'));
        $country = $this->normalizeCountry(data_get($response, 'country'));

        if ($countryConfidence === self::COUNTRY_CONFIDENCE_HIGH && $country === null) {
            throw new RuntimeException('Gemini residence city extraction marked country confidence as high but did not return a country.');
        }

        if ($countryConfidence === self::COUNTRY_CONFIDENCE_LOW) {
            $country = null;
        }

        return [
            'decision' => self::DECISION_ACCEPT,
            'city' => $city,
            'country' => $country,
            'country_confidence' => $countryConfidence,
        ];
    }

    protected function systemPrompt(): string
    {
        return <<<'TEXT'
Ты проверяешь ответ пользователя на вопрос "В каком городе вы живёте?".

Верни только JSON по заданной схеме.

Правила:
- Если в ответе есть город проживания, верни decision="accept" и извлеки город в поле city.
- Если по городу можно уверенно определить страну, верни её в поле country и поставь country_confidence="high".
- Если город распознан, но страна по нему неоднозначна или неочевидна, верни decision="accept", city="<город>", country=null и country_confidence="low".
- Если пользователь назвал и город, и страну, извлеки оба значения и поставь country_confidence="high", если они согласованы.
- Если ответ не содержит города, содержит отказ, мусор, цифры или не похож на город проживания, верни decision="retry", city=null, country=null, country_confidence=null.
- Не придумывай страну при низкой уверенности.
- Допустимо нормализовать названия города и страны до обычной записи.

Примеры:
- "Будапешт" -> {"decision":"accept","city":"Будапешт","country":"Венгрия","country_confidence":"high"}
- "Живу в Найроби" -> {"decision":"accept","city":"Найроби","country":"Кения","country_confidence":"high"}
- "Будапешт, Венгрия" -> {"decision":"accept","city":"Будапешт","country":"Венгрия","country_confidence":"high"}
- "Сан-Хосе" -> {"decision":"accept","city":"Сан-Хосе","country":null,"country_confidence":"low"}
- "Не скажу" -> {"decision":"retry","city":null,"country":null,"country_confidence":null}
- "12345" -> {"decision":"retry","city":null,"country":null,"country_confidence":null}
TEXT;
    }

    protected function userPrompt(string $userReply): string
    {
        return "Ответ пользователя:\n".$userReply;
    }

    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'decision' => [
                    'type' => 'string',
                    'enum' => [
                        self::DECISION_ACCEPT,
                        self::DECISION_RETRY,
                    ],
                ],
                'city' => [
                    'type' => ['string', 'null'],
                ],
                'country' => [
                    'type' => ['string', 'null'],
                ],
                'country_confidence' => [
                    'type' => ['string', 'null'],
                ],
            ],
            'required' => [
                'decision',
                'city',
                'country',
                'country_confidence',
            ],
        ];
    }

    protected function normalizeCity(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        return Str::limit($normalized, 255, '');
    }

    protected function normalizeCountry(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        return Str::limit($normalized, 255, '');
    }

    protected function normalizeCountryConfidence(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match (trim($value)) {
            self::COUNTRY_CONFIDENCE_HIGH => self::COUNTRY_CONFIDENCE_HIGH,
            self::COUNTRY_CONFIDENCE_LOW => self::COUNTRY_CONFIDENCE_LOW,
            default => throw new RuntimeException('Gemini residence city extraction returned an invalid country confidence.'),
        };
    }
}
