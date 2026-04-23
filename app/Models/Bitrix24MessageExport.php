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

    public const TRANSPORT_IMCONNECTOR_SEND_MESSAGES = 'imconnector.send.messages';

    public const TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD = 'imopenlines.crm.message.add';

    public const TRANSPORT_FAKE_HAPPY_PATH = 'fake_happy_path';

    public const FAILURE_NO_ACTIVE_CHAT = 'no_active_chat';

    public const FAILURE_AMBIGUOUS_CHAT = 'ambiguous_chat';

    public const FAILURE_SESSION_OPEN_UNAVAILABLE = 'session_open_unavailable';

    public const FAILURE_SESSION_OPEN_FAILED = 'session_open_failed';

    public const FAILURE_CHAT_ACCESS_DENIED = 'chat_access_denied';

    public const FAILURE_CHAT_USER_ADD_FAILED = 'chat_user_add_failed';

    public const FAILURE_MESSAGE_SEND_FAILED = 'message_send_failed';

    public const FAILURE_FAILED_UNCERTAIN = 'failed_uncertain';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'contact_id',
        'bitrix24_contact_id',
        'export_mode',
        'export_status',
        'live_batch_uuid',
        'live_claim_uuid',
        'live_claimed_at',
        'live_claim_expires_at',
        'transport_method',
        'resolved_bitrix_chat_id',
        'resolved_crm_entity_type',
        'resolved_crm_entity_id',
        'bitrix_remote_message_id',
        'batch_uuid',
        'bitrix24_timeline_entry_id',
        'exported_at',
        'failed_at',
        'failure_code',
        'failure_uncertain',
        'failure_reason',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'exported_at' => 'datetime',
        'failed_at' => 'datetime',
        'live_claimed_at' => 'datetime',
        'live_claim_expires_at' => 'datetime',
        'failure_uncertain' => 'boolean',
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
