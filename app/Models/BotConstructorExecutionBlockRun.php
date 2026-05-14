<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class BotConstructorExecutionBlockRun extends Model
{
    use HasFactory;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_NO_REPLY = 'no_reply';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELIVERY_UNCERTAIN = 'delivery_uncertain';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bot_constructor_execution_id',
        'bot_constructor_block_id',
        'bot_constructor_arrow_run_id',
        'dialog_id',
        'channel_id',
        'sequence_number',
        'status',
        'outbound_message_id',
        'processing_started_at',
        'error_message',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sequence_number' => 'integer',
        'processing_started_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (BotConstructorExecutionBlockRun $run): void {
            if (! in_array($run->status, self::statuses(), true)) {
                throw ValidationException::withMessages([
                    'status' => 'Неизвестный статус выполнения блока конструктора.',
                ]);
            }

            $run->sequence_number = max(1, (int) $run->sequence_number);

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
            self::STATUS_PROCESSING,
            self::STATUS_SENT,
            self::STATUS_NO_REPLY,
            self::STATUS_FAILED,
            self::STATUS_DELIVERY_UNCERTAIN,
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(BotConstructorExecution::class, 'bot_constructor_execution_id');
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(BotConstructorBlock::class, 'bot_constructor_block_id')->withTrashed();
    }

    public function arrowRun(): BelongsTo
    {
        return $this->belongsTo(BotConstructorArrowRun::class, 'bot_constructor_arrow_run_id');
    }

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'outbound_message_id');
    }
}
