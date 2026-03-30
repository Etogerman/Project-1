<?php

namespace App\Services\DataCollection;

use App\Services\AI\GeminiApiService;
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
- Если пользователь действительно назвал имя, верни decision="accept" и извлеки только имя в поле first_name.
- Если пользователь отказался, ушёл от ответа, задал встречный вопрос, написал мусор, цифры или фразу, не похожую на имя, верни decision="retry" и first_name=null.
- Не придумывай имя, если его нет в ответе.
- Не используй имя из профиля мессенджера как замену ответу пользователя.
- Допустимо нормализовать имя: убрать лишние слова, пробелы и привести к обычной записи.

Примеры:
- "Герман" -> {"decision":"accept","first_name":"Герман"}
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

        return Str::limit($normalized, 255, '');
    }
}
