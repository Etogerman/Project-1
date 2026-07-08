<?php

namespace App\Models;

use App\Services\Dialogs\ApplyDialogRoutePredicateAction;
use App\Services\Dialogs\DialogStageCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dialog extends Model
{
    use HasFactory;

    public const STAGE_NEW_DIALOG = 'new_dialog';

    public const STAGE_PHONE_RECEIVED = 'phone_received';

    public const STAGE_QUESTIONNAIRE_COMPLETED = 'questionnaire_completed';

    public const STAGE_TRANSFERRED_TO_MPL = 'transferred_to_mpl';

    public const STAGE_TRANSFERRED_TO_MPP = 'transferred_to_mpp';

    public const PHONE_CONFIRMED_VIA_PHONE_CAPTURE = 'phone_capture';

    public const BITRIX24_LIVE_STATUS_NOT_LINKED = 'not_linked';

    public const BITRIX24_LIVE_STATUS_ACTIVE = 'active';

    public const BITRIX24_LIVE_STATUS_FAILED = 'failed';

    public const BITRIX24_LIVE_STATUS_CLOSED = 'closed';

    public const BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER = 'blocked_by_user';

    /**
     * @return array<string, string>
     */
    public static function stageLabels(): array
    {
        return app(DialogStageCatalog::class)->labels();
    }

    public static function stageLabel(?string $stage): string
    {
        return app(DialogStageCatalog::class)->label($stage);
    }

    public static function stageTone(?string $stage): string
    {
        return app(DialogStageCatalog::class)->color($stage);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'channel_id',
        'stage',
        'stage_id',
        'current_contact_identity_id',
        'pending_auto_reply_source_message_id',
        'manual_reply_dismissed_source_message_id',
        'bot_subscription_status',
        'bot_subscription_changed_at',
        'bot_subscription_source_message_id',
        'external_chat_id',
        'bitrix24_live_chat_id',
        'bitrix24_live_status',
        'bitrix24_open_line_route_id',
        'bitrix24_open_line_user_code_override',
        'bitrix24_open_line_resolved_chat_id_override',
        'bitrix24_open_line_binding_verified_at',
        'bitrix24_live_last_exported_at',
        'bitrix24_live_last_imported_at',
        'confirmed_phone_raw',
        'confirmed_phone_normalized',
        'phone_confirmed_at',
        'phone_confirmed_via',
        'fields_payload',
        'last_message_at',
        'last_inbound_at',
        'last_outbound_at',
        'last_message_id',
        'last_inbound_message_id',
        'last_outbound_message_id',
        'last_message_preview',
        'last_inbound_message_preview',
        'last_outbound_message_preview',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'bitrix24_live_last_exported_at' => 'datetime',
        'bitrix24_live_last_imported_at' => 'datetime',
        'bitrix24_open_line_binding_verified_at' => 'datetime',
        'bot_subscription_changed_at' => 'datetime',
        'stage_id' => 'integer',
        'phone_confirmed_at' => 'datetime',
        'fields_payload' => 'array',
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'last_outbound_at' => 'datetime',
    ];

    /**
     * @return list<string>
     */
    public static function automaticStages(): array
    {
        return app(DialogStageCatalog::class)->automaticStageKeys();
    }

    /**
     * @return list<string>
     */
    public static function manualStages(): array
    {
        return app(DialogStageCatalog::class)->manualStageKeys();
    }

    /**
     * @return list<string>
     */
    public static function serviceStages(): array
    {
        return app(DialogStageCatalog::class)->serviceStageKeys();
    }

    /**
     * @return list<string>
     */
    public static function workingStages(): array
    {
        return app(DialogStageCatalog::class)->workingStageKeys();
    }

    /**
     * @return list<string>
     */
    public static function kanbanStages(): array
    {
        return app(DialogStageCatalog::class)->kanbanStageKeys();
    }

    public static function isAutomaticStage(?string $stage): bool
    {
        return app(DialogStageCatalog::class)->isAutomatic($stage);
    }

    public static function isManualStage(?string $stage): bool
    {
        return app(DialogStageCatalog::class)->isManual($stage);
    }

    public static function isServiceStage(?string $stage): bool
    {
        return app(DialogStageCatalog::class)->isService($stage);
    }

    public static function isWorkingStage(?string $stage): bool
    {
        return app(DialogStageCatalog::class)->isWorking($stage);
    }

    public function isBlacklistStage(): bool
    {
        return app(DialogStageCatalog::class)->isBlacklistDialog($this);
    }

    /**
     * @return array<string, string>
     */
    public static function manualTransitionOptions(?string $currentStage): array
    {
        $options = [];

        if (filled($currentStage)) {
            $options[$currentStage] = self::stageLabel($currentStage);
        } else {
            $options[''] = 'Этап не задан';
        }

        foreach (self::allowedOperatorTransitionTargets($currentStage) as $stage) {
            $options[$stage] = self::stageLabel($stage);
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function allowedManualTransitionTargets(?string $currentStage): array
    {
        return self::allowedOperatorTransitionTargets($currentStage);
    }

    /**
     * @return list<string>
     */
    public static function allowedOperatorTransitionTargets(?string $currentStage): array
    {
        return collect(self::workingStages())
            ->reject(fn (string $stage): bool => $stage === $currentStage)
            ->values()
            ->all();
    }

    public static function canManuallyTransition(?string $currentStage, string $targetStage): bool
    {
        if ($currentStage === $targetStage) {
            return self::isServiceStage($targetStage) || self::isWorkingStage($targetStage);
        }

        return self::isWorkingStage($targetStage);
    }

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

    public function dialogStage(): BelongsTo
    {
        return $this->belongsTo(DialogStage::class, 'stage_id');
    }

    public function bitrix24OpenLineRoute(): BelongsTo
    {
        return $this->belongsTo(Bitrix24OpenLineRoute::class);
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

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function lastInboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_inbound_message_id');
    }

    public function lastOutboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_outbound_message_id');
    }

    public function previewMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function hasCompleteStageHistoryRouteContext(): bool
    {
        return $this->current_contact_identity_id !== null
            && filled($this->external_chat_id);
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
