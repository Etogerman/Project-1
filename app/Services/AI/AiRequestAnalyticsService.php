<?php

namespace App\Services\AI;

use App\Data\AI\AiGenerationContext;
use App\Data\AI\AiProviderStructuredResult;
use App\Models\AiPricingRate;
use App\Models\AiProcessor;
use App\Models\AiRequest;
use App\Models\AiRequestAttempt;
use App\Models\AiTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AiRequestAnalyticsService
{
    private const RAW_BODY_LIMIT_BYTES = 65536;

    public function start(
        AiGenerationContext $context,
        string $systemPrompt,
        string $userPrompt,
    ): ?AiRequest {
        if (! $this->tablesReady()) {
            return null;
        }

        try {
            $task = AiTask::query()->where('key', $context->taskKey)->first();

            return AiRequest::query()->create([
                'correlation_id' => $context->correlationId ?: (string) Str::uuid(),
                'ai_task_id' => $task?->id,
                'task_key' => $context->taskKey,
                'status' => AiRequest::STATUS_ERROR,
                'contact_id' => $context->contactId,
                'dialog_id' => $context->dialogId,
                'channel_id' => $context->channelId,
                'scenario_id' => $context->scenarioId,
                'scenario_block_id' => $context->scenarioBlockId,
                'prompt_key' => $context->promptKey,
                'prompt_hash' => hash('sha256', $systemPrompt."\n---\n".$userPrompt),
                'system_prompt_preview' => $this->preview($systemPrompt, 1000),
                'user_prompt_preview' => $this->preview($userPrompt, 1000),
                'started_at' => now(),
            ]);
        } catch (Throwable $throwable) {
            $this->logFailure('ai.analytics_start_failed', $throwable, [
                'task_key' => $context->taskKey,
                'contact_id' => $context->contactId,
            ]);

            return null;
        }
    }

    public function recordSuccessAttempt(
        ?AiRequest $request,
        int $attemptNumber,
        ?AiProcessor $processor,
        AiProviderStructuredResult $result,
        Carbon $startedAt,
        Carbon $finishedAt,
    ): ?AiRequestAttempt {
        if (! $request instanceof AiRequest) {
            return null;
        }

        try {
            $cost = $this->costFor(
                provider: $result->provider,
                model: $result->model,
                inputTokens: $result->inputTokens,
                outputTokens: $result->outputTokens,
                thinkingTokens: $result->thinkingTokens,
                at: $startedAt,
            );
            $requestBody = $this->truncateRawBody($result->requestBodyRaw);
            $responseBody = $this->truncateRawBody($result->responseBodyRaw);

            return AiRequestAttempt::query()->create([
                'ai_request_id' => $request->id,
                'ai_processor_id' => $processor?->id,
                'attempt_number' => $attemptNumber,
                'provider' => $result->provider,
                'model' => $result->model,
                'status' => AiRequestAttempt::STATUS_SUCCESS,
                'http_status' => $result->httpStatus,
                'request_body_raw' => $requestBody['value'],
                'response_body_raw' => $responseBody['value'],
                'raw_body_truncated' => $requestBody['truncated'] || $responseBody['truncated'],
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'thinking_tokens' => $result->thinkingTokens,
                'total_tokens' => $result->totalTokens ?? $this->sumNullableTokens($result->inputTokens, $result->outputTokens, $result->thinkingTokens),
                'estimated_cost' => $cost['estimated_cost'],
                'provider_reported_cost' => $result->providerReportedCost,
                'provider_reported_currency' => $result->providerReportedCurrency,
                'currency' => $cost['currency'],
                'cost_status' => $cost['cost_status'],
                'response_preview' => $this->preview($result->responseBodyRaw, 1000),
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'latency_ms' => (int) max(0, round($startedAt->diffInMilliseconds($finishedAt))),
            ]);
        } catch (Throwable $throwable) {
            $this->logFailure('ai.analytics_attempt_success_failed', $throwable, [
                'ai_request_id' => $request->id,
                'attempt_number' => $attemptNumber,
            ]);

            return null;
        }
    }

    public function recordErrorAttempt(
        ?AiRequest $request,
        int $attemptNumber,
        ?AiProcessor $processor,
        AiProviderRequestException $exception,
        Carbon $startedAt,
        Carbon $finishedAt,
    ): ?AiRequestAttempt {
        if (! $request instanceof AiRequest) {
            return null;
        }

        try {
            $requestBody = $this->truncateRawBody($exception->requestBodyRaw);
            $responseBody = $this->truncateRawBody($exception->responseBodyRaw);
            $cost = $this->costFor(
                provider: $exception->provider,
                model: $exception->model,
                inputTokens: $exception->inputTokens,
                outputTokens: $exception->outputTokens,
                thinkingTokens: $exception->thinkingTokens,
                at: $startedAt,
            );

            return AiRequestAttempt::query()->create([
                'ai_request_id' => $request->id,
                'ai_processor_id' => $processor?->id,
                'attempt_number' => $attemptNumber,
                'provider' => $exception->provider,
                'model' => $exception->model,
                'status' => AiRequestAttempt::STATUS_ERROR,
                'http_status' => $exception->httpStatus,
                'request_body_raw' => $requestBody['value'],
                'response_body_raw' => $responseBody['value'],
                'raw_body_truncated' => $requestBody['truncated'] || $responseBody['truncated'],
                'input_tokens' => $exception->inputTokens,
                'output_tokens' => $exception->outputTokens,
                'thinking_tokens' => $exception->thinkingTokens,
                'total_tokens' => $exception->totalTokens ?? $this->sumNullableTokens($exception->inputTokens, $exception->outputTokens, $exception->thinkingTokens),
                'estimated_cost' => $cost['estimated_cost'],
                'currency' => $cost['currency'],
                'cost_status' => $cost['cost_status'],
                'error_message' => $this->safeErrorMessage($exception),
                'response_preview' => $this->preview($exception->responseBodyRaw, 1000),
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'latency_ms' => (int) max(0, round($startedAt->diffInMilliseconds($finishedAt))),
            ]);
        } catch (Throwable $throwable) {
            $this->logFailure('ai.analytics_attempt_error_failed', $throwable, [
                'ai_request_id' => $request->id,
                'attempt_number' => $attemptNumber,
            ]);

            return null;
        }
    }

    public function finalize(?AiRequest $request, ?AiRequestAttempt $representativeAttempt, bool $success): void
    {
        if (! $request instanceof AiRequest) {
            return;
        }

        try {
            $attempts = $request->attempts()->orderBy('attempt_number')->get();
            $tokenTotals = [
                'input_tokens' => $this->sumAttemptColumn($attempts, 'input_tokens'),
                'output_tokens' => $this->sumAttemptColumn($attempts, 'output_tokens'),
                'thinking_tokens' => $this->sumAttemptColumn($attempts, 'thinking_tokens'),
                'total_tokens' => $this->sumAttemptColumn($attempts, 'total_tokens'),
            ];
            $costStatus = $this->overallCostStatus($attempts);
            $estimatedCost = $this->sumAttemptCosts($attempts);
            $representative = $representativeAttempt instanceof AiRequestAttempt
                ? $representativeAttempt
                : $attempts->last();

            $request->forceFill([
                'status' => $success ? AiRequest::STATUS_SUCCESS : AiRequest::STATUS_ERROR,
                'final_attempt_id' => $success ? $representative?->id : null,
                'provider' => $representative?->provider,
                'model' => $representative?->model,
                'request_body_raw' => $representative?->request_body_raw,
                'response_body_raw' => $representative?->response_body_raw,
                'raw_body_truncated' => (bool) ($representative?->raw_body_truncated ?? false),
                'response_preview' => $representative?->response_preview,
                'input_tokens' => $tokenTotals['input_tokens'],
                'output_tokens' => $tokenTotals['output_tokens'],
                'thinking_tokens' => $tokenTotals['thinking_tokens'],
                'total_tokens' => $tokenTotals['total_tokens'],
                'estimated_cost' => $estimatedCost,
                'provider_reported_cost' => $representative?->provider_reported_cost,
                'provider_reported_currency' => $representative?->provider_reported_currency,
                'currency' => $estimatedCost !== null ? AiPricingRate::CURRENCY_USD : null,
                'cost_status' => $costStatus,
                'finished_at' => now(),
                'latency_ms' => $request->started_at instanceof Carbon
                    ? (int) max(0, round($request->started_at->diffInMilliseconds(now())))
                    : null,
            ])->save();
        } catch (Throwable $throwable) {
            $this->logFailure('ai.analytics_finalize_failed', $throwable, [
                'ai_request_id' => $request->id,
            ]);
        }
    }

    /**
     * @return array{estimated_cost: ?string, currency: ?string, cost_status: string}
     */
    private function costFor(
        string $provider,
        string $model,
        ?int $inputTokens,
        ?int $outputTokens,
        ?int $thinkingTokens,
        Carbon $at,
    ): array {
        if ($inputTokens === null && $outputTokens === null && $thinkingTokens === null) {
            return [
                'estimated_cost' => null,
                'currency' => null,
                'cost_status' => AiRequest::COST_STATUS_MISSING_USAGE,
            ];
        }

        $rate = AiPricingRate::query()
            ->active()
            ->where('provider', strtolower($provider))
            ->where('model', $model)
            ->where('currency', AiPricingRate::CURRENCY_USD)
            ->whereDate('effective_from', '<=', $at->toDateString())
            ->orderByDesc('effective_from')
            ->first();

        if (! $rate instanceof AiPricingRate) {
            return [
                'estimated_cost' => null,
                'currency' => null,
                'cost_status' => AiRequest::COST_STATUS_MISSING_TARIFF,
            ];
        }

        $cost = (($inputTokens ?? 0) * (float) $rate->input_price_per_1m_tokens
            + ($outputTokens ?? 0) * (float) $rate->output_price_per_1m_tokens
            + ($thinkingTokens ?? 0) * (float) $rate->thinking_price_per_1m_tokens) / 1_000_000;

        return [
            'estimated_cost' => number_format($cost, 8, '.', ''),
            'currency' => AiPricingRate::CURRENCY_USD,
            'cost_status' => AiRequest::COST_STATUS_CALCULATED,
        ];
    }

    /**
     * @param  Collection<int, AiRequestAttempt>  $attempts
     */
    private function overallCostStatus($attempts): ?string
    {
        if ($attempts->isEmpty()) {
            return null;
        }

        $statuses = $attempts
            ->pluck('cost_status')
            ->filter()
            ->unique()
            ->values();

        if ($statuses->isEmpty()) {
            return AiRequest::COST_STATUS_MISSING_USAGE;
        }

        if ($statuses->count() === 1) {
            return (string) $statuses->first();
        }

        if ($statuses->contains(AiRequest::COST_STATUS_CALCULATED)) {
            return AiRequest::COST_STATUS_PARTIAL;
        }

        if ($statuses->contains(AiRequest::COST_STATUS_MISSING_USAGE)) {
            return AiRequest::COST_STATUS_MISSING_USAGE;
        }

        return AiRequest::COST_STATUS_MISSING_TARIFF;
    }

    /**
     * @param  Collection<int, AiRequestAttempt>  $attempts
     */
    private function sumAttemptColumn($attempts, string $column): ?int
    {
        $values = $attempts
            ->pluck($column)
            ->filter(fn (mixed $value): bool => is_numeric($value));

        return $values->isEmpty() ? null : (int) $values->sum();
    }

    /**
     * @param  Collection<int, AiRequestAttempt>  $attempts
     */
    private function sumAttemptCosts($attempts): ?string
    {
        $values = $attempts
            ->pluck('estimated_cost')
            ->filter(fn (mixed $value): bool => is_numeric($value));

        if ($values->isEmpty()) {
            return null;
        }

        return number_format((float) $values->sum(), 8, '.', '');
    }

    private function sumNullableTokens(?int ...$tokens): ?int
    {
        $present = array_filter($tokens, fn (?int $token): bool => $token !== null);

        return $present === [] ? null : array_sum($present);
    }

    /**
     * @return array{value: string, truncated: bool}
     */
    private function truncateRawBody(string $value): array
    {
        $clean = $this->removeSecretFragments($value);

        if (strlen($clean) <= self::RAW_BODY_LIMIT_BYTES) {
            return ['value' => $clean, 'truncated' => false];
        }

        return [
            'value' => mb_strcut($clean, 0, self::RAW_BODY_LIMIT_BYTES),
            'truncated' => true,
        ];
    }

    private function preview(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, $limit);
    }

    private function removeSecretFragments(string $value): string
    {
        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            array_walk_recursive($decoded, function (mixed &$item, string|int $key): void {
                if (preg_match('/(?:api[_-]?key|access[_-]?token|refresh[_-]?token|auth(?:orization)?|secret|cookie|password)/iu', (string) $key) === 1) {
                    $item = '[secret]';
                }
            });

            $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (is_string($encoded)) {
                return $encoded;
            }
        }

        return preg_replace('/([?&](?:token|access_token|auth|secret|api_key)=)[^&\s"]+/iu', '$1[secret]', $value) ?? $value;
    }

    private function safeErrorMessage(Throwable $throwable): string
    {
        $message = preg_replace('/\s+/u', ' ', trim($throwable->getMessage()));

        if (! is_string($message) || $message === '') {
            return 'ИИ-запрос завершился ошибкой.';
        }

        return mb_substr($message, 0, 1000);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logFailure(string $event, Throwable $throwable, array $context): void
    {
        Log::warning($event, $context + [
            'exception' => get_class($throwable),
            'error_message' => $this->safeErrorMessage($throwable),
        ]);
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('ai_requests')
            && Schema::hasTable('ai_request_attempts')
            && Schema::hasTable('ai_tasks');
    }
}
