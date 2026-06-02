<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiTask extends Model
{
    public const KEY_NAME_RESOLUTION = 'name_resolution';

    public const KEY_ADDRESS_RESOLUTION = 'address_resolution';

    public const KEY_SCENARIO_V3_AI_ANALYSIS = 'scenario_v3_ai_analysis';

    public const KEY_OTHER = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (AiTask $task): void {
            $task->key = self::normalizeKey($task->key);
            $task->name = trim((string) $task->name);
        });
    }

    public static function normalizeKey(mixed $key): string
    {
        return Str::of(is_scalar($key) ? (string) $key : '')
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->trim('_')
            ->limit(64, '')
            ->toString();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function aiRequests(): HasMany
    {
        return $this->hasMany(AiRequest::class);
    }
}
