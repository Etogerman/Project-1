<?php

namespace App\Services\DataCollection;

use App\Data\AI\AiGenerationContext;
use App\Models\Contact;
use App\Services\AI\AiStructuredGenerationService;
use App\Services\AI\GeminiApiService;
use Illuminate\Support\Str;
use Locale;
use ResourceBundle;
use RuntimeException;

class ExtractFirstNameAction
{
    public const DECISION_ACCEPT = 'accept';

    public const DECISION_RETRY = 'retry';

    public function __construct(
        protected GeminiApiService $geminiApiService,
        protected AiStructuredGenerationService $aiStructuredGenerationService,
    ) {}

    /**
     * @return array{decision: string, first_name: ?string, resolution_method: ?string, ai_used?: bool, ai_request_id?: int|null}
     */
    public function handle(string $userReply, ?string $messengerName = null, ?AiGenerationContext $aiContext = null): array
    {
        $directFirstName = $this->resolveDirectFirstNameMatch($userReply);

        if ($directFirstName !== null) {
            return [
                'decision' => self::DECISION_ACCEPT,
                'first_name' => $directFirstName,
                'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
            ];
        }

        $phraseFirstName = $this->resolvePhraseFirstNameMatch($userReply);

        if ($phraseFirstName !== null) {
            return [
                'decision' => self::DECISION_ACCEPT,
                'first_name' => $phraseFirstName,
                'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
            ];
        }

        $shortMultiTokenFirstName = $this->resolveShortMultiTokenFirstNameMatch($userReply);

        if ($shortMultiTokenFirstName !== null) {
            return [
                'decision' => self::DECISION_ACCEPT,
                'first_name' => $shortMultiTokenFirstName,
                'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
            ];
        }

        $aiRequestId = null;
        $systemPrompt = $this->systemPrompt($messengerName);
        $userPrompt = $this->userPrompt($userReply, $messengerName);

        if ($aiContext instanceof AiGenerationContext) {
            $generationResult = $this->aiStructuredGenerationService->generateStructuredWithAnalytics(
                $systemPrompt,
                $userPrompt,
                $this->schema(),
                $aiContext,
            );
            $response = $generationResult->data;
            $aiRequestId = $generationResult->aiRequestId;
        } else {
            $response = $this->geminiApiService->generateStructured(
                $systemPrompt,
                $userPrompt,
                $this->schema(),
            );
        }

        $decision = data_get($response, 'decision');

        if (! in_array($decision, [self::DECISION_ACCEPT, self::DECISION_RETRY], true)) {
            throw new RuntimeException('Gemini first-name extraction returned an invalid decision.');
        }

        if ($decision === self::DECISION_RETRY) {
            $result = [
                'decision' => self::DECISION_RETRY,
                'first_name' => null,
                'resolution_method' => null,
            ];

            if ($aiContext instanceof AiGenerationContext) {
                $result['ai_used'] = true;
                $result['ai_request_id'] = $aiRequestId;
            }

            return $result;
        }

        $firstName = $this->normalizeFirstName(data_get($response, 'first_name'));

        if ($firstName === null) {
            throw new RuntimeException('Gemini first-name extraction accepted the value but did not return a name.');
        }

        $result = [
            'decision' => self::DECISION_ACCEPT,
            'first_name' => $firstName,
            'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS,
        ];

        if ($aiContext instanceof AiGenerationContext) {
            $result['ai_used'] = true;
            $result['ai_request_id'] = $aiRequestId;
        }

        return $result;
    }

