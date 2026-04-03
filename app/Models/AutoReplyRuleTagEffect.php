<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class AutoReplyRuleTagEffect extends Model
{
    use HasFactory;

    public const EFFECT_ASSIGN = 'assign';

    public const EFFECT_REMOVE = 'remove';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'auto_reply_rule_id',
        'tag_id',
        'effect',
    ];

    protected static function booted(): void
    {
        static::saving(function (AutoReplyRuleTagEffect $effect): void {
            if (! in_array($effect->effect, array_keys(self::effectOptions()), true)) {
                throw ValidationException::withMessages([
                    'effect' => 'Неизвестный тип действия для тега.',
                ]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public static function effectOptions(): array
    {
        return [
            self::EFFECT_ASSIGN => 'Назначить',
            self::EFFECT_REMOVE => 'Снять',
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

    public function scopeAssign(Builder $query): Builder
    {
        return $query->where('effect', self::EFFECT_ASSIGN);
    }

    public function scopeRemove(Builder $query): Builder
    {
        return $query->where('effect', self::EFFECT_REMOVE);
    }
}
