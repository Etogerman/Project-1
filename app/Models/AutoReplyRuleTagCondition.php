<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class AutoReplyRuleTagCondition extends Model
{
    use HasFactory;

    public const CONDITION_REQUIRED = 'required';

    public const CONDITION_EXCLUDED = 'excluded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'auto_reply_rule_id',
        'tag_id',
        'condition',
    ];

    protected static function booted(): void
    {
        static::saving(function (AutoReplyRuleTagCondition $condition): void {
            if (! in_array($condition->condition, array_keys(self::conditionOptions()), true)) {
                throw ValidationException::withMessages([
                    'condition' => 'Неизвестный тип условия для тега.',
                ]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public static function conditionOptions(): array
    {
        return [
            self::CONDITION_REQUIRED => 'Обязательный',
            self::CONDITION_EXCLUDED => 'Исключающий',
        ];
    }

    public function autoReplyRule(): BelongsTo
    {
        return $this->belongsTo(AutoReplyRule::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('condition', self::CONDITION_REQUIRED);
    }

    public function scopeExcluded(Builder $query): Builder
    {
        return $query->where('condition', self::CONDITION_EXCLUDED);
    }
}
