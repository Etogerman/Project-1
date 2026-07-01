<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    public const TEXT_FORMAT_PLAIN_TEXT = 'plain_text';

    public const TEXT_FORMAT_HTML = 'html';

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const KIND_INBOUND_USER = 'inbound_user';

    public const KIND_INBOUND_CONTACT_SHARE = 'inbound_contact_share';

    public const KIND_INBOUND_SYSTEM_EVENT = 'inbound_system_event';

    public const KIND_OUTBOUND_AUTO_REPLY = 'outbound_auto_reply';

    public const KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION = 'outbound_phone_capture_confirmation';

    public const KIND_OUTBOUND_MANUAL_REPLY = 'outbound_manual_reply';

    public const KIND_OUTBOUND_EXTERNAL_ACCOUNT_MESSAGE = 'outbound_external_account_message';

    public const KIND_OUTBOUND_DATA_COLLECTION_QUESTION = 'outbound_data_collection_question';

    public const KIND_OUTBOUND_DATA_COLLECTION_COMPLETION = 'outbound_data_collection_completion';

    public const KIND_OUTBOUND_SCENARIO_MESSAGE = 'outbound_scenario_message';

    public const KIND_OUTBOUND_DIALOG_STATUS_CHANGE = 'outbound_dialog_status_change';

    public const MEDIA_DOWNLOAD_STATUS_PENDING = 'pending';

    public const MEDIA_DOWNLOAD_STATUS_DOWNLOADING = 'downloading';

    public const MEDIA_DOWNLOAD_STATUS_DOWNLOADED = 'downloaded';

    public const MEDIA_DOWNLOAD_STATUS_FAILED = 'failed';

    public const SENT_BY_TYPE_CONTACT = 'contact';

    public const SENT_BY_TYPE_OPERATOR = 'operator';

    public const SENT_BY_TYPE_AUTO_REPLY = 'auto_reply';

    public const SENT_BY_TYPE_COLLECTOR = 'collector';

    public const SENT_BY_TYPE_SYSTEM = 'system';

    public const SENT_BY_SYSTEM_CODE_AUTO_REPLY_RULE = 'auto_reply_rule';

    public const SENT_BY_SYSTEM_CODE_PHONE_CAPTURE_CONFIRMATION = 'phone_capture_confirmation';

    public const SENT_BY_SYSTEM_CODE_DATA_COLLECTION_QUESTION = 'data_collection_question';

    public const SENT_BY_SYSTEM_CODE_DATA_COLLECTION_COMPLETION = 'data_collection_completion';

    public const SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION = 'telegram_bot_subscription';

    public const SENT_BY_SYSTEM_CODE_SCENARIO_WARMUP = 'scenario_warmup';

    public const SENT_BY_SYSTEM_CODE_SCENARIO_NEEDS_DISCOVERY = 'scenario_needs_discovery';

    public const SENT_BY_SYSTEM_CODE_SCENARIO_BUILDER_START_CONDITION = 'scenario_builder_start_condition';

    public const SENT_BY_SYSTEM_CODE_BOT_CONSTRUCTOR_BLOCK = 'bot_constructor_block';

    public const SENT_BY_SYSTEM_CODE_LEGACY_UNKNOWN_KIND = 'legacy_unknown_kind';

    public const SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES = 'bitrix24_openlines';

    public const SENT_BY_SYSTEM_CODE_TELEGRAM_EXTERNAL_ACCOUNT = 'telegram_external_account';

    public const SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE = 'dialog_inbox_status_change';

    public const SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE = 'dialog_stage_change';

    public const SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER = 'bot_blocked_by_user';

    public const SYSTEM_EVENT_CODE_BOT_UNBLOCKED_BY_USER = 'bot_unblocked_by_user';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dialog_id',
        'contact_id',
        'contact_identity_id',
        'channel_id',
        'direction',
        'message_kind',
        'system_event_code',
        'sent_by_type',
        'sent_by_user_id',
        'sent_by_system_code',
        'reply_to_message_id',
        'provider_event_key',
        'provider_group_key',
        'last_edit_provider_event_key',
        'external_chat_id',
        'external_message_id',
        'text',
        'text_format',
        'source_text',
        'rich_text',
        'message_parameter',
        'raw_payload',
        'received_at',
        'edited_at',
        'edit_count',
        'auto_reply_sent_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rich_text' => 'array',
        'raw_payload' => 'array',
        'received_at' => 'datetime',
        'edited_at' => 'datetime',
        'edit_count' => 'integer',
        'auto_reply_sent_at' => 'datetime',
    ];

    public function hasSuccessfulAutoReply(): bool
    {
        return $this->auto_reply_sent_at !== null;
    }

    public function usesHtmlFormat(): bool
    {
        return $this->text_format === self::TEXT_FORMAT_HTML && filled($this->source_text);
    }

    public static function normalizeTextFormat(?string $value): string
    {
        return $value === self::TEXT_FORMAT_HTML
            ? self::TEXT_FORMAT_HTML
            : self::TEXT_FORMAT_PLAIN_TEXT;
    }

    public static function normalizeMediaDownloadStatus(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return in_array($normalized, [
            self::MEDIA_DOWNLOAD_STATUS_PENDING,
            self::MEDIA_DOWNLOAD_STATUS_DOWNLOADING,
            self::MEDIA_DOWNLOAD_STATUS_DOWNLOADED,
            self::MEDIA_DOWNLOAD_STATUS_FAILED,
        ], true)
            ? $normalized
            : null;
    }

    /**
     * @return array<string, string>
     */
    public static function textFormatOptions(): array
    {
        return [
            self::TEXT_FORMAT_PLAIN_TEXT => 'Просто текст',
            self::TEXT_FORMAT_HTML => 'HTML',
        ];
    }

    /**
     * @return list<string>
     */
    public static function dialogAnswerMessageKinds(): array
    {
        return [
            self::KIND_OUTBOUND_MANUAL_REPLY,
            self::KIND_OUTBOUND_EXTERNAL_ACCOUNT_MESSAGE,
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function contactIdentity(): BelongsTo
    {
        return $this->belongsTo(ContactIdentity::class);
    }

    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'reply_to_message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MessageRevision::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
