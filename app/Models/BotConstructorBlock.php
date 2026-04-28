<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class BotConstructorBlock extends Model
{
    use HasFactory;

    public const MATCH_TYPE_ANY_INBOUND = 'any_inbound';

    public const MATCH_TYPE_EXACT_KEYWORD = 'exact_keyword';

    public const MATCH_TYPE_CONTAINS_TEXT = 'contains_text';

    public const MATCH_TYPE_EXACT_PARAMETER = 'exact_parameter';

    public const MATCH_TYPE_EXACT_TEXT_OR_PARAMETER = 'exact_text_or_parameter';

    public const NONE_TOKEN = '#{none}';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'is_active',
        'match_type',
        'match_values',
        'response_text',
        'x',
        'y',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'match_values' => 'array',
        'x' => 'integer',
        'y' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (BotConstructorBlock $block): void {
            $block->guardMatchType();
            $block->title = filled($block->title) ? trim((string) $block->title) : 'Стартовое условие';
            $block->x = max(0, (int) $block->x);
            $block->y = max(0, (int) $block->y);
            $block->match_values = static::normalizeMatchValues($block->match_values ?? []);

            if ($block->usesAnyInbound()) {
                $block->match_values = [];
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public static function matchTypeOptions(): array
    {
        return [
            self::MATCH_TYPE_ANY_INBOUND => 'Любое входящее',
            self::MATCH_TYPE_EXACT_KEYWORD => 'Полное совпадение текста',
            self::MATCH_TYPE_CONTAINS_TEXT => 'Сообщение содержит текст',
            self::MATCH_TYPE_EXACT_PARAMETER => 'Параметр сообщения',
            self::MATCH_TYPE_EXACT_TEXT_OR_PARAMETER => 'Текст или параметр сообщения',
        ];
    }

    /**
     * @return array{match_type:string, match_values:list<string>}
     */
    public static function normalizeConditionInput(string $matchType, mixed $rawValues): array
    {
        $input = is_array($rawValues)
            ? implode(';', array_map(static fn (mixed $value): string => (string) $value, $rawValues))
            : (string) $rawValues;

        $trimmedInput = trim($input);

        if ($trimmedInput === self::NONE_TOKEN) {
            return [
                'match_type' => self::MATCH_TYPE_ANY_INBOUND,
                'match_values' => [],
            ];
        }

        $values = array_map(
            static fn (string $value): string => trim($value),
            explode(';', $input),
        );

        $values = array_values(array_filter(
            $values,
            static fn (string $value): bool => $value !== '',
        ));

        if (in_array(self::NONE_TOKEN, $values, true)) {
            throw ValidationException::withMessages([
                'draftMatchValuesInput' => 'Значение #{none} нельзя смешивать с другими условиями.',
            ]);
        }

        return [
            'match_type' => $matchType,
            'match_values' => static::normalizeMatchValues($values),
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    public static function normalizeMatchValues(array $values): array
    {
        return collect($values)
            ->map(static fn (mixed $value): ?string => static::normalizeComparable($value))
            ->filter(static fn (?string $value): bool => filled($value))
            ->unique()
            ->values()
            ->all();
    }

    public static function normalizeComparable(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return mb_strtolower(trim((string) $value));
    }

    public static function isNoReply(?string $responseText): bool
    {
        $trimmed = trim((string) $responseText);

        return $trimmed === '' || $trimmed === self::NONE_TOKEN;
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'bot_constructor_block_channels')
            ->withTimestamps();
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BotConstructorBlockRun::class);
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

    public function usesAnyInbound(): bool
    {
        return $this->match_type === self::MATCH_TYPE_ANY_INBOUND;
    }

    public function matchesMessage(Message $message): bool
    {
        if ($this->usesAnyInbound()) {
            return true;
        }

        $values = $this->match_values ?? [];

        if (! is_array($values) || $values === []) {
            return false;
        }

        $text = static::normalizeComparable($message->text);
        $parameter = static::normalizeComparable($message->message_parameter);

        return match ($this->match_type) {
            self::MATCH_TYPE_EXACT_KEYWORD => filled($text) && in_array($text, $values, true),
            self::MATCH_TYPE_CONTAINS_TEXT => filled($text) && collect($values)->contains(
                static fn (string $value): bool => str_contains((string) $text, $value),
            ),
            self::MATCH_TYPE_EXACT_PARAMETER => filled($parameter) && in_array($parameter, $values, true),
            self::MATCH_TYPE_EXACT_TEXT_OR_PARAMETER => (filled($text) && in_array($text, $values, true))
                || (filled($parameter) && in_array($parameter, $values, true)),
            default => false,
        };
    }

    protected function responsePreview(): Attribute
    {
        return Attribute::make(
            get: fn (): string => self::isNoReply($this->response_text) ? 'без ответа' : 'есть ответ',
        );
    }

    protected function guardMatchType(): void
    {
        if (! array_key_exists((string) $this->match_type, self::matchTypeOptions())) {
            throw ValidationException::withMessages([
                'draftMatchType' => 'Выберите поддерживаемый тип совпадения.',
            ]);
        }
    }
}
