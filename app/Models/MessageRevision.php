<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageRevision extends Model
{
    use HasFactory;

    public const TYPE_EDIT = 'edit';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'revision_type',
        'provider_event_key',
        'provider_edited_at',
        'previous_text',
        'previous_rich_text',
        'previous_raw_payload',
        'new_text',
        'new_rich_text',
        'new_raw_payload',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'provider_edited_at' => 'datetime',
        'previous_rich_text' => 'array',
        'previous_raw_payload' => 'array',
        'new_rich_text' => 'array',
        'new_raw_payload' => 'array',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
