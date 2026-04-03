<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactStartTag extends Model
{
    use HasFactory;

    public const CATEGORY_START_PAYLOAD = 'start_payload';

    public const SOURCE_TELEGRAM_START = 'telegram_start';

    public const SOURCE_MAX_START = 'max_start';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'category',
        'code',
        'source',
        'source_message_id',
        'assigned_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }
}
