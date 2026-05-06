<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bitrix24OpenLineRoute extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LEGACY = 'legacy';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_MISCONFIGURED = 'misconfigured';

    public const STATUS_UNSUPPORTED = 'unsupported';

    public const CHANNEL_TYPE_TELEGRAM_BOT = 'telegram_bot';

    public const CHANNEL_TYPE_TELEGRAM_ACCOUNT = 'telegram_account';

    public const CHANNEL_TYPE_MAX = 'max';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bitrix24_profile_id',
        'channel_id',
        'portal_domain',
        'profile_key',
        'channel_type',
        'connector_code',
        'line_id',
        'line_name',
        'line_owner_key',
        'source_id',
        'status',
        'last_error_message',
        'last_error_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'last_error_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Bitrix24OpenLineRoute $route): void {
            $route->line_owner_key = $route->buildLineOwnerKey();
        });
    }

    /**
     * @return list<string>
     */
    public static function usableStatuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_LEGACY,
        ];
    }

    public static function channelTypeForChannel(Channel $channel): string
    {
        return match (true) {
            $channel->platform === Channel::PLATFORM_TELEGRAM && $channel->isBotConnection() => self::CHANNEL_TYPE_TELEGRAM_BOT,
            $channel->platform === Channel::PLATFORM_TELEGRAM && $channel->isAccountConnection() => self::CHANNEL_TYPE_TELEGRAM_ACCOUNT,
            $channel->platform === Channel::PLATFORM_MAX => self::CHANNEL_TYPE_MAX,
            default => (string) $channel->platform,
        };
    }

    public function isUsable(): bool
    {
        return in_array($this->status, self::usableStatuses(), true)
            && filled($this->connector_code)
            && filled($this->line_id);
    }

    public function buildLineOwnerKey(): ?string
    {
        if (! $this->isUsable() || ! filled($this->portal_domain) || ! filled($this->line_id)) {
            return null;
        }

        return sprintf('%s#%s', $this->portal_domain, $this->line_id);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereIn('status', self::usableStatuses());
    }

    public function bitrix24Profile(): BelongsTo
    {
        return $this->belongsTo(Bitrix24Profile::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function dialogs(): HasMany
    {
        return $this->hasMany(Dialog::class, 'bitrix24_open_line_route_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
