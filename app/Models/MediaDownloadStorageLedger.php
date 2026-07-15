<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaDownloadStorageLedger extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_USED = 'used';

    public const STATUS_RELEASED = 'released';

    /** @var list<string> */
    protected $fillable = [
        'message_attachment_id',
        'channel_id',
        'generation',
        'status',
        'reserved_bytes',
        'used_bytes',
        'release_reason',
        'expires_at',
        'released_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'generation' => 'integer',
        'reserved_bytes' => 'integer',
        'used_bytes' => 'integer',
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
