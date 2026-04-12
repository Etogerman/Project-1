<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactTimelineEvent extends Model
{
    use HasFactory;

    public const EVENT_OPERATOR_COMMENT = 'operator_comment';

    public const EVENT_FIRST_NAME_CHANGED = 'contact.first_name_changed';

    public const EVENT_MERGE_NAME_CONFLICT = 'contact.merge_name_conflict';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'event_type',
        'actor_user_id',
        'body',
        'payload',
        'occurred_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
