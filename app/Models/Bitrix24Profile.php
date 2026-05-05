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

    public const ADMIN_OAUTH_CALLBACK_PATH = '/admin/bitrix24/oauth/callback';

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
        'telegram_source_id',
        'max_source_id',
        'telegram_connector_code',
        'max_connector_code',
        'default_assigned_user_id',
        'default_deal_category_id',
        'default_deal_stage_id',
        'crm_field_name_source',
        'crm_field_age_exact',
        'crm_field_gender',
        'crm_field_age_range',
        'crm_field_contact_id',
        'crm_field_channel_id',
        'crm_field_channel_name',
        'crm_field_platform',
        'crm_field_bot_code',
        'crm_field_bot_name',
        'crm_field_alt_first_name',
        'crm_field_alt_last_name',
        'crm_field_name_conflict',
        'crm_name_source_automatic_id',
        'crm_name_source_self_reported_id',
        'crm_name_source_training_verified_id',
        'crm_gender_male_id',
        'crm_gender_female_id',
        'crm_gender_unknown_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'default_assigned_user_id' => 'integer',
        'default_deal_category_id' => 'integer',
        'crm_name_source_automatic_id' => 'integer',
        'crm_name_source_self_reported_id' => 'integer',
        'crm_name_source_training_verified_id' => 'integer',
        'crm_gender_male_id' => 'integer',
        'crm_gender_female_id' => 'integer',
        'crm_gender_unknown_id' => 'integer',
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

    public function openLineRoutes(): HasMany
    {
        return $this->hasMany(Bitrix24OpenLineRoute::class);
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

    public function adminOAuthCallbackUrl(): string
    {
        return $this->buildCallbackUrl(self::ADMIN_OAUTH_CALLBACK_PATH);
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

    public function sourceIdForPlatform(string $platform): ?string
    {
        return match ($platform) {
            Channel::PLATFORM_TELEGRAM => $this->nullableRoutingValue($this->telegram_source_id),
            Channel::PLATFORM_MAX => $this->nullableRoutingValue($this->max_source_id),
            default => null,
        };
    }

    public function openLinesConnectorCodeForPlatform(string $platform): ?string
    {
        return match ($platform) {
            Channel::PLATFORM_TELEGRAM => $this->nullableRoutingValue($this->telegram_connector_code),
            Channel::PLATFORM_MAX => $this->nullableRoutingValue($this->max_connector_code),
            default => null,
        };
    }

    public function effectiveDefaultAssignedUserId(): int
    {
        return $this->nullableInteger($this->default_assigned_user_id)
            ?? (int) config('bitrix24.defaults.assigned_user_id', 1);
    }

    public function effectiveDefaultDealCategoryId(): int
    {
        return $this->nullableInteger($this->default_deal_category_id)
            ?? (int) config('bitrix24.defaults.deal_category_id', 22);
    }

    public function effectiveDefaultDealStageId(): string
    {
        return $this->nullableRoutingValue($this->default_deal_stage_id)
            ?? (string) config('bitrix24.defaults.deal_stage_id', 'C22:NEW');
    }

    /**
     * @return array<string, string>
     */
    public function effectiveCrmFields(): array
    {
        return [
            'name_source' => $this->effectiveCrmField('crm_field_name_source', 'name_source'),
            'age_exact' => $this->effectiveCrmField('crm_field_age_exact', 'age_exact'),
            'gender' => $this->effectiveCrmField('crm_field_gender', 'gender'),
            'age_range' => $this->effectiveCrmField('crm_field_age_range', 'age_range'),
            'contact_id' => $this->effectiveCrmField('crm_field_contact_id', 'contact_id'),
            'channel_id' => $this->effectiveCrmField('crm_field_channel_id', 'channel_id'),
            'channel_name' => $this->effectiveCrmField('crm_field_channel_name', 'channel_name'),
            'platform' => $this->effectiveCrmField('crm_field_platform', 'platform'),
            'bot_code' => $this->effectiveCrmField('crm_field_bot_code', 'bot_code'),
            'bot_name' => $this->effectiveCrmField('crm_field_bot_name', 'bot_name'),
            'alt_first_name' => $this->effectiveCrmField('crm_field_alt_first_name', 'alt_first_name'),
            'alt_last_name' => $this->effectiveCrmField('crm_field_alt_last_name', 'alt_last_name'),
            'name_conflict' => $this->effectiveCrmField('crm_field_name_conflict', 'name_conflict'),
        ];
    }

    /**
     * @return array{name_source: array<string, int>, gender: array<string, int>}
     */
    public function effectiveCrmValues(): array
    {
        return [
            'name_source' => [
                'automatic_information_id' => $this->effectiveCrmInteger('crm_name_source_automatic_id', 'values.name_source.automatic_information_id'),
                'self_reported_id' => $this->effectiveCrmInteger('crm_name_source_self_reported_id', 'values.name_source.self_reported_id'),
                'training_verified_id' => $this->effectiveCrmInteger('crm_name_source_training_verified_id', 'values.name_source.training_verified_id'),
            ],
            'gender' => [
                'male_id' => $this->effectiveCrmInteger('crm_gender_male_id', 'values.gender.male_id'),
                'female_id' => $this->effectiveCrmInteger('crm_gender_female_id', 'values.gender.female_id'),
                'unknown_id' => $this->effectiveCrmInteger('crm_gender_unknown_id', 'values.gender.unknown_id'),
            ],
        ];
    }

    private function buildCallbackUrl(string $path): string
    {
        return rtrim($this->callback_base_url, '/').$path;
    }

    private function effectiveCrmField(string $attribute, string $configKey): string
    {
        return $this->nullableRoutingValue($this->{$attribute} ?? null)
            ?? (string) config('bitrix24.fields.'.$configKey);
    }

    private function effectiveCrmInteger(string $attribute, string $configKey): int
    {
        return $this->nullableInteger($this->{$attribute} ?? null)
            ?? (int) config('bitrix24.'.$configKey);
    }

    private function nullableRoutingValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer >= 0 ? $integer : null;
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
