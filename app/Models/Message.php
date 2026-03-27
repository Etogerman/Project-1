<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    public const DIRECTION_INBOUND = 'inbound';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'contact_identity_id',
        'channel_id',
        'direction',
        'external_chat_id',
        'external_message_id',
        'text',
        'raw_payload',
        'received_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'raw_payload' => 'array',
        'received_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function contactIdentity(): BelongsTo
    {
        return $this->belongsTo(ContactIdentity::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
