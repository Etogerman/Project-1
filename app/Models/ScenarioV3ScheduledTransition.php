<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ScenarioV3ScheduledTransition extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PASSED = 'passed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_LIMIT_REACHED = 'limit_reached';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scenario_run_id',
        'dialog_id',
        'inbound_message_id',
        'scenario_code',
        'published_version_id',
        'edge_key',
        'edge_id',
        'source_block_id',
        'target_block_id',
        'delay_payload',
        'scheduled_for',
        'processing_started_at',
        'finished_at',
        'status',
        'error_message',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'delay_payload' => 'array',
        'scheduled_for' => 'datetime',
        'processing_started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (ScenarioV3ScheduledTransition $transition): void {
            if (! in_array($transition->status, self::statuses(), true)) {
                throw ValidationException::withMessages([
                    'status' => 'Неизвестный статус отложенного перехода V3.',
                ]);
            }

            if (filled($transition->error_message)) {
                $transition->error_message = mb_substr(trim((string) $transition->error_message), 0, 1000);
            }
        });
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_SCHEDULED,
            self::STATUS_PROCESSING,
            self::STATUS_PASSED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
            self::STATUS_LIMIT_REACHED,
        ];
    }

    public function scenarioRun(): BelongsTo
    {
        return $this->belongsTo(ScenarioRun::class);
    }

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'inbound_message_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ScenarioVersion::class, 'published_version_id');
    }
}
