<?php

namespace App\Services\AI;

use App\Data\AI\AiProviderStructuredResult;
use App\Models\AiProcessor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class GeminiApiService
{
    /**
     * @param  array<string, mixed>  $responseJsonSchema
     * @return array<string, mixed>
     */
    public function generateStructured(
        string $systemPrompt,
        string $userPrompt,
        array $responseJsonSchema,
        ?array $settings = null,
    ): array {
        return $this->generateStructuredWithMetadata(
            $systemPrompt,
            $userPrompt,
            $responseJsonSchema,
            $settings,
        )->parsedPayload;
    }

    /**
     * @param  array<string, mixed>  $responseJsonSchema
     */
    public function generateStructuredWithMetadata(
        string $systemPrompt,
        string $userPrompt,
        array $responseJsonSchema,
        ?array $settings = null,
    ): AiProviderStructuredResult {
        $apiKey = $this->apiKey($settings);
        $model = $this->model($settings);
        $url = sprintf(
            '%s/models/%s:generateContent',
            rtrim($this->baseUrl($settings), '/'),
            $model,
        );
        $requestBody = $this->structuredRequestBody($systemPrompt, $userPrompt, $responseJsonSchema, $settings);
        $requestBodyRaw = $this->encodeBody($requestBody);

        try {
            $response = Http::asJson()
                ->timeout($this->timeoutSeconds($settings))
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                ])
                ->post($url, $requestBody);
        } catch (Throwable $throwable) {
            $this->logStructuredFailure(
                model: $model,
                httpStatus: null,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                rawBody: '',
                error: 'Gemini API request failed before HTTP response.',
            );

            throw new AiProviderRequestException(
                'Gemini API request failed before HTTP response: '.$throwable->getMessage(),
                AiProcessor::PROVIDER_GEMINI,
                $model,
                $requestBodyRaw,
                '',
                null,
                previous: $throwable,
            );
        }

        $httpStatus = $response->status();
        $rawBody = $response->body();

        if ($response->failed()) {
            $this->logStructuredFailure(
                model: $model,
                httpStatus: $httpStatus,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                rawBody: $rawBody,
                error: sprintf('Gemini API request failed [%d].', $httpStatus),
            );

            throw new AiProviderRequestException(
                sprintf('Gemini API request failed [%d]: %s', $httpStatus, trim($rawBody)),
                AiProcessor::PROVIDER_GEMINI,
                $model,
                $requestBodyRaw,
                $rawBody,
                $httpStatus,
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $this->logStructuredFailure(
                model: $model,
                httpStatus: $httpStatus,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                rawBody: $rawBody,
                error: 'Gemini API returned an invalid response payload.',
            );

            throw new AiProviderRequestException(
                'Gemini API returned an invalid response payload.',
                AiProcessor::PROVIDER_GEMINI,
                $model,
                $requestBodyRaw,
                $rawBody,
                $httpStatus,
            );
        }

        $text = data_get($payload, 'candidates.0.content.parts.0.text');
        $inputTokens = $this->nullableInt(data_get($payload, 'usageMetadata.promptTokenCount'));
        $outputTokens = $this->nullableInt(data_get($payload, 'usageMetadata.candidatesTokenCount'));
        $thinkingTokens = $this->nullableInt(data_get($payload, 'usageMetadata.thoughtsTokenCount'));
        $totalTokens = $this->nullableInt(data_get($payload, 'usageMetadata.totalTokenCount'));

        if (! is_string($text) || trim($text) === '') {
            $this->logStructuredFailure(
                model: $model,
                httpStatus: $httpStatus,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                rawBody: $rawBody,
                error: 'Gemini API returned an empty structured response.',
            );

            throw new AiProviderRequestException(
                'Gemini API returned an empty structured response.',
                AiProcessor::PROVIDER_GEMINI,
                $model,
                $requestBodyRaw,
                $rawBody,
                $httpStatus,
                $inputTokens,
                $outputTokens,
                $thinkingTokens,
                $totalTokens,
            );
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            $this->logStructuredFailure(
                model: $model,
                httpStatus: $httpStatus,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                rawBody: $rawBody,
                error: sprintf('Gemini API returned invalid JSON: %s', json_last_error_msg()),
                text: $text,
            );

            throw new AiProviderRequestException(
                sprintf('Gemini API returned invalid JSON: %s', $text),
                AiProcessor::PROVIDER_GEMINI,
                $model,
                $requestBodyRaw,
                $rawBody,
                $httpStatus,
                $inputTokens,
                $outputTokens,
                $thinkingTokens,
                $totalTokens,
            );
        }

        return new AiProviderStructuredResult(
            provider: AiProcessor::PROVIDER_GEMINI,
            model: $model,
            parsedPayload: $decoded,
            requestBodyRaw: $requestBodyRaw,
            responseBodyRaw: $rawBody,
            httpStatus: $httpStatus,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            thinkingTokens: $thinkingTokens,
            totalTokens: $totalTokens,
        );
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    protected function apiKey(?array $settings = null): string
    {
        $apiKey = trim((string) data_get($settings, 'api_key', config('bots.gemini.api_key', '')));

        if ($apiKey === '') {
            throw new InvalidArgumentException('Gemini API key is not configured.');
        }

        return $apiKey;
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    protected function model(?array $settings = null): string
    {
        $model = trim((string) data_get($settings, 'model', config('bots.gemini.model', 'gemini-2.5-flash')));

        if ($model === '') {
            throw new InvalidArgumentException('Gemini model is not configured.');
        }

        return $model;
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    protected function baseUrl(?array $settings = null): string
    {
        $baseUrl = trim((string) data_get($settings, 'base_url', config('bots.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta')));

        return $baseUrl !== '' ? $baseUrl : 'https://generativelanguage.googleapis.com/v1beta';
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    protected function temperature(?array $settings = null): float
    {
        return (float) data_get($settings, 'temperature', config('bots.gemini.temperature', 0.2));
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    protected function maxOutputTokens(?array $settings = null): int
    {
        return max(1, (int) data_get($settings, 'max_output_tokens', config('bots.gemini.max_output_tokens', 512)));
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    protected function thinkingBudget(?array $settings = null): int
    {
        return (int) data_get($settings, 'thinking_budget', config('bots.gemini.thinking_budget', 0));
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    protected function timeoutSeconds(?array $settings = null): int
    {
        return max(1, (int) data_get($settings, 'timeout_seconds', 30));
    }

    /**
     * @param  array<string, mixed>  $responseJsonSchema
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>
     */
    protected function structuredRequestBody(
        string $systemPrompt,
        string $userPrompt,
        array $responseJsonSchema,
        ?array $settings = null,
    ): array {
        return [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $this->temperature($settings),
                'maxOutputTokens' => $this->maxOutputTokens($settings),
                'thinkingConfig' => [
                    'thinkingBudget' => $this->thinkingBudget($settings),
                ],
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $responseJsonSchema,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function encodeBody(array $body): string
    {
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{}';
    }

    protected function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    protected function logStructuredFailure(
        string $model,
        int $httpStatus,
        string $systemPrompt,
        string $userPrompt,
        string $rawBody,
        string $error,
        ?string $text = null,
    ): void {
        Log::warning('gemini.structured_failure', [
            'model' => $model,
            'http_status' => $httpStatus,
            'system_prompt_preview' => $this->preview($systemPrompt, 300),
            'user_prompt_preview' => $this->preview($userPrompt, 300),
            'raw_response_preview' => $this->preview($rawBody, 1000),
            'text_preview' => $text === null ? null : $this->preview($text, 500),
            'error' => $error,
        ]);
    }

    protected function preview(string $value, int $limit): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized) || $normalized === '') {
            return '';
        }

        return mb_substr($normalized, 0, $limit);
    }
}
