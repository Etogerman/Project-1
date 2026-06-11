<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AiPricingRate extends Model
{
    public const CURRENCY_USD = 'USD';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'model',
        'input_price_per_1m_tokens',
        'output_price_per_1m_tokens',
        'thinking_price_per_1m_tokens',
        'currency',
        'effective_from',
        'is_active',
        'comment',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'input_price_per_1m_tokens' => 'decimal:8',
        'output_price_per_1m_tokens' => 'decimal:8',
        'thinking_price_per_1m_tokens' => 'decimal:8',
        'effective_from' => 'date',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (AiPricingRate $rate): void {
            $rate->provider = strtolower(trim((string) $rate->provider));
            $rate->model = trim((string) $rate->model);
            $rate->currency = self::normalizeCurrency($rate->currency);
            $rate->comment = filled($rate->comment) ? trim((string) $rate->comment) : null;
        });
    }

    public static function normalizeCurrency(mixed $currency): string
    {
        return strtoupper(trim((string) $currency)) === self::CURRENCY_USD
            ? self::CURRENCY_USD
            : self::CURRENCY_USD;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
