<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bitrix24Profile extends Model
{
    use HasFactory;

    public const PROFILE_KEY_STAGING = 'staging';

    public const TYPE_FULL_LIVE = 'full_live';

    public const TYPE_CRM_ONLY = 'crm_only';

    public const INSTALL_CALLBACK_PATH = '/callbacks/bitrix24/install';

    public const EVENTS_CALLBACK_PATH = '/callbacks/bitrix24/events';

    public const OPENLINES_CALLBACK_PATH = '/callbacks/bitrix24/openlines';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'portal_domain',
        'profile_key',
        'profile_type',
        'display_name',
        'client_id',
        'application_code',
        'callback_base_url',
    ];

    public function setCallbackBaseUrlAttribute(mixed $value): void
    {
        if (! is_scalar($value)) {
            $this->attributes['callback_base_url'] = '';

            return;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            $this->attributes['callback_base_url'] = '';

            return;
        }

        $this->attributes['callback_base_url'] = self::normalizeCallbackBaseUrl($trimmed) ?? $trimmed;
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Bitrix24Connection::class, 'profile_id');
    }

    public function installCallbackUrl(): string
    {
        return $this->buildCallbackUrl(self::INSTALL_CALLBACK_PATH);
    }

    public function eventsCallbackUrl(): string
    {
        return $this->buildCallbackUrl(self::EVENTS_CALLBACK_PATH);
    }

    public function openlinesCallbackUrl(): string
    {
        return $this->buildCallbackUrl(self::OPENLINES_CALLBACK_PATH);
    }

    public function allowsCallbackType(string $callbackType): bool
    {
        return match ($this->profile_type) {
            self::TYPE_FULL_LIVE => in_array($callbackType, [
                Bitrix24WebhookEvent::TYPE_INSTALL,
                Bitrix24WebhookEvent::TYPE_EVENTS,
                Bitrix24WebhookEvent::TYPE_OPENLINES,
            ], true),
            self::TYPE_CRM_ONLY => in_array($callbackType, [
                Bitrix24WebhookEvent::TYPE_INSTALL,
                Bitrix24WebhookEvent::TYPE_EVENTS,
            ], true),
            default => false,
        };
    }

    private function buildCallbackUrl(string $path): string
    {
        return rtrim($this->callback_base_url, '/').$path;
    }

    public static function normalizeCallbackBaseUrl(?string $value): ?string
    {
        if (! filled($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);
        $port = parse_url($value, PHP_URL_PORT);
        $path = parse_url($value, PHP_URL_PATH);

        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        $normalizedPath = is_string($path)
            ? rtrim('/'.ltrim($path, '/'), '/')
            : '';

        $normalizedPort = is_int($port) ? ':'.$port : '';

        return mb_strtolower($scheme).'://'.mb_strtolower($host).$normalizedPort.$normalizedPath;
    }
}
