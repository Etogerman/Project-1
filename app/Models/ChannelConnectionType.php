<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChannelConnectionType extends Model
{
    use HasFactory;

    public const CODE_TELEGRAM_BOT = 'telegram_bot';

    public const CODE_TELEGRAM_ACCOUNT = 'telegram_account';

    public const CODE_MAX_BOT = 'max_bot';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'platform',
        'connection_kind',
        'is_active',
        'supports_open_lines',
        'supports_auto_setup',
        'settings_schema',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'supports_open_lines' => 'boolean',
        'supports_auto_setup' => 'boolean',
        'settings_schema' => 'array',
        'sort_order' => 'integer',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function defaultDefinitions(): array
    {
        return [
            [
                'code' => self::CODE_TELEGRAM_BOT,
                'name' => 'Telegram bot',
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_kind' => Channel::CONNECTION_TYPE_BOT,
                'is_active' => true,
                'supports_open_lines' => true,
                'supports_auto_setup' => true,
                'settings_schema' => [
                    'credentials' => ['token', 'webhook_secret'],
                    'settings' => ['bot_external_id', 'bot_username', 'bot_name'],
                ],
                'sort_order' => 10,
            ],
            [
                'code' => self::CODE_TELEGRAM_ACCOUNT,
                'name' => 'Telegram account',
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_kind' => Channel::CONNECTION_TYPE_ACCOUNT,
                'is_active' => true,
                'supports_open_lines' => false,
                'supports_auto_setup' => false,
                'settings_schema' => [
                    'runtime' => ['gateway_session', 'peer_sync'],
                ],
                'sort_order' => 20,
            ],
            [
                'code' => self::CODE_MAX_BOT,
                'name' => 'MAX bot',
                'platform' => Channel::PLATFORM_MAX,
                'connection_kind' => Channel::CONNECTION_TYPE_BOT,
                'is_active' => true,
                'supports_open_lines' => true,
                'supports_auto_setup' => true,
                'settings_schema' => [
                    'credentials' => ['token', 'webhook_secret'],
                    'settings' => ['bot_external_id', 'bot_username', 'bot_name'],
                ],
                'sort_order' => 30,
            ],
        ];
    }

    public static function resolveIdFor(string $platform, string $connectionKind): ?int
    {
        try {
            $id = static::query()
                ->where('platform', $platform)
                ->where('connection_kind', $connectionKind)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');
        } catch (Throwable) {
            return null;
        }

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array<int, string>
     */
    public static function activeOptions(): array
    {
        try {
            return static::query()
                ->active()
                ->ordered()
                ->get()
                ->mapWithKeys(fn (ChannelConnectionType $type): array => [
                    $type->id => $type->display_label,
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function getDisplayLabelAttribute(): string
    {
        $platformLabel = Channel::platformOptions()[$this->platform] ?? $this->platform;

        return sprintf('%s · %s', $this->name, $platformLabel);
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     */
    public static function insertDefaultDefinitions(array $definitions): void
    {
        foreach ($definitions as $definition) {
            DB::table((new self)->getTable())->updateOrInsert(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'platform' => $definition['platform'],
                    'connection_kind' => $definition['connection_kind'],
                    'is_active' => $definition['is_active'],
                    'supports_open_lines' => $definition['supports_open_lines'],
                    'supports_auto_setup' => $definition['supports_auto_setup'],
                    'settings_schema' => json_encode($definition['settings_schema']),
                    'sort_order' => $definition['sort_order'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
