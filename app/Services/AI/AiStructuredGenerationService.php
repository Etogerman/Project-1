<?php

namespace App\Services\AI;

use App\Data\AI\AiGenerationContext;
use App\Data\AI\AiStructuredGenerationResult;
use App\Models\AiProcessor;
use App\Models\AiRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class AiStructuredGenerationService
{
    public function __construct(
        private readonly GeminiApiService $geminiApiService,
        private readonly AiRequestAnalyticsService $aiRequestAnalyticsService,
    ) {}

    /**
     * @param  array<string, mixed>  $responseJsonSchema
     * @return array<string, mixed>
     */
    public function generateStructured(string $systemPrompt, string $userPrompt, array $responseJsonSchema): array
    {
        $processors = $this->activeProcessors();

        if ($processors === []) {
            return $this->geminiApiService->generateStructured($systemPrompt, $userPrompt, $responseJsonSchema);
        }

        $lastThrowable = null;

        foreach ($processors as $processor) {
            try {
                $response = match ($processor->provider) {
                    AiProcessor::PROVIDER_GEMINI => $this->geminiApiService->generateStructured(
                        $systemPrompt,
                        $userPrompt,
                        $responseJsonSchema,
                        $processor->structuredSettings(),
                    ),
                    default => throw new RuntimeException("AI provider [{$processor->provider}] is not supported."),
                };

                $this->markProcessorRecovered($processor);

                return $response;
            } catch (Throwable $throwable) {
                $lastThrowable = $throwable;
                $this->markProcessorFailed($processor, $throwable);
            }
        }

        throw new RuntimeException('Все ИИ-обработчики недоступны.', previous: $lastThrowable);
    }

    /**
     * @param  array<string, mixed>  $responseJsonSchema
     */
    public function generateStructuredWithAnalytics(
        string $systemPrompt,
        string $userPrompt,
        array $responseJsonSchema,
        AiGenerationContext $context,
    ): AiStructuredGenerationResult {
        $aiRequest = $this->aiRequestAnalyticsService->start($context, $systemPrompt, $userPrompt);
        $processors = $this->activeProcessors();

        if ($processors === []) {
            try {
                return $this->generateWithAnalyticsAttempt(
                    aiRequest: $aiRequest,
                    attemptNumber: 1,
                    processor: null,
                    systemPrompt: $systemPrompt,
                    userPrompt: $userPrompt,
                    responseJsonSchema: $responseJsonSchema,
                    settings: null,
                );
            } catch (Throwable $throwable) {
                $this->aiRequestAnalyticsService->finalize(
                    $aiRequest,
                    $aiRequest?->attempts()->latest('id')->first(),
                    false,
                );

                throw new AiStructuredGenerationException(
                    $throwable->getMessage(),
                    $aiRequest?->id,
                    $throwable,
                );
            }
        }

        $lastThrowable = null;
        $lastAttempt = null;

        foreach ($processors as $index => $processor) {
            try {
                return $this->generateWithAnalyticsAttempt(
                    aiRequest: $aiRequest,
                    attemptNumber: $index + 1,
                    processor: $processor,
                    systemPrompt: $systemPrompt,
                    userPrompt: $userPrompt,
                    responseJsonSchema: $responseJsonSchema,
                    settings: $processor->structuredSettings(),
                );
            } catch (Throwable $throwable) {
                $lastThrowable = $throwable;
                $this->markProcessorFailed($processor, $throwable);
                $lastAttempt = $aiRequest?->attempts()->latest('id')->first();
            }
        }

        $this->aiRequestAnalyticsService->finalize($aiRequest, $lastAttempt, false);

        throw new AiStructuredGenerationException(
            'Все ИИ-обработчики недоступны.',
            $aiRequest?->id,
            $lastThrowable,
        );
    }

    /**
     * @param  array<string, mixed>  $responseJsonSchema
     * @return array{
     *     status: 'success'|'temporary_failed'|'failed',
     *     result?: AiStructuredGenerationResult,
     *     ai_request_id?: ?int,
     *     last_attempt_id?: ?int,
     *     error_message?: string,
     *     has_non_temporary_error?: bool
     * }
     */
    public function generateStructuredV3Cycle(
        string $systemPrompt,
        string $userPrompt,
        array $responseJsonSchema,
        AiGenerationContext $context,
        ?AiRequest $aiRequest = null,
    ): array {
        $aiRequest ??= $this->aiRequestAnalyticsService->start($context, $systemPrompt, $userPrompt);
        $processors = $this->activeProcessors();
        $processors = $processors !== [] ? $processors : [null];
        $lastThrowable = null;
        $lastAttempt = null;
        $hasNonTemporaryError = false;

        foreach ($processors as $processor) {
            $attemptNumber = $this->aiRequestAnalyticsService->nextAttemptNumber($aiRequest);

            try {
                $result = $this->generateWithAnalyticsAttempt(
                    aiRequest: $aiRequest,
                    attemptNumber: $attemptNumber,
                    processor: $processor,
                    systemPrompt: $systemPrompt,
                    userPrompt: $userPrompt,
                    responseJsonSchema: $responseJsonSchema,
                    settings: $processor instanceof AiProcessor ? $processor->structuredSettings() : null,
                );

                return [
                    'status' => 'success',
                    'result' => $result,
                    'ai_request_id' => $aiRequest?->id,
                    'last_attempt_id' => $result->finalAttemptId,
                    'has_non_temporary_error' => $hasNonTemporaryError,
                ];
            } catch (AiProviderRequestException $throwable) {
                $lastThrowable = $throwable;
                $lastAttempt = $aiRequest?->attempts()->latest('id')->first();

                if ($processor instanceof AiProcessor) {
                    $this->markProcessorFailed($processor, $throwable);
                }

                if (! $throwable->isTemporary()) {
                    $hasNonTemporaryError = true;
                }
            } catch (Throwable $throwable) {
                $lastThrowable = $throwable;
                $lastAttempt = $aiRequest?->attempts()->latest('id')->first();
                $hasNonTemporaryError = true;

                if ($processor instanceof AiProcessor) {
                    $this->markProcessorFailed($processor, $throwable);
                }
            }
        }

        if (! $hasNonTemporaryError) {
            $this->aiRequestAnalyticsService->markRetrying($aiRequest);

            return [
                'status' => 'temporary_failed',
                'ai_request_id' => $aiRequest?->id,
                'last_attempt_id' => $lastAttempt?->id,
                'error_message' => $lastThrowable?->getMessage() ?? 'Temporary AI provider error.',
                'has_non_temporary_error' => false,
            ];
        }

        $this->aiRequestAnalyticsService->finalize($aiRequest, $lastAttempt, false);

        return [
            'status' => 'failed',
            'ai_request_id' => $aiRequest?->id,
            'last_attempt_id' => $lastAttempt?->id,
            'error_message' => $lastThrowable?->getMessage() ?? 'AI provider error.',
            'has_non_temporary_error' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $responseJsonSchema
     * @param  array<string, mixed>|null  $settings
     */
    private function generateWithAnalyticsAttempt(
        ?AiRequest $aiRequest,
        int $attemptNumber,
        ?AiProcessor $processor,
        string $systemPrompt,
        string $userPrompt,
        array $responseJsonSchema,
        ?array $settings,
    ): AiStructuredGenerationResult {
        $startedAt = now();

        try {
            $response = match ($processor?->provider ?? AiProcessor::PROVIDER_GEMINI) {
                AiProcessor::PROVIDER_GEMINI => $this->geminiApiService->generateStructuredWithMetadata(
                    $systemPrompt,
                    $userPrompt,
                    $responseJsonSchema,
                    $settings,
                ),
                default => throw new RuntimeException("AI provider [{$processor?->provider}] is not supported."),
            };
        } catch (AiProviderRequestException $throwable) {
            $finishedAt = now();
            $attempt = $this->aiRequestAnalyticsService->recordErrorAttempt(
                $aiRequest,
                $attemptNumber,
                $processor,
                $throwable,
                $startedAt,
                $finishedAt,
            );

            throw $throwable;
        } catch (Throwable $throwable) {
            $finishedAt = now();
            $attempt = $this->aiRequestAnalyticsService->recordErrorAttempt(
                $aiRequest,
                $attemptNumber,
                $processor,
                $throwable,
                $startedAt,
                $finishedAt,
            );

            throw $throwable;
        }

        $finishedAt = now();
        $attempt = $this->aiRequestAnalyticsService->recordSuccessAttempt(
            $aiRequest,
            $attemptNumber,
            $processor,
            $response,
            $startedAt,
            $finishedAt,
        );
        $this->aiRequestAnalyticsService->finalize($aiRequest, $attempt, true);

        if ($processor instanceof AiProcessor) {
            $this->markProcessorRecovered($processor);
        }

        return new AiStructuredGenerationResult(
            data: $response->parsedPayload,
            aiRequestId: $aiRequest?->id,
            finalAttemptId: $attempt?->id,
            provider: $response->provider,
            model: $response->model,
            status: 'success',
        );
    }

    /**
     * @return list<AiProcessor>
     */
    private function activeProcessors(): array
    {
        if (! Schema::hasTable('ai_processors')) {
            return [];
        }

        return AiProcessor::query()
            ->active()
            ->ordered()
            ->get()
            ->all();
    }

    private function markProcessorFailed(AiProcessor $processor, Throwable $throwable): void
    {
        Log::warning('ai.processor_failed', [
            'processor_id' => $processor->id,
            'processor_name' => $processor->name,
            'provider' => $processor->provider,
            'exception' => get_class($throwable),
            'error_message' => $this->safeErrorMessage($throwable),
        ]);

        $processor->forceFill([
            'last_failed_at' => now(),
            'last_error_message' => $this->safeErrorMessage($throwable),
        ])->saveQuietly();
    }

    private function markProcessorRecovered(AiProcessor $processor): void
    {
        if ($processor->last_failed_at === null && blank($processor->last_error_message)) {
            return;
        }

        $processor->forceFill([
            'last_failed_at' => null,
            'last_error_message' => null,
        ])->saveQuietly();
    }

    private function safeErrorMessage(Throwable $throwable): string
    {
        $message = preg_replace('/\s+/u', ' ', trim($throwable->getMessage()));

        if (! is_string($message) || $message === '') {
            return 'ИИ-обработчик вернул ошибку.';
        }

        return mb_substr($message, 0, 1000);
    }
}
