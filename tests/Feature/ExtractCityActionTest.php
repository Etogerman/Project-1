<?php

namespace Tests\Feature;

use App\Services\DataCollection\ExtractCityAction;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtractCityActionTest extends TestCase
{
    public function test_action_accepts_valid_city(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Москва',
            ])),
        ]);

        $result = app(ExtractCityAction::class)->handle('Я из Москвы');

        $this->assertSame([
            'decision' => 'accept',
            'city' => 'Москва',
        ], $result);
    }

    public function test_action_returns_retry_for_refusal(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'city' => null,
            ])),
        ]);

        $result = app(ExtractCityAction::class)->handle('Не скажу');

        $this->assertSame([
            'decision' => 'retry',
            'city' => null,
        ], $result);
    }

    public function test_action_returns_retry_for_gibberish(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'city' => null,
            ])),
        ]);

        $result = app(ExtractCityAction::class)->handle('12345');

        $this->assertSame([
            'decision' => 'retry',
            'city' => null,
        ], $result);
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
