<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BackfillBitrix24ConnectionProfilesAction
{
    public function __construct(
        private readonly NormalizeBitrix24CallbackBaseUrlAction $normalizeCallbackBaseUrl,
    ) {}

    public function handle(): void
    {
        $portalDomain = $this->normalizePortalDomain(config('bitrix24.portal_domain'));
        $callbackBaseUrl = $this->resolveLegacyCallbackBaseUrl();

        if ($portalDomain === null || $callbackBaseUrl === null) {
            return;
        }

        $profile = Bitrix24Profile::query()->firstOrNew([
            'portal_domain' => $portalDomain,
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
        ]);

        $clientId = $this->nullableString(config('bitrix24.application.client_id'));
        $applicationCode = $this->nullableString(config('bitrix24.application.code'));
        $profileClientId = $profile->exists ? $profile->client_id : $clientId;
        $profileApplicationCode = $profile->exists ? $profile->application_code : $applicationCode;

        $updates = [
            'profile_type' => $profile->profile_type ?: Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => $profile->display_name ?: 'Staging',
            'client_id' => $profileClientId,
            'application_code' => $profileApplicationCode,
            'callback_base_url' => $callbackBaseUrl,
        ];

        if (Schema::hasColumn('bitrix24_profiles', 'default_assigned_user_id')) {
            $updates['default_assigned_user_id'] = $profile->default_assigned_user_id ?? (int) config('bitrix24.defaults.assigned_user_id', 1);
        }

        if (Schema::hasColumn('bitrix24_profiles', 'default_deal_category_id')) {
            $updates['default_deal_category_id'] = $profile->default_deal_category_id ?? (int) config('bitrix24.defaults.deal_category_id', 22);
        }

        if (Schema::hasColumn('bitrix24_profiles', 'default_deal_stage_id')) {
            $updates['default_deal_stage_id'] = $profile->default_deal_stage_id ?: (string) config('bitrix24.defaults.deal_stage_id', 'C22:NEW');
        }

        foreach ($this->crmSchemaBackfillValues($profile) as $column => $value) {
            if (Schema::hasColumn('bitrix24_profiles', $column)) {
                $updates[$column] = $value;
            }
        }

        $profile->forceFill($updates);

        DB::transaction(function () use ($portalDomain, $profile): void {
            $this->assertCallbackBaseUrlIsAvailableForProfile(
                $profile,
                Bitrix24Profile::normalizeCallbackBaseUrl($profile->callback_base_url)
                    ?? (string) $profile->callback_base_url,
            );

            if (! $profile->exists || $profile->isDirty()) {
                $profile->save();
            }

            $this->ensureDefaultCallbackOwner($profile);

            Bitrix24Connection::query()
                ->whereNull('profile_id')
                ->get()
                ->each(function (Bitrix24Connection $connection) use ($portalDomain, $profile): void {
                    if ($this->normalizePortalDomain($connection->portal_domain) !== $portalDomain) {
                        return;
                    }

                    $connection->forceFill([
                        'profile_id' => $profile->id,
                    ])->save();
                });
        });
    }

    private function resolveLegacyCallbackBaseUrl(): ?string
    {
        foreach ([
            config('bitrix24.callbacks.install_url'),
            config('bitrix24.callbacks.events_url'),
            config('bitrix24.callbacks.openlines_url'),
        ] as $candidate) {
            $normalized = $this->normalizeCallbackBaseUrl->handle($this->stripKnownCallbackPath($this->nullableString($candidate)));

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function stripKnownCallbackPath(?string $url): ?string
    {
        if ($url === null || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        $normalizedPath = is_string($path)
            ? rtrim('/'.ltrim($path, '/'), '/')
            : '';

        foreach ([
            Bitrix24Profile::INSTALL_CALLBACK_PATH,
            Bitrix24Profile::EVENTS_CALLBACK_PATH,
            Bitrix24Profile::OPENLINES_CALLBACK_PATH,
        ] as $suffix) {
            if (! str_ends_with($normalizedPath, $suffix)) {
                continue;
            }

            $prefixPath = substr($normalizedPath, 0, -strlen($suffix));
            $normalizedPath = $prefixPath === false
                ? ''
                : rtrim($prefixPath, '/');

            break;
        }

        $normalizedPort = is_int($port) ? ':'.$port : '';

        return mb_strtolower($scheme).'://'.mb_strtolower($host).$normalizedPort.$normalizedPath;
    }

    private function normalizePortalDomain(mixed $value): ?string
    {
        $trimmed = $this->nullableString($value);

        if ($trimmed === null) {
            return null;
        }

        $host = parse_url($trimmed, PHP_URL_HOST);

        if (is_string($host) && trim($host) !== '') {
            return mb_strtolower(trim($host));
        }

        return mb_strtolower(trim($trimmed, "/ \t\n\r\0\x0B"));
    }

    private function ensureDefaultCallbackOwner(Bitrix24Profile $profile): void
    {
        if (! Schema::hasTable('bitrix24_callback_owners')) {
            return;
        }

        $callbackBaseUrl = Bitrix24Profile::normalizeCallbackBaseUrl($profile->callback_base_url)
            ?? (string) $profile->callback_base_url;

        if ($callbackBaseUrl === '') {
            return;
        }

        Bitrix24CallbackOwner::query()->updateOrCreate(
            [
                'bitrix24_profile_id' => $profile->id,
                'owner_key' => Bitrix24CallbackOwner::DEFAULT_LOCAL_OWNER_KEY,
            ],
            [
                'display_name' => 'Локалка 1',
                'callback_base_url' => $callbackBaseUrl,
                'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
            ],
        );
    }

    private function assertCallbackBaseUrlIsAvailableForProfile(Bitrix24Profile $profile, string $callbackBaseUrl): void
    {
        if (! Schema::hasTable('bitrix24_callback_owners')) {
            return;
        }

        $ownerConflict = Bitrix24CallbackOwner::query()
            ->with('bitrix24Profile')
            ->where('callback_base_url', $callbackBaseUrl)
            ->when(
                $profile->exists,
                fn ($query) => $query->where('bitrix24_profile_id', '!=', $profile->getKey()),
            )
            ->first();

        if (! $ownerConflict instanceof Bitrix24CallbackOwner) {
            return;
        }

        $ownerProfile = $ownerConflict->bitrix24Profile;
        $ownerProfileKey = $ownerProfile instanceof Bitrix24Profile
            ? $ownerProfile->profile_key
            : '#'.$ownerConflict->bitrix24_profile_id;

        throw new RuntimeException(sprintf(
            'callback_base_url `%s` is already assigned to callback owner `%s` on profile `%s`.',
            $callbackBaseUrl,
            $ownerConflict->owner_key,
            $ownerProfileKey,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function crmSchemaBackfillValues(Bitrix24Profile $profile): array
    {
        return [
            'crm_field_name_source' => $profile->crm_field_name_source ?: config('bitrix24.fields.name_source'),
            'crm_field_age_exact' => $profile->crm_field_age_exact ?: config('bitrix24.fields.age_exact'),
            'crm_field_gender' => $profile->crm_field_gender ?: config('bitrix24.fields.gender'),
            'crm_field_age_range' => $profile->crm_field_age_range ?: config('bitrix24.fields.age_range'),
            'crm_field_contact_id' => $profile->crm_field_contact_id ?: config('bitrix24.fields.contact_id'),
            'crm_field_channel_id' => $profile->crm_field_channel_id ?: config('bitrix24.fields.channel_id'),
            'crm_field_channel_name' => $profile->crm_field_channel_name ?: config('bitrix24.fields.channel_name'),
            'crm_field_platform' => $profile->crm_field_platform ?: config('bitrix24.fields.platform'),
            'crm_field_bot_code' => $profile->crm_field_bot_code ?: config('bitrix24.fields.bot_code'),
            'crm_field_bot_name' => $profile->crm_field_bot_name ?: config('bitrix24.fields.bot_name'),
            'crm_field_alt_first_name' => $profile->crm_field_alt_first_name ?: config('bitrix24.fields.alt_first_name'),
            'crm_field_alt_last_name' => $profile->crm_field_alt_last_name ?: config('bitrix24.fields.alt_last_name'),
            'crm_field_name_conflict' => $profile->crm_field_name_conflict ?: config('bitrix24.fields.name_conflict'),
            'crm_name_source_automatic_id' => $profile->crm_name_source_automatic_id ?? (int) config('bitrix24.values.name_source.automatic_information_id'),
            'crm_name_source_self_reported_id' => $profile->crm_name_source_self_reported_id ?? (int) config('bitrix24.values.name_source.self_reported_id'),
            'crm_name_source_training_verified_id' => $profile->crm_name_source_training_verified_id ?? (int) config('bitrix24.values.name_source.training_verified_id'),
            'crm_gender_male_id' => $profile->crm_gender_male_id ?? (int) config('bitrix24.values.gender.male_id'),
            'crm_gender_female_id' => $profile->crm_gender_female_id ?? (int) config('bitrix24.values.gender.female_id'),
            'crm_gender_unknown_id' => $profile->crm_gender_unknown_id ?? (int) config('bitrix24.values.gender.unknown_id'),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
