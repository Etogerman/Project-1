<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class AutoReplyRule extends Model
{
    use HasFactory;

    public const TELEGRAM_BUTTON_TYPE_REQUEST_PHONE = 'request_phone';

    public const MAX_BUTTON_TYPE_REQUEST_PHONE = 'request_phone';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'keyword',
        'normalized_keyword',
        'reply_text',
        'telegram_button_type',
        'max_button_type',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (AutoReplyRule $rule): void {
            $rule->guardTelegramButtonType();
            $rule->guardMaxButtonType();
        });
    }

    public static function normalizeKeyword(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return mb_strtolower(trim((string) $value));
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    public static function telegramButtonTypeOptions(): array
    {
        return [
            self::TELEGRAM_BUTTON_TYPE_REQUEST_PHONE => 'Запросить номер телефона',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function maxButtonTypeOptions(): array
    {
        return [
            self::MAX_BUTTON_TYPE_REQUEST_PHONE => 'Запросить номер телефона',
        ];
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
                'telegram_button_type' => 'Кнопка "Запросить номер телефона" доступна только для Telegram-каналов.',
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
                'max_button_type' => 'Кнопка "Запросить номер телефона" доступна только для MAX-каналов.',
            ]);
        }
    }
}
