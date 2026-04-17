<?php

namespace App\Models;

use App\Services\Bitrix24\HashBitrix24ApplicationTokenAction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bitrix24WebhookEvent extends Model
{
    use HasFactory;

    public const TYPE_INSTALL = 'install';

    public const TYPE_EVENTS = 'events';

    public const TYPE_OPENLINES = 'openlines';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_IGNORED = 'ignored';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'connection_id',
        'callback_type',
        'event_name',
        'member_id',
        'application_token',
        'application_token_hash',
        'portal_domain',
        'payload_hash',
        'payload',
        'headers',
        'query',
        'processing_status',
        'processed_at',
        'failed_at',
        'failure_reason',
        'recheck_scheduled_at',
        'recheck_attempted_at',
        'attempts',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'query' => 'array',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
        'recheck_scheduled_at' => 'datetime',
        'recheck_attempted_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'application_token',
        'application_token_hash',
    ];

    public function setApplicationTokenAttribute(mixed $value): void
    {
        $this->attributes['application_token'] = '';
        $this->attributes['application_token_hash'] = app(HashBitrix24ApplicationTokenAction::class)->handle($value);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Bitrix24Connection::class, 'connection_id');
    }
}
