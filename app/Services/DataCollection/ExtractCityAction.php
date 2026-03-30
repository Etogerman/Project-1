<?php

namespace App\Services\DataCollection;

use App\Services\AI\GeminiApiService;
use Illuminate\Support\Str;
use RuntimeException;

class ExtractCityAction
{
    public const DECISION_ACCEPT = 'accept';

    public const DECISION_RETRY = 'retry';

    public function __construct(
        protected GeminiApiService $geminiApiService,
    ) {}

    /**
     * @return array{decision: string, city: ?string}
     */
    public function handle(string $userReply, ?string $country = null): array
    {
        $response = $this->geminiApiService->generateStructured(
            $this->systemPrompt($country),
            $this->userPrompt($userReply),
            $this->schema(),
        );

        $decision = data_get($response, 'decision');

        if (! in_array($decision, [self::DECISION_ACCEPT, self::DECISION_RETRY], true)) {
            throw new RuntimeException('Gemini city extraction returned an invalid decision.');
        }

        if ($decision === self::DECISION_RETRY) {
            return [
                'decision' => self::DECISION_RETRY,
                'city' => null,
            ];
        }

        $city = $this->normalizeCity(data_get($response, 'city'));

        if ($city === null) {
            throw new RuntimeException('Gemini city extraction accepted the value but did not return a city.');
        }

        return [
            'decision' => self::DECISION_ACCEPT,
            'city' => $city,
        ];
    }

    protected function systemPrompt(?string $country): string
    {
        $prompt = <<<'TEXT'
Ты проверяешь ответ пользователя на вопрос "В каком городе вы находитесь?".

Верни только JSON по заданной схеме.

Правила:
- Если пользователь назвал город, верни decision="accept" и извлеки только название города в поле city.
- Если пользователь отказался, ушёл от ответа, задал встречный вопрос, написал мусор, цифры или фразу, не похожую на название города, верни decision="retry" и city=null.
- Не придумывай город, если его нет в ответе.
- Допустимо нормализовать название города до обычной записи.

Примеры:
- "Москва" -> {"decision":"accept","city":"Москва"}
- "Я из Берлина" -> {"decision":"accept","city":"Берлин"}
- "Алматы" -> {"decision":"accept","city":"Алматы"}
- "Не скажу" -> {"decision":"retry","city":null}
- "12345" -> {"decision":"retry","city":null}
TEXT;

        if (! filled($country)) {
            return $prompt;
        }

        return $prompt."\n\nКонтакт уже указал страну: {$country}. Принимай город только если он реалистично находится в этой стране. Если город не соответствует стране, верни decision=\"retry\" и city=null.";
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
            ],
            'required' => [
                'decision',
                'city',
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
}
