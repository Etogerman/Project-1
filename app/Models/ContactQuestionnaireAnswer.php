<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactQuestionnaireAnswer extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ASKED = 'asked';

    public const STATUS_FILLED = 'filled';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'questionnaire_run_id',
        'field_key',
        'status',
        'attempts_count',
        'value',
        'display_value',
        'target',
        'synced_to_contact_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'attempts_count' => 'integer',
        'synced_to_contact_at' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Ожидает',
            self::STATUS_ASKED => 'Спросили',
            self::STATUS_FILLED => 'Заполнено',
            self::STATUS_SKIPPED => 'Пропущено',
            self::STATUS_FAILED => 'Не удалось',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContactQuestionnaireRun::class, 'questionnaire_run_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ContactQuestionnaireAttempt::class, 'questionnaire_run_id', 'questionnaire_run_id')
            ->where('field_key', $this->field_key)
            ->orderBy('attempt_index')
            ->orderBy('id');
    }
}
