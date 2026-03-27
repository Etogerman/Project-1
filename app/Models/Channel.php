<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

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
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'credentials' => 'encrypted:array',
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
}
