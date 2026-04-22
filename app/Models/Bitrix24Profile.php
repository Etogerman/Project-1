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
}
