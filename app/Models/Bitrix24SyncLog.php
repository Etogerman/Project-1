<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bitrix24SyncLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_SYSTEM = 'system';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'connection_id',
        'direction',
        'operation',
        'entity_type',
        'entity_id',
        'request_payload',
        'response_payload',
        'status',
        'http_status',
        'error_code',
        'error_message',
        'fingerprint',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'http_status' => 'integer',
        'created_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Bitrix24Connection::class, 'connection_id');
    }
}
