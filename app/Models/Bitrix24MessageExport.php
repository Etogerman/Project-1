<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bitrix24MessageExport extends Model
{
    use HasFactory;

    public const MODE_HISTORY = 'history';

    public const MODE_LIVE = 'live';

    public const STATUS_PENDING = 'pending';

    public const STATUS_EXPORTED = 'exported';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'contact_id',
        'bitrix24_contact_id',
        'export_mode',
        'export_status',
        'batch_uuid',
        'bitrix24_timeline_entry_id',
        'exported_at',
        'failed_at',
        'failure_reason',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'exported_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
