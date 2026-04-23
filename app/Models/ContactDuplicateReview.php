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

    public const TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY = 'cross_channel_identity_ambiguity';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'routed_contact_id',
        'phone_normalized',
        'identity_key',
        'review_type',
        'candidate_root_contact_ids',
        'context_payload',
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
        'context_payload' => 'array',
        'resolved_at' => 'datetime',
    ];

    /**
     * @return list<string>
     */
    public static function phoneReviewTypes(): array
    {
        return [
            self::TYPE_PHONE_MULTIPLE_ROOTS,
            self::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function terminalStatuses(): array
    {
        return [
            self::STATUS_RESOLVED,
            self::STATUS_DISMISSED,
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isCrossChannelIdentityAmbiguity(): bool
    {
        return $this->review_type === self::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY;
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function routedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'routed_contact_id');
    }

    public function triggerMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'trigger_message_id');
    }
}
