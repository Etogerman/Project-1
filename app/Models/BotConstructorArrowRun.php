<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class BotConstructorArrowRun extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PASSED = 'passed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_LIMIT_REACHED = 'limit_reached';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bot_constructor_execution_id',
        'bot_constructor_arrow_id',
        'dialog_id',
        'source_block_id',
        'target_block_id',
        'inbound_message_id',
        'scheduled_for',
        'processing_started_at',
        'status',
        'error_message',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'scheduled_for' => 'datetime',
        'processing_started_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (BotConstructorArrowRun $run): void {
            if (! in_array($run->status, self::statuses(), true)) {
                throw ValidationException::withMessages([
                    'status' => 'Неизвестный статус прохода соединения.',
                ]);
            }

            if (filled($run->error_message)) {
                $run->error_message = mb_substr(trim((string) $run->error_message), 0, 1000);
            }
        });
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_SCHEDULED,
            self::STATUS_PROCESSING,
            self::STATUS_PASSED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
            self::STATUS_LIMIT_REACHED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function limitCountedStatuses(): array
    {
        return [
            self::STATUS_SCHEDULED,
            self::STATUS_PROCESSING,
            self::STATUS_PASSED,
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(BotConstructorExecution::class, 'bot_constructor_execution_id');
    }

    public function arrow(): BelongsTo
    {
        return $this->belongsTo(BotConstructorArrow::class, 'bot_constructor_arrow_id');
    }

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function sourceBlock(): BelongsTo
    {
        return $this->belongsTo(BotConstructorBlock::class, 'source_block_id');
    }

    public function targetBlock(): BelongsTo
    {
        return $this->belongsTo(BotConstructorBlock::class, 'target_block_id');
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'inbound_message_id');
    }
}
