<?php

namespace App\Models;

use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ScenarioChannelBinding extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'scenario_code',
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
        static::saving(function (ScenarioChannelBinding $binding): void {
            $binding->guardScenarioCode();
        });
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected function guardScenarioCode(): void
    {
        $scenarioCode = is_string($this->scenario_code)
            ? trim($this->scenario_code)
            : null;

        if (! filled($scenarioCode)) {
            throw ValidationException::withMessages([
                'scenario_code' => 'Нужно указать код сценария.',
            ]);
        }

        if (! app(ScenarioRegistry::class)->has($scenarioCode)) {
            throw ValidationException::withMessages([
                'scenario_code' => 'Неизвестный код сценария.',
            ]);
        }

        $this->scenario_code = $scenarioCode;
    }
}
