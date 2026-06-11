<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactQuestionnaireRun extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_AWAITING_ANSWER = 'awaiting_answer';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_OPERATOR_REQUESTED = 'operator_requested';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_RESET = 'reset';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'questionnaire_template_id',
        'questionnaire_template_version_id',
        'status',
        'current_field_key',
        'started_dialog_id',
        'last_dialog_id',
        'started_by_block_id',
        'awaiting_block_id',
        'scenario_run_id',
        'started_at',
        'completed_at',
        'cancelled_at',
        'operator_requested_at',
        'reset_at',
        'reset_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'operator_requested_at' => 'datetime',
        'reset_at' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_IN_PROGRESS => 'В процессе',
            self::STATUS_AWAITING_ANSWER => 'Ждёт ответ',
            self::STATUS_COMPLETED => 'Завершена',
            self::STATUS_FAILED => 'Не удалось',
            self::STATUS_CANCELLED => 'Отменена',
            self::STATUS_OPERATOR_REQUESTED => 'Запрошен оператор',
            self::STATUS_PAUSED => 'На паузе',
            self::STATUS_RESET => 'Сброшена',
        ];
    }

    /**
     * @return list<string>
     */
    public static function activeStatuses(): array
    {
        return [
            self::STATUS_IN_PROGRESS,
            self::STATUS_AWAITING_ANSWER,
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::activeStatuses());
    }

    public function scopeAwaitingAnswer(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AWAITING_ANSWER);
    }

    public function isAwaitingAnswer(): bool
    {
        return $this->status === self::STATUS_AWAITING_ANSWER;
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireTemplate::class, 'questionnaire_template_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireTemplateVersion::class, 'questionnaire_template_version_id');
    }

    public function startedDialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class, 'started_dialog_id');
    }

    public function lastDialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class, 'last_dialog_id');
    }

    public function scenarioRun(): BelongsTo
    {
        return $this->belongsTo(ScenarioRun::class);
    }

    public function resetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reset_by');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ContactQuestionnaireAnswer::class, 'questionnaire_run_id')
            ->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ContactQuestionnaireAttempt::class, 'questionnaire_run_id')
            ->orderBy('field_key')
            ->orderBy('attempt_index')
            ->orderBy('id');
    }
}
