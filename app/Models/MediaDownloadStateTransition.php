<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class MediaDownloadStateTransition extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_attachment_id',
        'channel_id',
        'previous_transition_id',
        'previous_generation',
        'generation',
        'actor_type',
        'actor_id',
        'action',
        'old_status',
        'new_status',
        'safe_reason',
        'expected_bytes',
        'actual_bytes',
        'transport',
        'correlation_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'message_attachment_id' => 'integer',
        'channel_id' => 'integer',
        'previous_transition_id' => 'integer',
        'previous_generation' => 'integer',
        'generation' => 'integer',
        'actor_id' => 'integer',
        'expected_bytes' => 'integer',
        'actual_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Inbound media state transition audit is append-only.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Inbound media state transition audit is append-only.');
        });
    }
}
