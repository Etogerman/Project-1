<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactDuplicateReview extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_DISMISSED = 'dismissed';

    public const TYPE_PHONE_MULTIPLE_ROOTS = 'phone_multiple_roots';

    public const TYPE_PHONE_OTHER_ROOT_CANDIDATE = 'phone_other_root_candidate';

    public const TYPE_MERGE_CONFLICT = 'merge_conflict';

    public const TYPE_BROKEN_MERGE_CHAIN = 'broken_merge_chain';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'phone_normalized',
        'review_type',
        'candidate_root_contact_ids',
        'trigger_message_id',
        'status',
        'reason',
        'resolved_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'candidate_root_contact_ids' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function triggerMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'trigger_message_id');
    }
}
