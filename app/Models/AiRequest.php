<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiRequest extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_ERROR = 'error';

    public const COST_STATUS_CALCULATED = 'calculated';

    public const COST_STATUS_PARTIAL = 'partial';

    public const COST_STATUS_MISSING_TARIFF = 'missing_tariff';

    public const COST_STATUS_MISSING_USAGE = 'missing_usage';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'correlation_id',
        'ai_task_id',
        'task_key',
        'status',
        'contact_id',
        'dialog_id',
        'channel_id',
        'scenario_id',
        'scenario_block_id',
        'final_attempt_id',
        'provider',
        'model',
        'prompt_key',
        'prompt_hash',
        'request_body_raw',
        'response_body_raw',
        'raw_body_truncated',
        'system_prompt_preview',
        'user_prompt_preview',
        'response_preview',
        'input_tokens',
        'output_tokens',
        'thinking_tokens',
        'total_tokens',
        'estimated_cost',
        'provider_reported_cost',
        'provider_reported_currency',
        'currency',
        'cost_status',
        'started_at',
        'finished_at',
        'latency_ms',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'raw_body_truncated' => 'boolean',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'thinking_tokens' => 'integer',
        'total_tokens' => 'integer',
        'estimated_cost' => 'decimal:8',
        'provider_reported_cost' => 'decimal:8',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'latency_ms' => 'integer',
    ];

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            self::STATUS_SUCCESS => 'Успешно',
            self::STATUS_ERROR => 'Ошибка',
            default => 'Неизвестно',
        };
    }

    public static function costStatusLabel(?string $status): string
    {
        return match ($status) {
            self::COST_STATUS_CALCULATED => 'Рассчитана',
            self::COST_STATUS_PARTIAL => 'Частично',
            self::COST_STATUS_MISSING_TARIFF => 'Нет тарифа',
            self::COST_STATUS_MISSING_USAGE => 'Нет токенов',
            default => 'Не рассчитана',
        };
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AiTask::class, 'ai_task_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    public function finalAttempt(): BelongsTo
    {
        return $this->belongsTo(AiRequestAttempt::class, 'final_attempt_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AiRequestAttempt::class);
    }
}
