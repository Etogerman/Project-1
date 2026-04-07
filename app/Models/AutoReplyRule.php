<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AutoReplyRule extends Model
{
    use HasFactory;

    public const BUTTON_TYPE_SHARE_CONTACT = 'share_contact';

    public const BUTTON_TYPE_INLINE_KEYBOARD = 'inline_keyboard';

    public const MATCH_SCOPE_EXACT_KEYWORD = 'exact_keyword';

    public const MATCH_SCOPE_CONTAINS_TEXT = 'contains_text';

    public const MATCH_SCOPE_EXACT_PARAMETER = 'exact_parameter';

    public const MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER = 'exact_text_or_parameter';

    public const MATCH_SCOPE_ANY_INBOUND = 'any_inbound';

    public const CONTACT_PHONE_CONDITION_HAS_PHONE = 'has_phone';

    public const CONTACT_PHONE_CONDITION_MISSING_PHONE = 'missing_phone';

    public const TELEGRAM_BUTTON_TYPE_REQUEST_PHONE = 'request_phone';

    public const MAX_BUTTON_TYPE_REQUEST_PHONE = 'request_phone';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'channel_id',
        'keyword',
        'normalized_keyword',
        'match_scope',
        'contact_phone_condition',
        'reply_text',
        'telegram_button_type',
        'max_button_type',
        'is_active',
        'priority',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (AutoReplyRule $rule): void {
            $rule->guardMatchScope();
            $rule->guardContactPhoneCondition();
            $rule->guardKeywordFieldsForMatchScope();
            $rule->guardTelegramButtonType();
            $rule->guardMaxButtonType();
        });

        static::saved(function (AutoReplyRule $rule): void {
            $rule->syncLegacyChannelBridge();
        });
    }

    public static function normalizeKeyword(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return mb_strtolower(trim((string) $value));
    }

    /**
     * @return array<string, string>
     */
    public static function matchScopeOptions(): array
    {
        return [
            self::MATCH_SCOPE_CONTAINS_TEXT => 'Содержит текст в сообщении',
            self::MATCH_SCOPE_EXACT_KEYWORD => 'Точное соответствие текста в сообщении',
            self::MATCH_SCOPE_EXACT_PARAMETER => 'Точное соответствие параметра сообщения',
            self::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER => 'Точное соответствие текста или параметра сообщения',
            self::MATCH_SCOPE_ANY_INBOUND => 'Любое входящее',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function phoneConditionOptions(): array
    {
        return [
            self::CONTACT_PHONE_CONDITION_HAS_PHONE => 'Телефон заполнен',
            self::CONTACT_PHONE_CONDITION_MISSING_PHONE => 'Телефон не заполнен',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'auto_reply_rule_channels')
            ->withPivot(['button_type', 'button_text', 'button_url'])
            ->withTimestamps();
    }

    public function tagEffects(): HasMany
    {
        return $this->hasMany(AutoReplyRuleTagEffect::class)
            ->orderBy('effect')
            ->orderBy('id');
    }

    public function tagConditions(): HasMany
    {
        return $this->hasMany(AutoReplyRuleTagCondition::class)
            ->orderBy('condition')
            ->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForChannel(Builder $query, Channel|int $channel): Builder
    {
        $channelId = $channel instanceof Channel
            ? (int) $channel->getKey()
            : (int) $channel;

        if ($channelId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('channels', function (Builder $channelQuery) use ($channelId): void {
            $channelQuery->whereKey($channelId);
        });
    }

    public function usesExactKeywordScope(): bool
    {
        return ($this->match_scope ?? self::MATCH_SCOPE_EXACT_KEYWORD) === self::MATCH_SCOPE_EXACT_KEYWORD;
    }

    public function usesContainsTextScope(): bool
    {
        return $this->match_scope === self::MATCH_SCOPE_CONTAINS_TEXT;
    }

    public function usesExactParameterScope(): bool
    {
        return $this->match_scope === self::MATCH_SCOPE_EXACT_PARAMETER;
    }

    public function usesExactTextOrParameterScope(): bool
    {
        return $this->match_scope === self::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER;
    }

    public function usesAnyInboundScope(): bool
    {
        return $this->match_scope === self::MATCH_SCOPE_ANY_INBOUND;
    }

    public function usesKeywordScope(): bool
    {
        return ! $this->usesAnyInboundScope();
    }

    /**
     * @return array<string, string>
     */
    public static function telegramButtonTypeOptions(): array
    {
        return [
            self::TELEGRAM_BUTTON_TYPE_REQUEST_PHONE => 'Поделиться номером телефона',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function maxButtonTypeOptions(): array
    {
        return [
            self::MAX_BUTTON_TYPE_REQUEST_PHONE => 'Поделиться номером телефона',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function telegramChannelButtonTypeOptions(): array
    {
        return [
            self::BUTTON_TYPE_SHARE_CONTACT => 'Поделиться номером телефона',
            self::BUTTON_TYPE_INLINE_KEYBOARD => 'Inline-кнопка',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function maxChannelButtonTypeOptions(): array
    {
        return [
            self::BUTTON_TYPE_SHARE_CONTACT => 'Поделиться номером телефона',
        ];
    }

    public function getButtonTypeForChannel(Channel|int $channel): ?string
    {
        return $this->resolveChannelPivotValue($channel, 'button_type');
    }

    public function getButtonTextForChannel(Channel|int $channel): ?string
    {
        return $this->resolveChannelPivotValue($channel, 'button_text');
    }

    public function getButtonUrlForChannel(Channel|int $channel): ?string
    {
        return $this->resolveChannelPivotValue($channel, 'button_url');
    }

    protected function keyword(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): array => [
                'keyword' => filled($value) ? trim((string) $value) : $value,
                'normalized_keyword' => static::normalizeKeyword($value),
            ],
        );
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value)
                ? trim((string) $value)
                : null,
        );
    }

    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => filled($this->name)
                ? (string) $this->name
                : "Автоответ #{$this->id}",
        );
    }

    protected function guardMatchScope(): void
    {
        $matchScope = $this->match_scope ?? self::MATCH_SCOPE_EXACT_KEYWORD;

        if (! in_array($matchScope, array_keys(self::matchScopeOptions()), true)) {
            throw ValidationException::withMessages([
                'match_scope' => 'Неизвестная область срабатывания правила.',
            ]);
        }

        $this->match_scope = $matchScope;
    }

    protected function guardContactPhoneCondition(): void
    {
        if (! filled($this->contact_phone_condition)) {
            $this->contact_phone_condition = null;

            return;
        }

        if (! in_array($this->contact_phone_condition, array_keys(self::phoneConditionOptions()), true)) {
            throw ValidationException::withMessages([
                'contact_phone_condition' => 'Неизвестное условие по телефону.',
            ]);
        }
    }

    protected function guardKeywordFieldsForMatchScope(): void
    {
        if ($this->usesAnyInboundScope()) {
            $this->keyword = null;
            $this->normalized_keyword = null;

            return;
        }

        $normalizedKeyword = static::normalizeKeyword($this->keyword ?? $this->normalized_keyword);

        if (! filled($normalizedKeyword)) {
            throw ValidationException::withMessages([
                'keyword' => 'Для этого правила нужно указать значение для срабатывания.',
            ]);
        }

        $this->keyword = filled($this->keyword) ? trim((string) $this->keyword) : $normalizedKeyword;
        $this->normalized_keyword = $normalizedKeyword;
    }

    protected function guardTelegramButtonType(): void
    {
        if (! filled($this->telegram_button_type)) {
            return;
        }

        if ($this->telegram_button_type !== self::TELEGRAM_BUTTON_TYPE_REQUEST_PHONE) {
            throw ValidationException::withMessages([
                'telegram_button_type' => 'Неизвестный тип Telegram-кнопки.',
            ]);
        }

        $channel = $this->relationLoaded('channel')
            ? $this->channel
            : Channel::query()->find($this->channel_id);

        if (! $channel instanceof Channel || $channel->platform !== Channel::PLATFORM_TELEGRAM) {
            throw ValidationException::withMessages([
                'telegram_button_type' => 'Кнопка "Поделиться номером телефона" доступна только для Telegram-каналов.',
            ]);
        }
    }

    protected function guardMaxButtonType(): void
    {
        if (! filled($this->max_button_type)) {
            return;
        }

        if ($this->max_button_type !== self::MAX_BUTTON_TYPE_REQUEST_PHONE) {
            throw ValidationException::withMessages([
                'max_button_type' => 'Неизвестный тип MAX-кнопки.',
            ]);
        }

        $channel = $this->relationLoaded('channel')
            ? $this->channel
            : Channel::query()->find($this->channel_id);

        if (! $channel instanceof Channel || $channel->platform !== Channel::PLATFORM_MAX) {
            throw ValidationException::withMessages([
                'max_button_type' => 'Кнопка "Поделиться номером телефона" доступна только для MAX-каналов.',
            ]);
        }
    }

    protected function syncLegacyChannelBridge(): void
    {
        if (! Schema::hasTable('auto_reply_rule_channels')) {
            return;
        }

        if (! filled($this->channel_id)) {
            $this->channels()->detach();

            return;
        }

        $channel = $this->relationLoaded('channel')
            ? $this->channel
            : Channel::query()->find($this->channel_id);

        if (! $channel instanceof Channel) {
            return;
        }

        $buttonType = match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->telegram_button_type === self::TELEGRAM_BUTTON_TYPE_REQUEST_PHONE
                ? 'share_contact'
                : null,
            Channel::PLATFORM_MAX => $this->max_button_type === self::MAX_BUTTON_TYPE_REQUEST_PHONE
                ? 'share_contact'
                : null,
            default => null,
        };

        $this->channels()->sync([
            (int) $channel->getKey() => [
                'button_type' => $buttonType,
                'button_text' => null,
                'button_url' => null,
            ],
        ]);
    }

    protected function resolveChannelPivotValue(Channel|int $channel, string $attribute): ?string
    {
        $channelId = $channel instanceof Channel
            ? (int) $channel->getKey()
            : (int) $channel;

        if ($channelId <= 0) {
            return null;
        }

        $channelModel = $this->relationLoaded('channels')
            ? $this->channels->firstWhere('id', $channelId)
            : $this->channels()->whereKey($channelId)->first();

        if (! $channelModel instanceof Channel) {
            return null;
        }

        $value = $channelModel->pivot?->{$attribute};

        return filled($value)
            ? trim((string) $value)
            : null;
    }
}
