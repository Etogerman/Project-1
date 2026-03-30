<?php

namespace App\Services\DataCollection;

use App\Services\AI\GeminiApiService;
use Illuminate\Support\Str;
use RuntimeException;

class ExtractCountryAction
{
    public const DECISION_ACCEPT = 'accept';

    public const DECISION_RETRY = 'retry';

    public function __construct(
        protected GeminiApiService $geminiApiService,
    ) {}

    /**
     * @return array{decision: string, country: ?string}
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
            throw new RuntimeException('Gemini country extraction returned an invalid decision.');
        }

        if ($decision === self::DECISION_RETRY) {
            return [
                'decision' => self::DECISION_RETRY,
                'country' => null,
            ];
        }

        $country = $this->normalizeCountry(data_get($response, 'country'));

        if ($country === null) {
            throw new RuntimeException('Gemini country extraction accepted the value but did not return a country.');
        }

        return [
            'decision' => self::DECISION_ACCEPT,
            'country' => $country,
        ];
    }

    protected function systemPrompt(): string
    {
        return <<<'TEXT'
Ты проверяешь ответ пользователя на вопрос "В какой стране вы находитесь?".

Верни только JSON по заданной схеме.

Правила:
- Если пользователь назвал страну, верни decision="accept" и извлеки только название страны в поле country.
- Если пользователь отказался, ушёл от ответа, задал встречный вопрос, написал мусор, цифры или фразу, не похожую на название страны, верни decision="retry" и country=null.
- Не придумывай страну, если её нет в ответе.
- Допустимо нормализовать название страны до обычной записи.

Примеры:
- "Россия" -> {"decision":"accept","country":"Россия"}
- "Я из России" -> {"decision":"accept","country":"Россия"}
- "Казахстан" -> {"decision":"accept","country":"Казахстан"}
- "Не скажу" -> {"decision":"retry","country":null}
- "12345" -> {"decision":"retry","country":null}
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
                'country' => [
                    'type' => ['string', 'null'],
                ],
            ],
            'required' => [
                'decision',
                'country',
            ],
        ];
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
}
