<?php

namespace App\Services\DataCollection;

use App\Services\AI\GeminiApiService;
use Illuminate\Support\Str;
use ResourceBundle;
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
        $directCountry = $this->resolveDirectCountryMatch($userReply);

        if ($directCountry !== null) {
            return [
                'decision' => self::DECISION_ACCEPT,
                'country' => $directCountry,
            ];
        }

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
- Если пользователь написал ровно название страны, даже редкой или неевропейской, это валидный ответ.
- Если пользователь отказался, ушёл от ответа, задал встречный вопрос, написал мусор, цифры или фразу, не похожую на название страны, верни decision="retry" и country=null.
- Не придумывай страну, если её нет в ответе.
- Допустимо нормализовать название страны до обычной записи.

Примеры:
- "Россия" -> {"decision":"accept","country":"Россия"}
- "Я из России" -> {"decision":"accept","country":"Россия"}
- "Казахстан" -> {"decision":"accept","country":"Казахстан"}
- "Мозамбик" -> {"decision":"accept","country":"Мозамбик"}
- "Я из Мозамбика" -> {"decision":"accept","country":"Мозамбик"}
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

        $limited = Str::limit($normalized, 255, '');

        return $this->resolveDirectCountryMatch($limited) ?? $limited;
    }

    protected function resolveDirectCountryMatch(string $value): ?string
    {
        $normalized = $this->normalizeLookupKey($value);

        if ($normalized === null) {
            return null;
        }

        return $this->countryLookup()[$normalized] ?? null;
    }

    protected function normalizeLookupKey(string $value): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value, " \t\n\r\0\x0B.,!?;:\"'«»()[]{}"));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        return mb_strtolower($normalized);
    }

    /**
     * @return array<string, string>
     */
    protected function countryLookup(): array
    {
        static $lookup;

        if (is_array($lookup)) {
            return $lookup;
        }

        $lookup = [];
        $canonicalNames = $this->canonicalCountryNames();

        foreach (['ru', 'en'] as $locale) {
            $bundle = ResourceBundle::create($locale, 'ICUDATA-region');
            $countries = $bundle instanceof ResourceBundle ? $bundle->get('Countries') : null;

            if (! $countries instanceof ResourceBundle) {
                continue;
            }

            foreach ($countries as $code => $name) {
                if (! is_string($code) || ! preg_match('/^[A-Z]{2}$/', $code) || ! is_string($name) || $name === '') {
                    continue;
                }

                $normalizedName = $this->normalizeLookupKey($name);

                if ($normalizedName === null) {
                    continue;
                }

                $canonicalName = $canonicalNames[$code] ?? $name;

                $lookup[$normalizedName] = Str::limit(trim($canonicalName), 255, '');
            }
        }

        foreach ($this->countryAliases() as $canonicalName => $aliases) {
            foreach ($aliases as $alias) {
                $normalizedName = $this->normalizeLookupKey($alias);

                if ($normalizedName !== null) {
                    $lookup[$normalizedName] = $canonicalName;
                }
            }
        }

        return $lookup;
    }

    /**
     * @return array<string, string>
     */
    protected function canonicalCountryNames(): array
    {
        $bundle = ResourceBundle::create('ru', 'ICUDATA-region');
        $countries = $bundle instanceof ResourceBundle ? $bundle->get('Countries') : null;

        if (! $countries instanceof ResourceBundle) {
            return [];
        }

        $names = [];

        foreach ($countries as $code => $name) {
            if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code) && is_string($name) && trim($name) !== '') {
                $names[$code] = Str::limit(trim($name), 255, '');
            }
        }

        return $names;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function countryAliases(): array
    {
        return [
            'Венгрия' => ['Венгрия'],
            'Казахстан' => ['Казахстан'],
            'Кения' => ['Кения'],
            'Мозамбик' => ['Мозамбик', 'Mozambique'],
            'Россия' => ['Россия', 'Российская Федерация', 'РФ'],
        ];
    }
}
