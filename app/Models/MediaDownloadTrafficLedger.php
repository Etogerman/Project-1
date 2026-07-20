<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaDownloadTrafficLedger extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_RELEASED = 'released';

    /** @var list<string> */
    protected $fillable = [
        'message_attachment_id',
        'channel_id',
        'generation',
        'attempt_number',
        'period_date',
        'status',
        'reserved_bytes',
        'consumed_bytes',
        'checkpoint_bytes',
        'release_reason',
        'expires_at',
        'released_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'generation' => 'integer',
        'attempt_number' => 'integer',
        'period_date' => 'date',
        'reserved_bytes' => 'integer',
        'consumed_bytes' => 'integer',
        'checkpoint_bytes' => 'integer',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(MessageAttachment::class, 'message_attachment_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
