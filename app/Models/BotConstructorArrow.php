<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class BotConstructorArrow extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const DELAY_UNIT_SECONDS = 'seconds';

    public const DELAY_UNIT_MINUTES = 'minutes';

    public const DELAY_UNIT_HOURS = 'hours';

    public const DELAY_UNIT_DAYS = 'days';

    public const CONDITION_ALWAYS = 'always';

    public const CONDITION_EXACT_TEXT = 'exact_text';

    public const CONDITION_CONTAINS_TEXT = 'contains_text';

    public const PASS_LIMIT_MODE_CONSTANT = 'constant';

    public const PASS_LIMIT_MODE_MANUAL = 'manual';

    private const MAX_DELAY_SECONDS = 30 * 24 * 60 * 60;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source_block_id',
        'target_block_id',
        'is_active',
        'delay_value',
        'delay_unit',
        'cancel_if_left_source_block',
        'condition_match_type',
        'condition_value',
        'priority',
        'pass_limit_mode',
        'pass_limit_value',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'cancel_if_left_source_block' => 'boolean',
        'delay_value' => 'integer',
        'priority' => 'integer',
        'pass_limit_value' => 'integer',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (BotConstructorArrow $arrow): void {
            $arrow->delay_value = max(0, (int) $arrow->delay_value);
            $arrow->priority = (int) $arrow->priority;
            $arrow->delay_unit = trim((string) $arrow->delay_unit);
            $arrow->condition_match_type = trim((string) $arrow->condition_match_type);
            $arrow->pass_limit_mode = trim((string) $arrow->pass_limit_mode);

            if ($arrow->condition_value !== null) {
                $arrow->condition_value = trim((string) $arrow->condition_value);
            }

            if ($arrow->pass_limit_mode === self::PASS_LIMIT_MODE_CONSTANT) {
                $arrow->pass_limit_value = null;
            }

            $arrow->guardBlocks();
            $arrow->guardDelay();
            $arrow->guardCondition();
            $arrow->guardPassLimit();
        });
    }

    /**
     * @return list<string>
     */
    public static function delayUnits(): array
    {
        return [
            self::DELAY_UNIT_SECONDS,
            self::DELAY_UNIT_MINUTES,
            self::DELAY_UNIT_HOURS,
            self::DELAY_UNIT_DAYS,
        ];
    }

    /**
     * @return list<string>
     */
    public static function conditionTypes(): array
    {
        return [
            self::CONDITION_ALWAYS,
            self::CONDITION_EXACT_TEXT,
            self::CONDITION_CONTAINS_TEXT,
        ];
    }

    /**
     * @return list<string>
     */
    public static function passLimitModes(): array
    {
        return [
            self::PASS_LIMIT_MODE_CONSTANT,
            self::PASS_LIMIT_MODE_MANUAL,
        ];
    }

    public function sourceBlock(): BelongsTo
    {
        return $this->belongsTo(BotConstructorBlock::class, 'source_block_id');
    }

    public function targetBlock(): BelongsTo
    {
        return $this->belongsTo(BotConstructorBlock::class, 'target_block_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BotConstructorArrowRun::class, 'bot_constructor_arrow_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function delayInSeconds(): int
    {
        return match ($this->delay_unit) {
            self::DELAY_UNIT_MINUTES => (int) $this->delay_value * 60,
            self::DELAY_UNIT_HOURS => (int) $this->delay_value * 60 * 60,
            self::DELAY_UNIT_DAYS => (int) $this->delay_value * 24 * 60 * 60,
            default => (int) $this->delay_value,
        };
    }

    private function guardBlocks(): void
    {
        $errors = [];

        if ((int) $this->source_block_id <= 0) {
            $errors['source_block_id'] = 'Укажите исходный блок.';
        }

        if ((int) $this->target_block_id <= 0) {
            $errors['target_block_id'] = 'Укажите целевой блок.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function guardDelay(): void
    {
        if (! in_array($this->delay_unit, self::delayUnits(), true)) {
            throw ValidationException::withMessages([
                'delay_unit' => 'Выберите поддерживаемую единицу задержки.',
            ]);
        }

        if ($this->delayInSeconds() > self::MAX_DELAY_SECONDS) {
            throw ValidationException::withMessages([
                'delay_value' => 'Задержка не может быть больше 30 дней.',
            ]);
        }
    }

    private function guardCondition(): void
    {
        if (! in_array($this->condition_match_type, self::conditionTypes(), true)) {
            throw ValidationException::withMessages([
                'condition_match_type' => 'Выберите поддерживаемый тип условия.',
            ]);
        }

        if ($this->condition_match_type === self::CONDITION_ALWAYS) {
            $this->condition_value = null;

            return;
        }

        if (! filled($this->condition_value)) {
            throw ValidationException::withMessages([
                'condition_value' => 'Укажите условие соединения.',
            ]);
        }
    }

    private function guardPassLimit(): void
    {
        if (! in_array($this->pass_limit_mode, self::passLimitModes(), true)) {
            throw ValidationException::withMessages([
                'pass_limit_mode' => 'Выберите поддерживаемый режим лимита.',
            ]);
        }

        if ($this->pass_limit_mode === self::PASS_LIMIT_MODE_MANUAL && (int) $this->pass_limit_value < 1) {
            throw ValidationException::withMessages([
                'pass_limit_value' => 'Лимит переходов должен быть больше 0.',
            ]);
        }
    }
}
