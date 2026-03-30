<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
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
        $url = sprintf(
            '%s/models/%s:generateContent',
            rtrim((string) config('bots.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
            $this->model(),
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
                    'maxOutputTokens' => (int) config('bots.gemini.max_output_tokens', 128),
                    'responseMimeType' => 'application/json',
                    'responseJsonSchema' => $responseJsonSchema,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Gemini API request failed [%d]: %s',
                $response->status(),
                trim($response->body())
            ));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Gemini API returned an invalid response payload.');
        }

        $text = data_get($payload, 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('Gemini API returned an empty structured response.');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
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
}
