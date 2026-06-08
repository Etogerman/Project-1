<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AiProcessor extends Model
{
    public const PROVIDER_GEMINI = 'gemini';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'provider',
        'model',
        'base_url',
        'credentials',
        'api_key_present',
        'is_active',
        'priority',
        'timeout_seconds',
        'temperature',
        'max_output_tokens',
        'thinking_budget',
        'last_failed_at',
        'last_error_message',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'credentials' => 'encrypted:array',
        'api_key_present' => 'boolean',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'timeout_seconds' => 'integer',
        'temperature' => 'float',
        'max_output_tokens' => 'integer',
        'thinking_budget' => 'integer',
        'last_failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (AiProcessor $processor): void {
            $processor->provider = static::normalizeProvider($processor->provider);
            $processor->name = trim((string) $processor->name);
            $processor->model = static::nullableTrim($processor->model);
            $processor->base_url = static::nullableTrim($processor->base_url);
            $processor->priority = max(0, (int) $processor->priority);
            $processor->timeout_seconds = max(1, (int) $processor->timeout_seconds);
            $processor->max_output_tokens = max(1, (int) $processor->max_output_tokens);
            $processor->api_key_present = filled($processor->apiKeyFromCredentials());
        });
    }

    /**
     * @return array<string, string>
     */
    public static function providerOptions(): array
    {
        return [
            self::PROVIDER_GEMINI => 'Gemini',
        ];
    }

    public static function normalizeProvider(mixed $value): string
    {
        $provider = strtolower(trim((string) $value));

        return array_key_exists($provider, self::providerOptions())
            ? $provider
            : self::PROVIDER_GEMINI;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy('priority')
            ->orderBy('id');
    }

    public function apiKey(): ?string
    {
        $apiKey = $this->apiKeyFromCredentials();

        if (filled($apiKey)) {
            return $apiKey;
        }

        if ($this->provider === self::PROVIDER_GEMINI) {
            $fallback = trim((string) config('bots.gemini.api_key', ''));

            return $fallback !== '' ? $fallback : null;
        }

        return null;
    }

    public function hasApiKeyConfigured(): bool
    {
        return filled($this->apiKey());
    }

    public function apiKeyStatusLabel(): string
    {
        if (filled($this->apiKeyFromCredentials())) {
            return 'В настройках';
        }

        if ($this->provider === self::PROVIDER_GEMINI && filled(config('bots.gemini.api_key'))) {
            return 'Из .env';
        }

        return 'Не задан';
    }

    /**
     * @return array<string, mixed>
     */
    public function structuredSettings(): array
    {
        return [
            'api_key' => $this->apiKey(),
            'model' => $this->model,
            'base_url' => $this->base_url,
            'temperature' => $this->temperature,
            'max_output_tokens' => $this->max_output_tokens,
            'thinking_budget' => $this->thinking_budget,
            'timeout_seconds' => $this->timeout_seconds,
        ];
    }

    public function apiKeyFromCredentials(): ?string
    {
        $apiKey = Arr::get($this->credentials ?? [], 'api_key');

        return filled($apiKey) ? trim((string) $apiKey) : null;
    }

    private static function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
