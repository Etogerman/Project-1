<?php

namespace Tests\Feature;

use App\Services\DataCollection\ExtractFirstNameAction;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtractFirstNameActionTest extends TestCase
{
    public function test_action_accepts_exact_first_name_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake();

        $result = app(ExtractFirstNameAction::class)->handle('Николай');

        Http::assertNothingSent();

        $this->assertSame([
            'decision' => 'accept',
            'first_name' => 'Николай',
        ], $result);
    }

    public function test_action_normalizes_lowercase_exact_first_name_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake();

        $result = app(ExtractFirstNameAction::class)->handle('николай');

        Http::assertNothingSent();

        $this->assertSame([
            'decision' => 'accept',
            'first_name' => 'Николай',
        ], $result);
    }

    public function test_action_accepts_short_form_first_name_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake();

        $result = app(ExtractFirstNameAction::class)->handle('Коля');

        Http::assertNothingSent();

        $this->assertSame([
            'decision' => 'accept',
            'first_name' => 'Коля',
        ], $result);
    }

    public function test_action_does_not_accept_country_name_as_direct_first_name_match(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'first_name' => null,
            ])),
        ]);

        $result = app(ExtractFirstNameAction::class)->handle('Россия');

        Http::assertSentCount(1);

        $this->assertSame([
            'decision' => 'retry',
            'first_name' => null,
        ], $result);
    }

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
