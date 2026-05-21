<?php

namespace App\Services\AI;

use App\Models\AiProcessor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class AiStructuredGenerationService
{
    public function __construct(
        private readonly GeminiApiService $geminiApiService,
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
