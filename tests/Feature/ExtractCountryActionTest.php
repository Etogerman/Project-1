<?php

namespace Tests\Feature;

use App\Services\DataCollection\ExtractCountryAction;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtractCountryActionTest extends TestCase
{
    public function test_action_accepts_valid_country(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'country' => 'Россия',
            ])),
        ]);

        $result = app(ExtractCountryAction::class)->handle('Я из России');

        $this->assertSame([
            'decision' => 'accept',
            'country' => 'Россия',
        ], $result);
    }

    public function test_action_returns_retry_for_refusal(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'country' => null,
            ])),
        ]);

        $result = app(ExtractCountryAction::class)->handle('Не скажу');

        $this->assertSame([
            'decision' => 'retry',
            'country' => null,
        ], $result);
    }

    public function test_action_returns_retry_for_gibberish(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'country' => null,
            ])),
        ]);

        $result = app(ExtractCountryAction::class)->handle('12345');

        $this->assertSame([
            'decision' => 'retry',
            'country' => null,
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
