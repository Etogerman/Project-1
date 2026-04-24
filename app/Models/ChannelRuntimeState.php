<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelRuntimeState extends Model
{
    use HasFactory;

    public const AUTH_STATUS_UNKNOWN = 'unknown';

    public const AUTH_STATUS_PENDING = 'pending';

    public const AUTH_STATUS_AUTHORIZED = 'authorized';

    public const AUTH_STATUS_FAILED = 'failed';

    public const AUTH_STATUS_REVOKED = 'revoked';

    public const AUTHORIZATION_STATE_NOT_STARTED = 'not_started';

    public const AUTHORIZATION_STATE_AWAITING_QR = 'awaiting_qr';

    public const AUTHORIZATION_STATE_AWAITING_CODE = 'awaiting_code';

    public const AUTHORIZATION_STATE_AWAITING_PASSWORD = 'awaiting_password';

    public const AUTHORIZATION_STATE_READY = 'ready';

    public const AUTHORIZATION_STATE_EXPIRED = 'expired';

    public const SYNC_STATUS_IDLE = 'idle';

    public const SYNC_STATUS_BACKFILL_IN_PROGRESS = 'backfill_in_progress';

    public const SYNC_STATUS_LIVE = 'live';

    public const SYNC_STATUS_DEGRADED = 'degraded';

    public const SYNC_STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'auth_status',
        'authorization_state',
        'sync_status',
        'last_gateway_heartbeat_at',
        'last_sync_started_at',
        'last_sync_completed_at',
        'last_error_at',
        'last_error_message',
        'runtime_payload',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'last_gateway_heartbeat_at' => 'datetime',
        'last_sync_started_at' => 'datetime',
        'last_sync_completed_at' => 'datetime',
        'last_error_at' => 'datetime',
        'runtime_payload' => 'array',
    ];

    /**
     * @return array<string, string>
     */
    public static function authStatusLabels(): array
    {
        return [
            self::AUTH_STATUS_UNKNOWN => 'Не авторизован',
            self::AUTH_STATUS_PENDING => 'Ожидает авторизации',
            self::AUTH_STATUS_AUTHORIZED => 'Авторизован',
            self::AUTH_STATUS_FAILED => 'Ошибка авторизации',
            self::AUTH_STATUS_REVOKED => 'Доступ отозван',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function authorizationStateLabels(): array
    {
        return [
            self::AUTHORIZATION_STATE_NOT_STARTED => 'Не начато',
            self::AUTHORIZATION_STATE_AWAITING_QR => 'Ожидает QR',
            self::AUTHORIZATION_STATE_AWAITING_CODE => 'Ожидает код',
            self::AUTHORIZATION_STATE_AWAITING_PASSWORD => 'Ожидает пароль',
            self::AUTHORIZATION_STATE_READY => 'Готов',
            self::AUTHORIZATION_STATE_EXPIRED => 'Истекло',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function syncStatusLabels(): array
    {
        return [
            self::SYNC_STATUS_IDLE => 'Ожидает',
            self::SYNC_STATUS_BACKFILL_IN_PROGRESS => 'Загрузка истории',
            self::SYNC_STATUS_LIVE => 'В реальном времени',
            self::SYNC_STATUS_DEGRADED => 'Ограниченно',
            self::SYNC_STATUS_FAILED => 'Ошибка синхронизации',
        ];
    }

    public function getAuthStatusLabel(): string
    {
        return self::authStatusLabels()[$this->auth_status] ?? (string) $this->auth_status;
    }

    public function getAuthorizationStateLabel(): string
    {
        return self::authorizationStateLabels()[$this->authorization_state] ?? (string) $this->authorization_state;
    }

    public function getSyncStatusLabel(): string
    {
        return self::syncStatusLabels()[$this->sync_status] ?? (string) $this->sync_status;
    }

    public function getAuthStatusColor(): string
    {
        return match ($this->auth_status) {
            self::AUTH_STATUS_AUTHORIZED => 'success',
            self::AUTH_STATUS_PENDING => 'warning',
            self::AUTH_STATUS_FAILED, self::AUTH_STATUS_REVOKED => 'danger',
            default => 'gray',
        };
    }

    public function getAuthorizationStateColor(): string
    {
        return match ($this->authorization_state) {
            self::AUTHORIZATION_STATE_READY => 'success',
            self::AUTHORIZATION_STATE_AWAITING_QR,
            self::AUTHORIZATION_STATE_AWAITING_CODE,
            self::AUTHORIZATION_STATE_AWAITING_PASSWORD => 'warning',
            self::AUTHORIZATION_STATE_EXPIRED => 'danger',
            default => 'gray',
        };
    }

    public function getSyncStatusColor(): string
    {
        return match ($this->sync_status) {
            self::SYNC_STATUS_LIVE => 'success',
            self::SYNC_STATUS_BACKFILL_IN_PROGRESS => 'info',
            self::SYNC_STATUS_DEGRADED => 'warning',
            self::SYNC_STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
