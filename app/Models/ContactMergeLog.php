<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMergeLog extends Model
{
    use HasFactory;

    public const CREATED_BY_TYPE_SYSTEM = 'system';

    public const CREATED_BY_TYPE_USER = 'user';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'primary_contact_id',
        'secondary_contact_id',
        'trigger_phone',
        'trigger_message_id',
        'merge_reason',
        'messages_moved_count',
        'identities_moved_count',
        'phones_moved_count',
        'fields_copied',
        'fields_conflicted',
        'created_by_type',
        'created_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'fields_copied' => 'array',
        'fields_conflicted' => 'array',
        'messages_moved_count' => 'integer',
        'identities_moved_count' => 'integer',
        'phones_moved_count' => 'integer',
        'created_at' => 'datetime',
    ];

    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'primary_contact_id');
    }

    public function secondaryContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'secondary_contact_id');
    }

    public function triggerMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'trigger_message_id');
    }
}
