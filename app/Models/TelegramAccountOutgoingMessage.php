<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAccountOutgoingMessage extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'dialog_id',
        'message_id',
        'external_chat_id',
        'text',
        'text_format',
        'dedupe_key',
        'status',
        'attempts',
        'claimed_at',
        'sent_at',
        'failed_at',
        'sent_external_message_id',
        'last_error_message',
        'result_payload',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'attempts' => 'integer',
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
        'result_payload' => 'array',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
