<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class GeminiApiService
{
    /**
     * @param  array<string, mixed>  $responseJsonSchema
     * @return array<string, mixed>
     */
    public function generateStructured(string $systemPrompt, string $userPrompt, array $responseJsonSchema): array
    {
        $apiKey = $this->apiKey();
        $model = $this->model();
        $url = sprintf(
            '%s/models/%s:generateContent',
            rtrim((string) config('bots.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
            $model,
        );

        $response = Http::asJson()
            ->withHeaders([
                'x-goog-api-key' => $apiKey,
            ])
            ->post($url, [
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
                    'temperature' => (float) config('bots.gemini.temperature', 0.2),
                    'maxOutputTokens' => (int) config('bots.gemini.max_output_tokens', 512),
                    'thinkingConfig' => [
                        'thinkingBudget' => (int) config('bots.gemini.thinking_budget', 0),
                    ],
                    'responseMimeType' => 'application/json',
                    'responseJsonSchema' => $responseJsonSchema,
                ],
            ]);

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

            throw new RuntimeException(sprintf(
                'Gemini API request failed [%d]: %s',
                $httpStatus,
                trim($rawBody)
            ));
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

            throw new RuntimeException('Gemini API returned an invalid response payload.');
        }

        $text = data_get($payload, 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            $this->logStructuredFailure(
                model: $model,
                httpStatus: $httpStatus,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                rawBody: $rawBody,
                error: 'Gemini API returned an empty structured response.',
            );

            throw new RuntimeException('Gemini API returned an empty structured response.');
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

            throw new RuntimeException(sprintf('Gemini API returned invalid JSON: %s', $text));
        }

        return $decoded;
    }

    protected function apiKey(): string
    {
        $apiKey = (string) config('bots.gemini.api_key', '');

        if ($apiKey === '') {
            throw new InvalidArgumentException('Gemini API key is not configured.');
        }

        return $apiKey;
    }

    protected function model(): string
    {
        $model = trim((string) config('bots.gemini.model', 'gemini-2.5-flash'));

        if ($model === '') {
            throw new InvalidArgumentException('Gemini model is not configured.');
        }

        return $model;
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
