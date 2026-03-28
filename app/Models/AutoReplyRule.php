<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class AutoReplyRule extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'keyword',
        'normalized_keyword',
        'reply_text',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function normalizeKeyword(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return mb_strtolower(trim((string) $value));
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected function keyword(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): array => [
                'keyword' => filled($value) ? trim((string) $value) : $value,
                'normalized_keyword' => static::normalizeKeyword($value),
            ],
        );
    }
}
