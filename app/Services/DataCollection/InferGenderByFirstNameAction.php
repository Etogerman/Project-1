<?php

namespace App\Services\DataCollection;

use App\Models\Contact;
use App\Services\AI\GeminiApiService;
use RuntimeException;

class InferGenderByFirstNameAction
{
    public function __construct(
        protected GeminiApiService $geminiApiService,
    ) {}

    public function handle(string $firstName): string
    {
        $normalizedFirstName = trim($firstName);

        if ($normalizedFirstName === '') {
            throw new RuntimeException('First name is required for gender inference.');
        }

        $response = $this->geminiApiService->generateStructured(
            $this->systemPrompt(),
            $this->userPrompt($normalizedFirstName),
            $this->schema(),
        );

        $gender = data_get($response, 'gender');

        if (! is_string($gender) || ! array_key_exists($gender, Contact::genderOptions())) {
            throw new RuntimeException('Gemini gender inference returned an invalid gender.');
        }

        return $gender;
    }

    protected function systemPrompt(): string
    {
        return <<<'TEXT'
Ты определяешь наиболее вероятный пол по имени.

Верни только JSON по заданной схеме.

Правила:
- Если имя однозначно мужское, верни gender="male".
- Если имя однозначно женское, верни gender="female".
- Если имя неоднозначное, культурно вариативное или пола по имени надёжно не определить, верни gender="unknown".
- Не придумывай дополнительные поля и не объясняй ответ.

Примеры:
- "Николай" -> {"gender":"male"}
- "Мария" -> {"gender":"female"}
- "Саша" -> {"gender":"unknown"}
- "Женя" -> {"gender":"unknown"}
TEXT;
    }

    protected function userPrompt(string $firstName): string
    {
        return "Имя:\n".$firstName;
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
                'gender' => [
                    'type' => 'string',
                    'enum' => array_keys(Contact::genderOptions()),
                ],
            ],
            'required' => [
                'gender',
            ],
        ];
    }
}
