<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class BotConstructorExecution extends Model
{
    use HasFactory;

    public const TRIGGER_INBOUND = 'inbound';

    public const TRIGGER_SCHEDULED_ARROW = 'scheduled_arrow';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_STOPPED_BY_LIMIT = 'stopped_by_limit';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'root_inbound_message_id',
        'parent_execution_id',
        'started_by_arrow_run_id',
        'dialog_id',
        'channel_id',
        'trigger_type',
        'auto_transition_count',
        'next_sequence_number',
        'status',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'auto_transition_count' => 'integer',
        'next_sequence_number' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (BotConstructorExecution $execution): void {
            $execution->trigger_type = trim((string) $execution->trigger_type);
            $execution->status = trim((string) $execution->status);
            $execution->auto_transition_count = max(0, (int) $execution->auto_transition_count);
            $execution->next_sequence_number = max(1, (int) $execution->next_sequence_number);

            if (! in_array($execution->trigger_type, self::triggerTypes(), true)) {
                throw ValidationException::withMessages([
                    'trigger_type' => 'Неизвестный тип запуска конструктора.',
                ]);
            }

            if (! in_array($execution->status, self::statuses(), true)) {
                throw ValidationException::withMessages([
                    'status' => 'Неизвестный статус выполнения конструктора.',
                ]);
            }
        });
    }

    /**
     * @return list<string>
     */
    public static function triggerTypes(): array
    {
        return [
            self::TRIGGER_INBOUND,
            self::TRIGGER_SCHEDULED_ARROW,
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_RUNNING,
            self::STATUS_COMPLETED,
            self::STATUS_STOPPED_BY_LIMIT,
            self::STATUS_FAILED,
        ];
    }

    public function rootInboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'root_inbound_message_id');
    }

    public function parentExecution(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_execution_id');
    }

    public function childExecutions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_execution_id');
    }

    public function startedByArrowRun(): BelongsTo
    {
        return $this->belongsTo(BotConstructorArrowRun::class, 'started_by_arrow_run_id');
    }

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function arrowRuns(): HasMany
    {
        return $this->hasMany(BotConstructorArrowRun::class, 'bot_constructor_execution_id');
    }

    public function blockRuns(): HasMany
    {
        return $this->hasMany(BotConstructorExecutionBlockRun::class, 'bot_constructor_execution_id');
    }
}
