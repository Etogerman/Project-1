<?php

namespace App\Services\DataCollection;

use App\Services\AI\GeminiApiService;
use Locale;
use ResourceBundle;
use Illuminate\Support\Str;
use RuntimeException;

class ExtractFirstNameAction
{
    public const DECISION_ACCEPT = 'accept';

    public const DECISION_RETRY = 'retry';

    public function __construct(
        protected GeminiApiService $geminiApiService,
    ) {}

    /**
     * @return array{decision: string, first_name: ?string}
     */
    public function handle(string $userReply, ?string $messengerName = null): array
    {
        $directFirstName = $this->resolveDirectFirstNameMatch($userReply);

        if ($directFirstName !== null) {
            return [
                'decision' => self::DECISION_ACCEPT,
                'first_name' => $directFirstName,
            ];
        }

        $response = $this->geminiApiService->generateStructured(
            $this->systemPrompt($messengerName),
            $this->userPrompt($userReply, $messengerName),
            $this->schema(),
        );

        $decision = data_get($response, 'decision');

        if (! in_array($decision, [self::DECISION_ACCEPT, self::DECISION_RETRY], true)) {
            throw new RuntimeException('Gemini first-name extraction returned an invalid decision.');
        }

        if ($decision === self::DECISION_RETRY) {
            return [
                'decision' => self::DECISION_RETRY,
                'first_name' => null,
            ];
        }

        $firstName = $this->normalizeFirstName(data_get($response, 'first_name'));

        if ($firstName === null) {
            throw new RuntimeException('Gemini first-name extraction accepted the value but did not return a name.');
        }

        return [
            'decision' => self::DECISION_ACCEPT,
            'first_name' => $firstName,
        ];
    }

    protected function systemPrompt(?string $messengerName): string
    {
        $prompt = <<<'TEXT'
Ты проверяешь ответ пользователя на вопрос "Как вас зовут?".

Верни только JSON по заданной схеме.

Правила:
- Если пользователь написал ровно одно обычное человеческое имя, даже без фразы "меня зовут", это валидный ответ.
- Если пользователь действительно назвал имя, верни decision="accept" и извлеки только имя в поле first_name.
- Если пользователь отказался, ушёл от ответа, задал встречный вопрос, написал мусор, цифры или фразу, не похожую на имя, верни decision="retry" и first_name=null.
- Не придумывай имя, если его нет в ответе.
- Не используй имя из профиля мессенджера как замену ответу пользователя.
- Допустимо нормализовать имя: убрать лишние слова, пробелы и привести к обычной записи.

Примеры:
- "Герман" -> {"decision":"accept","first_name":"Герман"}
- "Николай" -> {"decision":"accept","first_name":"Николай"}
- "Коля" -> {"decision":"accept","first_name":"Коля"}
- "николай" -> {"decision":"accept","first_name":"Николай"}
- "Меня зовут Герман" -> {"decision":"accept","first_name":"Герман"}
- "Не скажу как меня зовут" -> {"decision":"retry","first_name":null}
- "А зачем вам это?" -> {"decision":"retry","first_name":null}
- "12345" -> {"decision":"retry","first_name":null}
TEXT;

        if (! filled($messengerName)) {
            return $prompt;
        }

        return $prompt."\n\nИмя из профиля мессенджера: ".$messengerName;
    }

    protected function userPrompt(string $userReply, ?string $messengerName): string
    {
        $prompt = "Ответ пользователя:\n".$userReply;

        if (! filled($messengerName)) {
            return $prompt;
        }

        return $prompt."\n\nИмя из профиля мессенджера (только как дополнительный контекст, не как источник истины): ".$messengerName;
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
                'first_name' => [
                    'type' => ['string', 'null'],
                ],
            ],
            'required' => [
                'decision',
                'first_name',
            ],
        ];
    }

    protected function normalizeFirstName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        return $this->normalizeNameToken($normalized);
    }

    protected function resolveDirectFirstNameMatch(string $value): ?string
    {
        $candidate = $this->normalizeDirectCandidate($value);

        if ($candidate === null) {
            return null;
        }

        if ($this->isStopWord($candidate) || $this->resolveDirectCountryMatch($candidate) !== null) {
            return null;
        }

        return $this->normalizeNameToken($candidate);
    }

    protected function normalizeDirectCandidate(string $value): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value, " \t\n\r\0\x0B.,!?;:\"«»()[]{}"));

        if (! is_string($normalized) || $normalized === '' || str_contains($normalized, ' ')) {
            return null;
        }

        if (mb_strlen($normalized) < 2) {
            return null;
        }

        if (! preg_match("/^[\\p{L}\\p{M}]+(?:[-'][\\p{L}\\p{M}]+)*$/u", $normalized)) {
            return null;
        }

        return $normalized;
    }

    protected function normalizeNameToken(string $value): string
    {
        $segments = preg_split("/([-'])/u", mb_strtolower($value), -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($segments)) {
            return Str::limit(trim($value), 255, '');
        }

        $normalized = '';

        foreach ($segments as $segment) {
            if ($segment === '-' || $segment === "'") {
                $normalized .= $segment;

                continue;
            }

            $normalized .= mb_convert_case($segment, MB_CASE_TITLE, 'UTF-8');
        }

        return Str::limit($normalized, 255, '');
    }

    protected function isStopWord(string $value): bool
    {
        return in_array(mb_strtolower($value), $this->stopWords(), true);
    }

    /**
     * @return list<string>
     */
    protected function stopWords(): array
    {
        return [
            'ага',
            'алло',
            'да',
            'зачем',
            'не',
            'нет',
            'ок',
            'окей',
            'привет',
            'пропустить',
            'спасибо',
            'хз',
            'skip',
        ];
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
        $normalized = preg_replace('/\s+/u', ' ', trim($value, " \t\n\r\0\x0B.,!?;:\"\'«»()[]{}"));

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

        foreach (['ru', 'en'] as $locale) {
            $bundle = ResourceBundle::create($locale, 'ICUDATA-region');
            $countries = $bundle instanceof ResourceBundle ? ($bundle['Countries'] ?? null) : null;

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

                $canonicalName = Locale::getDisplayRegion('und_'.$code, 'ru');

                if (! is_string($canonicalName) || trim($canonicalName) === '') {
                    $canonicalName = $name;
                }

                $lookup[$normalizedName] = Str::limit(trim($canonicalName), 255, '');
            }
        }

        return $lookup;
    }
}
