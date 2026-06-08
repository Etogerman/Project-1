<?php

namespace Tests\Feature;

use App\Models\Contact;
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
            'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
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
            'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
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
            'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
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
            'resolution_method' => null,
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
            'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
        ], $result);
    }

    public function test_action_marks_gemini_accepted_name_as_ai_analysis(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'first_name' => 'Герман',
            ])),
        ]);

        $result = app(ExtractFirstNameAction::class)->handle('думаю пусть будет Герман');

        Http::assertSentCount(1);

        $this->assertSame([
            'decision' => 'accept',
            'first_name' => 'Герман',
            'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS,
        ], $result);
    }

    public function test_action_accepts_phrase_with_name_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake();

        $result = app(ExtractFirstNameAction::class)->handle('Меня зовут Николай');

        Http::assertNothingSent();

        $this->assertSame([
            'decision' => 'accept',
            'first_name' => 'Николай',
            'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
        ], $result);
    }

    public function test_action_accepts_phrase_with_full_name_priority_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake();

        $result = app(ExtractFirstNameAction::class)->handle('Обычно меня зовут Колян, а полное имя Николай');

        Http::assertNothingSent();

        $this->assertSame([
            'decision' => 'accept',
            'first_name' => 'Николай',
            'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
        ], $result);
    }

    public function test_action_accepts_two_word_name_like_reply_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake();

        $result = app(ExtractFirstNameAction::class)->handle('Николай Первый');

        Http::assertNothingSent();

        $this->assertSame([
            'decision' => 'accept',
            'first_name' => 'Николай',
            'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
        ], $result);
    }

    public function test_action_accepts_name_and_patronymic_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake();

        $result = app(ExtractFirstNameAction::class)->handle('Николай Петрович');

        Http::assertNothingSent();

        $this->assertSame([
            'decision' => 'accept',
            'first_name' => 'Николай',
            'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
        ], $result);
    }

    public function test_action_accepts_name_and_surname_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake();

        $result = app(ExtractFirstNameAction::class)->handle('Николай Абрикосов');

        Http::assertNothingSent();

        $this->assertSame([
            'decision' => 'accept',
            'first_name' => 'Николай',
            'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
        ], $result);
    }

    public function test_action_accepts_i_am_phrase_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake();

        $result = app(ExtractFirstNameAction::class)->handle('Я Николай');

        Http::assertNothingSent();

        $this->assertSame([
            'decision' => 'accept',
            'first_name' => 'Николай',
            'resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_SCENARIO_DIRECT,
        ], $result);
    }

    public function test_action_does_not_treat_i_am_from_country_phrase_as_local_name_match(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'first_name' => null,
            ])),
        ]);

        $result = app(ExtractFirstNameAction::class)->handle('Я из России');

        Http::assertSentCount(1);

        $this->assertSame([
            'decision' => 'retry',
            'first_name' => null,
            'resolution_method' => null,
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
            'resolution_method' => null,
        ], $result);
    }

    public function test_action_does_not_treat_refusal_phrase_with_name_marker_as_local_name_match(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'first_name' => null,
            ])),
        ]);

        $result = app(ExtractFirstNameAction::class)->handle('Не скажу как меня зовут');

        Http::assertSentCount(1);

        $this->assertSame([
            'decision' => 'retry',
            'first_name' => null,
            'resolution_method' => null,
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
            'resolution_method' => null,
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
