<?php

namespace Tests\Feature;

use App\Services\DataCollection\ExtractResidenceCityAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtractResidenceCityActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_accepts_city_with_high_country_confidence(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Будапешт',
                'country' => 'Венгрия',
                'country_confidence' => 'high',
            ])),
        ]);

        $result = app(ExtractResidenceCityAction::class)->handle('Будапешт');

        $this->assertSame([
            'decision' => 'accept',
            'city' => 'Будапешт',
            'country' => 'Венгрия',
            'country_confidence' => 'high',
        ], $result);
    }

    public function test_action_accepts_city_with_low_country_confidence_without_country(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Сан-Хосе',
                'country' => null,
                'country_confidence' => 'low',
            ])),
        ]);

        $result = app(ExtractResidenceCityAction::class)->handle('Сан-Хосе');

        $this->assertSame([
            'decision' => 'accept',
            'city' => 'Сан-Хосе',
            'country' => null,
            'country_confidence' => 'low',
        ], $result);
    }

    public function test_action_keeps_city_and_downgrades_to_low_confidence_for_malformed_country_confidence(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Мапуто',
                'country' => 'Мозамбик',
                'country_confidence' => 'medium',
            ])),
        ]);

        $result = app(ExtractResidenceCityAction::class)->handle('Мапуто');

        $this->assertSame([
            'decision' => 'accept',
            'city' => 'Мапуто',
            'country' => null,
            'country_confidence' => 'low',
        ], $result);
    }

    public function test_action_retries_for_non_city_answer(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'city' => null,
                'country' => null,
                'country_confidence' => null,
            ])),
        ]);

        $result = app(ExtractResidenceCityAction::class)->handle('Привет');

        $this->assertSame([
            'decision' => 'retry',
            'city' => null,
            'country' => null,
            'country_confidence' => null,
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
