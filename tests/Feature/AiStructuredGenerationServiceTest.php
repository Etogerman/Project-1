<?php

namespace Tests\Feature;

use App\Models\AiProcessor;
use App\Services\AI\AiStructuredGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiStructuredGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_generation_uses_active_processors_by_priority(): void
    {
        config()->set('bots.gemini.api_key', null);

        AiProcessor::query()->delete();

        AiProcessor::query()->create([
            'name' => 'Gemini запасной',
            'provider' => AiProcessor::PROVIDER_GEMINI,
            'model' => 'gemini-backup',
            'credentials' => ['api_key' => 'backup-key'],
            'is_active' => true,
            'priority' => 20,
            'timeout_seconds' => 15,
            'temperature' => 0.1,
            'max_output_tokens' => 256,
            'thinking_budget' => 0,
        ]);

        AiProcessor::query()->create([
            'name' => 'Gemini основной',
            'provider' => AiProcessor::PROVIDER_GEMINI,
            'model' => 'gemini-primary',
            'credentials' => ['api_key' => 'primary-key'],
            'is_active' => true,
            'priority' => 10,
            'timeout_seconds' => 15,
            'temperature' => 0.2,
            'max_output_tokens' => 512,
            'thinking_budget' => 0,
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"output_id":"ok","data":[]}'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(AiStructuredGenerationService::class)->generateStructured('system', 'user', [
            'type' => 'object',
        ]);

        $this->assertSame('ok', $result['output_id']);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/models/gemini-primary:generateContent')
                && $request->hasHeader('x-goog-api-key', 'primary-key');
        });
    }

    public function test_structured_generation_falls_back_to_next_processor(): void
    {
        config()->set('bots.gemini.api_key', null);

        AiProcessor::query()->delete();

        $primary = AiProcessor::query()->create([
            'name' => 'Gemini основной',
            'provider' => AiProcessor::PROVIDER_GEMINI,
            'model' => 'gemini-primary',
            'credentials' => ['api_key' => 'primary-key'],
            'is_active' => true,
            'priority' => 10,
            'timeout_seconds' => 15,
            'temperature' => 0.2,
            'max_output_tokens' => 512,
            'thinking_budget' => 0,
        ]);

        AiProcessor::query()->create([
            'name' => 'Gemini запасной',
            'provider' => AiProcessor::PROVIDER_GEMINI,
            'model' => 'gemini-backup',
            'credentials' => ['api_key' => 'backup-key'],
            'is_active' => true,
            'priority' => 20,
            'timeout_seconds' => 15,
            'temperature' => 0.2,
            'max_output_tokens' => 512,
            'thinking_budget' => 0,
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-primary:generateContent' => Http::response([
                'error' => ['message' => 'quota exceeded'],
            ], 429),
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-backup:generateContent' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"output_id":"backup","data":[]}'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(AiStructuredGenerationService::class)->generateStructured('system', 'user', [
            'type' => 'object',
        ]);

        $this->assertSame('backup', $result['output_id']);
        $this->assertNotNull($primary->fresh()->last_failed_at);
    }
}