    protected function systemPrompt(?string $messengerName): string
    {
        $prompt = <<<'TEXT'
Ты проверяешь ответ пользователя на вопрос "Как вас зовут?".

Верни только JSON по заданной схеме.

Правила:
- Если пользователь написал ровно одно обычное человеческое имя, даже без фразы "меня зовут", это валидный ответ.
- Если пользователь ответил естественной фразой с именем, извлеки только имя.
- Если в ответе есть уменьшительное имя и явно указано полное имя, бери полное имя.
- Игнорируй фамилию, отчество, титулы и прозвища, если имя уже можно определить.
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
- "Меня зовут Николай" -> {"decision":"accept","first_name":"Николай"}
- "Я Николай" -> {"decision":"accept","first_name":"Николай"}
- "Зови меня Коля" -> {"decision":"accept","first_name":"Коля"}
- "Обычно меня зовут Колян, а полное имя Николай" -> {"decision":"accept","first_name":"Николай"}
- "Николай Первый" -> {"decision":"accept","first_name":"Николай"}
- "Николай Петрович" -> {"decision":"accept","first_name":"Николай"}
- "Не скажу как меня зовут" -> {"decision":"retry","first_name":null}
- "А зачем вам это?" -> {"decision":"retry","first_name":null}
- "Россия" -> {"decision":"retry","first_name":null}
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

    protected function resolvePhraseFirstNameMatch(string $value): ?string
    {
        $normalized = $this->normalizePhraseText($value);

        if ($normalized === null || $this->containsExplicitNonNameSemantic($normalized)) {
            return null;
        }

        if (preg_match('/(?:^|[\s,.;:!?()«»"\'-])(?:мо[её]\s+)?полное\s+имя\s+(.+)$/u', $normalized, $matches) === 1) {
            $candidate = $this->extractFirstValidPhraseToken($matches[1] ?? null);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        foreach ($this->phrasePatterns() as $pattern) {
            if (preg_match($pattern, $normalized, $matches) !== 1) {
                continue;
            }

            $candidate = $this->extractFirstValidPhraseToken($matches[1] ?? null);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    protected function resolveShortMultiTokenFirstNameMatch(string $value): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value, " \t\n\r\0\x0B.,!?;:\"«»()[]{}"));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        $tokens = preg_split('/\s+/u', $normalized);

        if (! is_array($tokens) || count($tokens) < 2 || count($tokens) > 3) {
            return null;
        }

        $firstToken = $this->normalizeDirectCandidate((string) array_shift($tokens));

        if ($firstToken === null || $this->isStopWord($firstToken) || $this->resolveDirectCountryMatch($firstToken) !== null) {
            return null;
        }

        foreach ($tokens as $token) {
            $secondaryToken = $this->normalizeDirectCandidate($token);

            if ($secondaryToken === null || ! $this->looksLikeSecondaryNamePart($secondaryToken)) {
                return null;
            }
        }

        return $this->normalizeNameToken($firstToken);
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

    protected function normalizePhraseText(string $value): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        return mb_strtolower($normalized);
    }

    protected function containsExplicitNonNameSemantic(string $value): bool
    {
        foreach ($this->nonNameFragments() as $fragment) {
            if (str_contains($value, $fragment)) {
                return true;
            }
        }

        return false;
    }

    protected function extractFirstValidPhraseToken(?string $tail): ?string
    {
        if (! is_string($tail) || trim($tail) === '') {
            return null;
        }

        $tokens = preg_split('/[\s,.;:!?()«»"]+/u', $tail);

        if (! is_array($tokens)) {
            return null;
        }

        foreach ($tokens as $token) {
            if (! is_string($token) || $token === '') {
                continue;
            }

            if ($this->isSkippablePhraseToken($token)) {
                continue;
            }

            $candidate = $this->normalizeDirectCandidate($token);

            if ($candidate === null || $this->isStopWord($candidate) || $this->resolveDirectCountryMatch($candidate) !== null) {
                return null;
            }

            return $this->normalizeNameToken($candidate);
        }

        return null;
    }

    protected function isSkippablePhraseToken(string $token): bool
    {
        return in_array(mb_strtolower(trim($token)), $this->phraseSkipTokens(), true);
    }

    protected function looksLikeSecondaryNamePart(string $value): bool
    {
        $normalized = mb_strtolower($value);

        if (in_array($normalized, $this->secondaryNameTokens(), true)) {
            return true;
        }

        return preg_match(
            '/(?:ович|евич|ич|ыч|оглы|улы|кызы|ична|инична|овна|евна|ов|ев|ёв|ин|ын|ский|ская|цкий|цкая|енко|ук|юк|дзе|швили|son|sen|ski|sky|ez|es|ian|yan|ova|eva|ina)$/ui',
            $value,
        ) === 1;
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
            'из',
            'зачем',
            'не',
            'нет',
            'по',
            'пожалуйста',
            'просто',
            'ок',
            'окей',
            'потом',
            'привет',
            'пропустить',
            'спасибо',
            'ты',
            'это',
            'хз',
            'skip',
        ];
    }

    /**
     * @return list<string>
     */
    protected function phrasePatterns(): array
    {
        return [
            '/^\s*обычно\s+меня\s+зовут\s+(.+)$/u',
            '/^\s*обычно\s+зовут\s+(.+)$/u',
            '/^\s*меня\s+зовут\s+(.+)$/u',
            '/^\s*зовут\s+меня\s+(.+)$/u',
            '/^\s*зови\s+меня\s+(.+)$/u',
            '/^\s*называй\s+меня\s+(.+)$/u',
            '/^\s*можно\s+(.+)$/u',
            '/^\s*мо[её]\s+имя\s+(.+)$/u',
            '/^\s*имя\s+мо[её]\s+(.+)$/u',
            '/^\s*я\s+(.+)$/u',
        ];
    }

    /**
     * @return list<string>
     */
    protected function phraseSkipTokens(): array
    {
        return [
            'это',
        ];
    }

    /**
     * @return list<string>
     */
    protected function nonNameFragments(): array
    {
        return [
            'не скажу',
            'не хочу',
            'не буду',
            'не отвечу',
            'неважно',
            'зачем',
            'потом',
            'секрет',
            'тайна',
        ];
    }

    /**
     * @return list<string>
     */
    protected function secondaryNameTokens(): array
    {
        return [
            'first',
            'i',
            'ii',
            'iii',
            'iv',
            'junior',
            'jr',
            'младший',
            'первый',
            'sr',
            'старший',
            'second',
            'senior',
            'third',
            'второй',
            'третий',
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

                $canonicalName = Locale::getDisplayRegion('und_'.$code, 'ru');

                if (! is_string($canonicalName) || trim($canonicalName) === '') {
                    $canonicalName = $name;
                }

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
     * @return array<string, list<string>>
     */
    protected function countryAliases(): array
    {
        return [
            'Венгрия' => ['Венгрия'],
            'Казахстан' => ['Казахстан'],
            'Кения' => ['Кения'],
            'Мозамбик' => ['Мозамбик'],
            'Россия' => ['Россия', 'Российская Федерация', 'РФ'],
        ];
    }
}
