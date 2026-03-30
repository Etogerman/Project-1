<?php

namespace Tests\Feature;

use App\Services\DataCollection\InferGenderByFirstNameAction;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InferGenderByFirstNameActionTest extends TestCase
{
    public function test_action_returns_male_for_male_name(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'gender' => 'male',
            ])),
        ]);

        $result = app(InferGenderByFirstNameAction::class)->handle('Николай');

        $this->assertSame('male', $result);
    }

    public function test_action_returns_female_for_female_name(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'gender' => 'female',
            ])),
        ]);

        $result = app(InferGenderByFirstNameAction::class)->handle('Мария');

        $this->assertSame('female', $result);
    }

    public function test_action_returns_unknown_for_ambiguous_name(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'gender' => 'unknown',
            ])),
        ]);

        $result = app(InferGenderByFirstNameAction::class)->handle('Саша');

        $this->assertSame('unknown', $result);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function geminiResponse(array $payload): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
