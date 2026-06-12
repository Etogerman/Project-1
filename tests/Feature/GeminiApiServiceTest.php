<?php

namespace Tests\Feature;

use App\Services\AI\AiProviderRequestException;
use App\Services\AI\GeminiApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class GeminiApiServiceTest extends TestCase
{
    public function test_service_returns_decoded_json_and_sends_structured_request(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.gemini.model', 'gemini-2.5-flash');
        config()->set('bots.gemini.max_output_tokens', 512);
        config()->set('bots.gemini.thinking_budget', 0);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => '{"decision":"accept","first_name":"Герман"}',
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(GeminiApiService::class)->generateStructured(
            'system prompt',
            'user prompt',
            [
                'type' => 'object',
                'properties' => [
                    'decision' => ['type' => 'string'],
                ],
                'required' => ['decision'],
            ],
        );

        $this->assertSame([
            'decision' => 'accept',
            'first_name' => 'Герман',
        ], $result);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'
                && $request->hasHeader('x-goog-api-key', 'gemini-key')
                && data_get($data, 'systemInstruction.parts.0.text') === 'system prompt'
                && data_get($data, 'contents.0.parts.0.text') === 'user prompt'
                && data_get($data, 'generationConfig.responseMimeType') === 'application/json'
                && data_get($data, 'generationConfig.responseJsonSchema.type') === 'object'
                && data_get($data, 'generationConfig.maxOutputTokens') === 512
                && data_get($data, 'generationConfig.thinkingConfig.thinkingBudget') === 0;
        });
    }

    public function test_service_throws_when_api_key_is_missing(): void
    {
        config()->set('bots.gemini.api_key', null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Gemini API key is not configured.');

        app(GeminiApiService::class)->generateStructured('system', 'user', [
            'type' => 'object',
        ]);
    }

    public function test_service_throws_on_api_error(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'message' => 'quota exceeded',
                ],
            ], 429),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini API request failed [429]');

        app(GeminiApiService::class)->generateStructured('system', 'user', [
            'type' => 'object',
        ]);
    }

    public function test_service_wraps_network_failure_as_temporary_provider_exception(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake(fn () => throw new ConnectionException('Connection timed out.'));

        try {
            app(GeminiApiService::class)->generateStructured('system', 'user', [
                'type' => 'object',
            ]);

            $this->fail('Expected Gemini provider exception.');
        } catch (AiProviderRequestException $exception) {
            $this->assertNull($exception->httpStatus);
            $this->assertTrue($exception->isTemporary());
            $this->assertStringContainsString('before HTTP response', $exception->getMessage());
            $this->assertStringContainsString('Connection timed out.', $exception->getMessage());
        }
    }

    public function test_service_throws_on_invalid_json_payload(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => 'not-json',
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini API returned invalid JSON');

        app(GeminiApiService::class)->generateStructured('system', 'user', [
            'type' => 'object',
        ]);
    }
}
