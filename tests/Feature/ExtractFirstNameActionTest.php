<?php

namespace Tests\Feature;

use App\Services\DataCollection\ExtractFirstNameAction;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtractFirstNameActionTest extends TestCase
{
    public function test_action_accepts_valid_first_name(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'first_name' => 'Герман',
            ])),
        ]);

        $result = app(ExtractFirstNameAction::class)->handle('Меня зовут Герман');

        $this->assertSame([
            'decision' => 'accept',
            'first_name' => 'Герман',
        ], $result);
    }

    public function test_action_returns_retry_for_refusal(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'first_name' => null,
            ])),
        ]);

        $result = app(ExtractFirstNameAction::class)->handle('Не скажу');

        $this->assertSame([
            'decision' => 'retry',
            'first_name' => null,
        ], $result);
    }

    public function test_action_returns_retry_for_gibberish(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'first_name' => null,
            ])),
        ]);

        $result = app(ExtractFirstNameAction::class)->handle('12345');

        $this->assertSame([
            'decision' => 'retry',
            'first_name' => null,
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
