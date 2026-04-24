<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelPeerSyncState extends Model
{
    use HasFactory;

    public const PEER_KEY_PREFIX_TELEGRAM_ACCOUNT = 'telegram_account';

    public const BACKFILL_STATUS_NOT_STARTED = 'not_started';

    public const BACKFILL_STATUS_IN_PROGRESS = 'in_progress';

    public const BACKFILL_STATUS_COMPLETE = 'complete';

    public const BACKFILL_STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'peer_key',
        'external_chat_id',
        'backfill_status',
        'oldest_imported_message_id',
        'latest_observed_message_id',
        'history_complete_at',
        'last_sync_error',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'history_complete_at' => 'datetime',
    ];

    public static function buildTelegramAccountPeerKey(int|string $channelId, int|string $externalChatId): string
    {
        return sprintf(
            '%s:%s:%s',
            self::PEER_KEY_PREFIX_TELEGRAM_ACCOUNT,
            (string) $channelId,
            (string) $externalChatId,
        );
    }

    /**
     * @return array<string, string>
     */
    public static function backfillStatusLabels(): array
    {
        return [
            self::BACKFILL_STATUS_NOT_STARTED => 'Не начато',
            self::BACKFILL_STATUS_IN_PROGRESS => 'Загрузка истории',
            self::BACKFILL_STATUS_COMPLETE => 'Завершена',
            self::BACKFILL_STATUS_FAILED => 'Ошибка',
        ];
    }

    public function getBackfillStatusLabel(): string
    {
        return self::backfillStatusLabels()[$this->backfill_status] ?? (string) $this->backfill_status;
    }

    public function getBackfillStatusColor(): string
    {
        return match ($this->backfill_status) {
            self::BACKFILL_STATUS_COMPLETE => 'success',
            self::BACKFILL_STATUS_IN_PROGRESS => 'info',
            self::BACKFILL_STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
