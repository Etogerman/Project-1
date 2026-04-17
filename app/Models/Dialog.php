<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Services\Dialogs\ApplyDialogRoutePredicateAction;

class Dialog extends Model
{
    use HasFactory;

    public const PHONE_CONFIRMED_VIA_PHONE_CAPTURE = 'phone_capture';

    public const BITRIX24_LIVE_STATUS_NOT_LINKED = 'not_linked';

    public const BITRIX24_LIVE_STATUS_ACTIVE = 'active';

    public const BITRIX24_LIVE_STATUS_FAILED = 'failed';

    public const BITRIX24_LIVE_STATUS_CLOSED = 'closed';

    public const BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER = 'blocked_by_user';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'channel_id',
        'current_contact_identity_id',
        'pending_auto_reply_source_message_id',
        'manual_reply_dismissed_source_message_id',
        'bot_subscription_status',
        'bot_subscription_changed_at',
        'bot_subscription_source_message_id',
        'external_chat_id',
        'bitrix24_live_chat_id',
        'bitrix24_live_status',
        'bitrix24_live_last_exported_at',
        'bitrix24_live_last_imported_at',
        'confirmed_phone_raw',
        'confirmed_phone_normalized',
        'phone_confirmed_at',
        'phone_confirmed_via',
        'last_message_at',
        'last_inbound_at',
        'last_outbound_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'bitrix24_live_last_exported_at' => 'datetime',
        'bitrix24_live_last_imported_at' => 'datetime',
        'bot_subscription_changed_at' => 'datetime',
        'phone_confirmed_at' => 'datetime',
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'last_outbound_at' => 'datetime',
    ];

    public function isBotBlockedByUser(): bool
    {
        return $this->bot_subscription_status === self::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER;
    }

    public function isBitrix24LiveActive(): bool
    {
        return $this->bitrix24_live_status === self::BITRIX24_LIVE_STATUS_ACTIVE;
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function currentContactIdentity(): BelongsTo
    {
        return $this->belongsTo(ContactIdentity::class, 'current_contact_identity_id');
    }

    public function pendingAutoReplySourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'pending_auto_reply_source_message_id');
    }

    public function botSubscriptionSourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'bot_subscription_source_message_id');
    }

    public function manualReplyDismissedSourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'manual_reply_dismissed_source_message_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function previewMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'preview_message_id');
    }

    public function scopeWhereRouteReady(Builder $query): Builder
    {
        return app(ApplyDialogRoutePredicateAction::class)->applyReady($query);
    }

    public function scopeWhereRouteProblem(Builder $query): Builder
    {
        return app(ApplyDialogRoutePredicateAction::class)->applyProblem($query);
    }
}
