<?php

namespace Tests\Feature;

use App\Data\AI\AiGenerationContext;
use App\Models\AiPricingRate;
use App\Models\AiProcessor;
use App\Models\AiRequest;
use App\Models\AiTask;
use App\Models\Contact;
use App\Services\AI\AiStructuredGenerationException;
use App\Services\AI\AiStructuredGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiRequestAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_generation_with_analytics_logs_successful_fallback_attempts_and_cost(): void
    {
        config()->set('bots.gemini.api_key', null);

        $contact = Contact::factory()->create();
        $this->createProcessor('Gemini основной', 'gemini-primary', 'primary-key', 10);
        $this->createProcessor('Gemini запасной', 'gemini-backup', 'backup-key', 20);
        $this->createPricingRate('gemini-backup');

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-primary:generateContent' => Http::response([
                'error' => ['message' => 'quota exceeded'],
            ], 429),
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-backup:generateContent' => Http::response($this->geminiResponse([
                'output_id' => 'backup',
                'data' => ['first_name' => 'Николай'],
            ], promptTokens: 10, outputTokens: 20, thinkingTokens: 5, totalTokens: 35)),
        ]);

        $result = app(AiStructuredGenerationService::class)->generateStructuredWithAnalytics(
            'system prompt',
            'user prompt',
            ['type' => 'object'],
            new AiGenerationContext(
                taskKey: AiTask::KEY_NAME_RESOLUTION,
                contactId: $contact->id,
                promptKey: 'test:first_name',
            ),
        );

        $request = AiRequest::query()->with('attempts')->sole();

        $this->assertSame(['output_id' => 'backup', 'data' => ['first_name' => 'Николай']], $result->data);
        $this->assertSame(AiRequest::STATUS_SUCCESS, $request->status);
        $this->assertSame('gemini', $request->provider);
        $this->assertSame('gemini-backup', $request->model);
        $this->assertSame(AiRequest::COST_STATUS_PARTIAL, $request->cost_status);
        $this->assertSame(35, $request->total_tokens);
        $this->assertSame('0.00006500', $request->estimated_cost);
        $this->assertSame('USD', $request->currency);
        $this->assertNotNull($request->final_attempt_id);
        $this->assertCount(2, $request->attempts);
        $this->assertSame(AiRequest::COST_STATUS_MISSING_USAGE, $request->attempts[0]->cost_status);
        $this->assertSame(AiRequest::COST_STATUS_CALCULATED, $request->attempts[1]->cost_status);
    }

    public function test_structured_generation_with_analytics_logs_full_fallback_error(): void
    {
        config()->set('bots.gemini.api_key', null);

        $contact = Contact::factory()->create();
        $this->createProcessor('Gemini основной', 'gemini-primary', 'primary-key', 10);
        $this->createProcessor('Gemini запасной', 'gemini-backup', 'backup-key', 20);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-primary:generateContent' => Http::response([
                'error' => ['message' => 'quota exceeded'],
            ], 429),
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-backup:generateContent' => Http::response([
                'error' => ['message' => 'server error'],
            ], 500),
        ]);

        try {
            app(AiStructuredGenerationService::class)->generateStructuredWithAnalytics(
                'system prompt',
                'user prompt',
                ['type' => 'object'],
                new AiGenerationContext(
                    taskKey: AiTask::KEY_NAME_RESOLUTION,
                    contactId: $contact->id,
                    promptKey: 'test:first_name',
                ),
            );

            $this->fail('Expected analytics generation exception.');
        } catch (AiStructuredGenerationException $exception) {
            $this->assertNotNull($exception->aiRequestId);
        }

        $request = AiRequest::query()->with('attempts')->sole();

        $this->assertSame(AiRequest::STATUS_ERROR, $request->status);
        $this->assertNull($request->final_attempt_id);
        $this->assertSame('gemini-backup', $request->model);
        $this->assertSame(AiRequest::COST_STATUS_MISSING_USAGE, $request->cost_status);
        $this->assertCount(2, $request->attempts);
    }

    public function test_plain_structured_generation_keeps_backward_compatible_without_ai_request_log(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'output_id' => 'plain',
                'data' => [],
            ])),
        ]);

        $result = app(AiStructuredGenerationService::class)->generateStructured('system', 'user', [
            'type' => 'object',
        ]);

        $this->assertSame('plain', $result['output_id']);
        $this->assertDatabaseCount('ai_requests', 0);
    }

    private function createProcessor(string $name, string $model, string $apiKey, int $priority): AiProcessor
    {
        return AiProcessor::query()->create([
            'name' => $name,
            'provider' => AiProcessor::PROVIDER_GEMINI,
            'model' => $model,
            'credentials' => ['api_key' => $apiKey],
            'is_active' => true,
            'priority' => $priority,
            'timeout_seconds' => 15,
            'temperature' => 0.2,
            'max_output_tokens' => 512,
            'thinking_budget' => 0,
        ]);
    }

    private function createPricingRate(string $model): AiPricingRate
    {
        return AiPricingRate::query()->create([
            'provider' => AiProcessor::PROVIDER_GEMINI,
            'model' => $model,
            'input_price_per_1m_tokens' => '1.00000000',
            'output_price_per_1m_tokens' => '2.00000000',
            'thinking_price_per_1m_tokens' => '3.00000000',
            'currency' => AiPricingRate::CURRENCY_USD,
            'effective_from' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function geminiResponse(
        array $payload,
        int $promptTokens = 1,
        int $outputTokens = 1,
        int $thinkingTokens = 0,
        int $totalTokens = 2,
    ): array {
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
            'usageMetadata' => [
                'promptTokenCount' => $promptTokens,
                'candidatesTokenCount' => $outputTokens,
                'thoughtsTokenCount' => $thinkingTokens,
                'totalTokenCount' => $totalTokens,
            ],
        ];
    }
}
