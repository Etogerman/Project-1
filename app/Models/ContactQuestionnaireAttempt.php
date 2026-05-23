<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactQuestionnaireAttempt extends Model
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_OPERATOR_REQUESTED = 'operator_requested';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'questionnaire_run_id',
        'field_key',
        'attempt_index',
        'dialog_id',
        'message_id',
        'prompt_text',
        'raw_answer',
        'parsed_value',
        'status',
        'error',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'attempt_index' => 'integer',
    ];

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_ACCEPTED => 'Принято',
            self::STATUS_REJECTED => 'Отклонено',
            self::STATUS_SKIPPED => 'Пропущено',
            self::STATUS_OPERATOR_REQUESTED => 'Запрошен оператор',
            self::STATUS_CANCELLED => 'Отменено',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContactQuestionnaireRun::class, 'questionnaire_run_id');
    }

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
