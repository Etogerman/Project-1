<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactFirstNameResolutionEvent extends Model
{
    public const EVENT_TYPE_RESOLUTION_ATTEMPT = 'resolution_attempt';

    public const EVENT_TYPE_NAME_WRITTEN = 'name_written';

    public const SOURCE_DICTIONARY = 'dictionary';

    public const SOURCE_AI = 'ai';

    public const SOURCE_SCENARIO = 'scenario';

    public const SOURCE_OPERATOR = 'operator';

    public const SOURCE_MESSENGER_PROFILE = 'messenger_profile';

    public const RESULT_MATCHED = 'matched';

    public const RESULT_NOT_FOUND = 'not_found';

    public const RESULT_AMBIGUOUS = 'ambiguous';

    public const RESULT_MANUAL_REQUIRED = 'manual_required';

    public const RESULT_ACCEPTED = 'accepted';

    public const RESULT_REJECTED = 'rejected';

    public const RESULT_ERROR = 'error';

    public const RESULT_WRITTEN = 'written';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_type',
        'correlation_id',
        'contact_id',
        'dialog_id',
        'channel_id',
        'scenario_id',
        'scenario_block_id',
        'message_id',
        'ai_request_id',
        'resolution_attempt_event_id',
        'source',
        'result',
        'client_text_preview',
        'matched_dictionary_entry_id',
        'found_first_name',
        'resolved_first_name',
        'old_first_name',
        'new_first_name',
        'written_first_name',
        'first_name_source',
        'first_name_resolution_method',
        'payload',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
    ];

    public static function sourceLabel(?string $source): string
    {
        return match ($source) {
            self::SOURCE_DICTIONARY => 'Справочник',
            self::SOURCE_AI => 'ИИ',
            self::SOURCE_SCENARIO => 'Сценарий',
            self::SOURCE_OPERATOR => 'Оператор',
            self::SOURCE_MESSENGER_PROFILE => 'Профиль мессенджера',
            default => 'Неизвестно',
        };
    }

    public static function resultLabel(?string $result): string
    {
        return match ($result) {
            self::RESULT_MATCHED => 'Найдено',
            self::RESULT_NOT_FOUND => 'Не найдено',
            self::RESULT_AMBIGUOUS => 'Неоднозначно',
            self::RESULT_MANUAL_REQUIRED => 'Требует уточнения',
            self::RESULT_ACCEPTED => 'Принято',
            self::RESULT_REJECTED => 'Отклонено',
            self::RESULT_ERROR => 'Ошибка',
            self::RESULT_WRITTEN => 'Записано',
            default => 'Неизвестно',
        };
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('result', [
            self::RESULT_NOT_FOUND,
            self::RESULT_AMBIGUOUS,
            self::RESULT_MANUAL_REQUIRED,
            self::RESULT_REJECTED,
            self::RESULT_ERROR,
        ]);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function aiRequest(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class);
    }

    public function resolutionAttempt(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resolution_attempt_event_id');
    }
}
