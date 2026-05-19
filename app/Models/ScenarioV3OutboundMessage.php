<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ScenarioV3OutboundMessage extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scenario_run_id',
        'dialog_id',
        'channel_id',
        'inbound_message_id',
        'outbound_message_id',
        'published_version_id',
        'scheduled_transition_id',
        'scenario_code',
        'block_id',
        'text',
        'text_format',
        'delivery_payload',
        'status',
        'attempts',
        'available_at',
        'processing_started_at',
        'sent_at',
        'failed_at',
        'error_message',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'delivery_payload' => 'array',
        'available_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (ScenarioV3OutboundMessage $message): void {
            if (! in_array($message->status, self::statuses(), true)) {
                throw ValidationException::withMessages([
                    'status' => 'Неизвестный статус исходящего V3-сообщения.',
                ]);
            }

            if (filled($message->error_message)) {
                $message->error_message = mb_substr(trim((string) $message->error_message), 0, 1000);
            }
        });
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_SENT,
            self::STATUS_FAILED,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Ожидает отправку',
            self::STATUS_PROCESSING => 'Отправляется',
            self::STATUS_SENT => 'Отправлено',
            self::STATUS_FAILED => 'Ошибка доставки',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? 'Неизвестно';
    }

    public function scenarioRun(): BelongsTo
    {
        return $this->belongsTo(ScenarioRun::class);
    }

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'inbound_message_id');
    }

    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'outbound_message_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ScenarioVersion::class, 'published_version_id');
    }

    public function scheduledTransition(): BelongsTo
    {
        return $this->belongsTo(ScenarioV3ScheduledTransition::class, 'scheduled_transition_id');
    }
}
