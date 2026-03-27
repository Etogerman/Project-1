<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class Channel extends Model
{
    use HasFactory;

    public const PLATFORM_TELEGRAM = 'telegram';

    public const PLATFORM_MAX = 'max';

    public const CONNECTION_TYPE_BOT = 'bot';

    public const CREDENTIAL_TOKEN = 'token';

    public const CREDENTIAL_WEBHOOK_SECRET = 'webhook_secret';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'platform',
        'connection_type',
        'credentials',
        'bot_external_id',
        'bot_username',
        'bot_name',
        'bot_profile_url',
        'last_webhook_received_at',
        'last_reply_sent_at',
        'last_error_at',
        'last_error_message',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'credentials' => 'encrypted:array',
        'last_webhook_received_at' => 'datetime',
        'last_reply_sent_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    public static function platformOptions(): array
    {
        return [
            self::PLATFORM_TELEGRAM => 'Telegram',
            self::PLATFORM_MAX => 'MAX',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function connectionTypeOptions(): array
    {
        return [
            self::CONNECTION_TYPE_BOT => 'Bot',
        ];
    }

    protected function displayTitle(): Attribute
    {
        return Attribute::make(
            get: fn (): string => sprintf(
                '#%d %s (%s)',
                $this->id,
                $this->name,
                self::platformOptions()[$this->platform] ?? $this->platform,
            ),
        );
    }

    public function getToken(): ?string
    {
        $token = data_get($this->credentials, self::CREDENTIAL_TOKEN);

        return filled($token) ? (string) $token : null;
    }

    public function getWebhookSecret(): ?string
    {
        $secret = data_get($this->credentials, self::CREDENTIAL_WEBHOOK_SECRET);

        return filled($secret) ? (string) $secret : null;
    }

    public function putCredential(string $key, mixed $value): static
    {
        $credentials = $this->credentials ?? [];

        Arr::set($credentials, $key, $value);

        $this->credentials = $credentials;

        return $this;
    }

    public function getBotUsernameLabel(): ?string
    {
        if (! filled($this->bot_username)) {
            return null;
        }

        return '@'.ltrim((string) $this->bot_username, '@');
    }

    public function getBotProfileUrl(): ?string
    {
        if (filled($this->bot_profile_url)) {
            return (string) $this->bot_profile_url;
        }

        if (! filled($this->bot_username)) {
            return null;
        }

        return match ($this->platform) {
            self::PLATFORM_TELEGRAM => 'https://t.me/'.ltrim((string) $this->bot_username, '@'),
            self::PLATFORM_MAX => 'https://max.ru/'.ltrim((string) $this->bot_username, '@'),
            default => null,
        };
    }

    public function getWebhookStatusLabel(): string
    {
        return filled($this->getWebhookSecret()) ? 'Настроен' : 'Не настроен';
    }

    public function getWebhookStatusColor(): string
    {
        return filled($this->getWebhookSecret()) ? 'success' : 'gray';
    }

    public function getHealthStatusLabel(): string
    {
        if (! $this->is_active) {
            return 'Отключен';
        }

        if (! filled($this->getWebhookSecret())) {
            return 'Без webhook';
        }

        if ($this->last_error_at !== null && ($this->last_reply_sent_at === null || $this->last_error_at->greaterThanOrEqualTo($this->last_reply_sent_at))) {
            return 'Ошибка';
        }

        if ($this->last_reply_sent_at !== null) {
            return 'Работает';
        }

        if ($this->last_webhook_received_at !== null) {
            return 'Webhook';
        }

        return 'Не проверен';
    }

    public function getHealthStatusColor(): string
    {
        return match ($this->getHealthStatusLabel()) {
            'Работает' => 'success',
            'Webhook' => 'info',
            'Не проверен' => 'gray',
            'Без webhook' => 'warning',
            'Ошибка' => 'danger',
            'Отключен' => 'gray',
            default => 'gray',
        };
    }

    public function markWebhookReceived(): static
    {
        $this->forceFill([
            'last_webhook_received_at' => now(),
        ])->saveQuietly();

        return $this;
    }

    public function markReplySent(): static
    {
        $this->forceFill([
            'last_reply_sent_at' => now(),
            'last_error_at' => null,
            'last_error_message' => null,
        ])->saveQuietly();

        return $this;
    }

    public function markError(string|Throwable $error): static
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        $this->forceFill([
            'last_error_at' => now(),
            'last_error_message' => Str::limit(trim($message), 1000),
        ])->saveQuietly();

        return $this;
    }

    public function clearOperationalError(): static
    {
        $this->forceFill([
            'last_error_at' => null,
            'last_error_message' => null,
        ])->saveQuietly();

        return $this;
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ChannelActivityLog::class);
    }

    public function contactIdentities(): HasMany
    {
        return $this->hasMany(ContactIdentity::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
