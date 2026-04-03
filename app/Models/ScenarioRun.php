<?php

namespace App\Models;

use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ScenarioRun extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scenario_code',
        'dialog_id',
        'status',
        'current_step',
        'state_payload',
        'exit_outcome',
        'started_at',
        'finished_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'state_payload' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (ScenarioRun $run): void {
            $run->guardScenarioCode();
            $run->guardLifecycle();
        });
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_FAILED => 'Failed',
        ];
    }

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
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

    protected function guardLifecycle(): void
    {
        $status = is_string($this->status)
            ? trim($this->status)
            : null;

        if (! in_array($status, array_keys(self::statusOptions()), true)) {
            throw ValidationException::withMessages([
                'status' => 'Неизвестный статус сценария.',
            ]);
        }

        if ($this->started_at === null) {
            throw ValidationException::withMessages([
                'started_at' => 'Нужно указать время запуска сценария.',
            ]);
        }

        $this->status = $status;
        $this->current_step = filled($this->current_step)
            ? trim((string) $this->current_step)
            : null;
        $this->exit_outcome = filled($this->exit_outcome)
            ? trim((string) $this->exit_outcome)
            : null;

        if ($this->isActive()) {
            if ($this->finished_at !== null) {
                throw ValidationException::withMessages([
                    'finished_at' => 'Активный сценарий не может иметь время завершения.',
                ]);
            }

            if ($this->exit_outcome !== null) {
                throw ValidationException::withMessages([
                    'exit_outcome' => 'Активный сценарий не может иметь итоговый outcome.',
                ]);
            }

            return;
        }

        if ($this->finished_at === null) {
            throw ValidationException::withMessages([
                'finished_at' => 'Завершённый сценарий должен иметь время завершения.',
            ]);
        }
    }
}
