<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class BotConstructorBlockRun extends Model
{
    use HasFactory;

    public const STATUS_SENT = 'sent';

    public const STATUS_NO_REPLY = 'no_reply';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'inbound_message_id',
        'bot_constructor_block_id',
        'outbound_message_id',
        'status',
        'error_message',
    ];

    protected static function booted(): void
    {
        static::saving(function (BotConstructorBlockRun $run): void {
            if (! in_array($run->status, self::statuses(), true)) {
                throw ValidationException::withMessages([
                    'status' => 'Неизвестный статус срабатывания блока.',
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
            self::STATUS_SENT,
            self::STATUS_NO_REPLY,
            self::STATUS_FAILED,
        ];
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'inbound_message_id');
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(BotConstructorBlock::class, 'bot_constructor_block_id');
    }

    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'outbound_message_id');
    }
}
