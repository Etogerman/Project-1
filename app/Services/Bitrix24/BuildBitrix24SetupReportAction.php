<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24SetupReportResult;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;

class BuildBitrix24SetupReportAction
{
    public function __construct(
        private readonly NormalizeBitrix24CallbackBaseUrlAction $normalizeCallbackBaseUrl,
        private readonly ResolveCurrentBitrix24ProfileAction $resolveCurrentProfile,
        private readonly ResolveCurrentBitrix24ConnectionAction $resolveCurrentConnection,
    ) {}

    public function handle(): Bitrix24SetupReportResult
    {
        /** @var array<string, mixed> $config */
        $config = config('bitrix24', []);
        $profiles = Bitrix24Profile::query()
            ->orderBy('portal_domain')
            ->orderBy('profile_key')
            ->get();

        $checks = [
            $this->buildProfileRegistryPresenceCheck($profiles->count()),
            ...$this->buildProfileRegistryUniquenessChecks($profiles),
            ...$this->buildProfileChecks($profiles),
            ...$this->buildCurrentRuntimeChecks(),
            $this->buildRequiredValueCheck(
                key: 'application.client_id',
                label: 'Bitrix24 client_id',
                value: (string) data_get($config, 'application.client_id', ''),
                notes: 'Required while token refresh still uses the global OAuth client.',
            ),
            $this->buildRequiredValueCheck(
                key: 'application.client_secret',
                label: 'Bitrix24 client_secret',
                value: (string) data_get($config, 'application.client_secret', ''),
                notes: 'Required while token refresh still uses the global OAuth client.',
            ),
            $this->buildAbsoluteHttpsUrlCheck(
                key: 'oauth.server_url',
                label: 'Bitrix24 OAuth server URL',
                value: (string) data_get($config, 'oauth.server_url', ''),
                notes: 'Required while token refresh and install validation still trust the global OAuth host.',
            ),
            $this->buildRequiredValueCheck(
                key: 'sources.telegram_id',
                label: 'Telegram SOURCE_ID',
                value: (string) data_get($config, 'sources.telegram_id', ''),
                notes: 'Still required until per-profile CRM source routing is implemented.',
            ),
            $this->buildRequiredValueCheck(
                key: 'sources.max_id',
                label: 'MAX SOURCE_ID',
                value: (string) data_get($config, 'sources.max_id', ''),
                notes: 'Still required until per-profile CRM source routing is implemented.',
            ),
            $this->buildRequiredValueCheck(
                key: 'openlines.telegram_line_id',
                label: 'Telegram LINE_ID',
                value: (string) data_get($config, 'openlines.telegram_line_id', ''),
                notes: 'Still required until per-profile Open Lines routing is implemented.',
            ),
            $this->buildRequiredValueCheck(
                key: 'openlines.max_line_id',
                label: 'MAX LINE_ID',
                value: (string) data_get($config, 'openlines.max_line_id', ''),
                notes: 'Still required until per-profile Open Lines routing is implemented.',
            ),
            $this->buildRequiredValueCheck(
                key: 'openlines.telegram_connector_code',
                label: 'Telegram connector_code',
                value: (string) data_get($config, 'openlines.telegram_connector_code', ''),
                notes: 'Still required until per-profile Open Lines routing is implemented.',
            ),
            $this->buildRequiredValueCheck(
                key: 'openlines.max_connector_code',
                label: 'MAX connector_code',
                value: (string) data_get($config, 'openlines.max_connector_code', ''),
                notes: 'Still required until per-profile Open Lines routing is implemented.',
            ),
            $this->buildDistinctConnectorCodesCheck(
                (string) data_get($config, 'openlines.telegram_connector_code', ''),
                (string) data_get($config, 'openlines.max_connector_code', ''),
            ),
            $this->buildNumericCheck(
                key: 'defaults.assigned_user_id',
                label: 'Default assigned user ID',
                value: (string) data_get($config, 'defaults.assigned_user_id', ''),
                expected: '1',
                notes: 'Discovery fixed user 1 as the default assignee.',
            ),
            $this->buildNumericCheck(
                key: 'defaults.deal_category_id',
                label: 'Default deal category ID',
                value: (string) data_get($config, 'defaults.deal_category_id', ''),
                expected: '22',
                notes: 'Discovery fixed category 22 for Abrikosoff deals.',
            ),
            $this->buildRequiredValueCheck(
                key: 'defaults.deal_stage_id',
                label: 'Default deal stage ID',
                value: (string) data_get($config, 'defaults.deal_stage_id', ''),
                notes: 'Discovery fixed C22:NEW for new Abrikosoff deals.',
                expected: 'C22:NEW',
            ),
        ];

        foreach ((array) ($config['fields'] ?? []) as $fieldKey => $fieldValue) {
            $checks[] = $this->buildRequiredValueCheck(
                key: 'fields.'.$fieldKey,
                label: 'Field code: '.$fieldKey,
                value: (string) $fieldValue,
                notes: 'Must stay frozen until per-profile routing lands.',
            );
        }

        return new Bitrix24SetupReportResult(
            checks: $checks,
            frozenValues: $this->buildFrozenValues($config, $profiles->all()),
        );
    }

    /**
     * @return list<array{key: string, label: string, value: string, status: string, required: bool, notes: string}>
     */
    private function buildCurrentRuntimeChecks(): array
    {
        try {
            $profile = $this->resolveCurrentProfile->handle();
        } catch (Bitrix24ConnectionStateException $exception) {
            return [$this->check(
                'runtime.current_profile',
                'Current runtime Bitrix24 profile',
                '—',
                Bitrix24SetupReportResult::STATUS_MISSING,
                true,
                $exception->getMessage(),
            )];
        }

        $checks = [$this->check(
            'runtime.current_profile',
            'Current runtime Bitrix24 profile',
            sprintf('%s (%s)', $profile->profile_key, $profile->callback_base_url),
            Bitrix24SetupReportResult::STATUS_OK,
            true,
            'Configured callback URLs resolve to exactly one full_live Bitrix24 profile for outbound/runtime selection.',
        )];

        try {
            $connection = $this->resolveCurrentConnection->handle();
        } catch (NoActiveBitrix24ConnectionException|Bitrix24ConnectionStateException $exception) {
            $checks[] = $this->check(
                'runtime.current_connection',
                'Current runtime Bitrix24 connection',
                '—',
                Bitrix24SetupReportResult::STATUS_MISSING,
                true,
                $exception->getMessage(),
            );

            return $checks;
        }

        $checks[] = $this->buildCurrentRuntimeConnectionCheck($connection);

        return $checks;
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function buildCurrentRuntimeConnectionCheck(Bitrix24Connection $connection): array
    {
        return $this->check(
            'runtime.current_connection',
            'Current runtime Bitrix24 connection',
            sprintf('#%d (%s)', $connection->id, $connection->profile?->profile_key ?? '—'),
            Bitrix24SetupReportResult::STATUS_OK,
            true,
            'Current runtime selector resolves to exactly one active Bitrix24 connection for covered Slice 2A runtime paths.',
        );
    }

    /**
     * @param  list<Bitrix24Profile>  $profiles
     * @return list<array{key: string, label: string, value: string, status: string, required: bool, notes: string}>
     */
    private function buildProfileRegistryUniquenessChecks($profiles): array
    {
        $profileKeys = [];
        $callbackBaseUrls = [];

        foreach ($profiles as $profile) {
            $profileIdentity = $profile->portal_domain.'|'.$profile->profile_key;
            $normalizedCallbackBaseUrl = $this->normalizeCallbackBaseUrl->handle($profile->callback_base_url) ?? $profile->callback_base_url;
            $profileKeys[$profileIdentity] = ($profileKeys[$profileIdentity] ?? 0) + 1;
            $callbackBaseUrls[$normalizedCallbackBaseUrl] = ($callbackBaseUrls[$normalizedCallbackBaseUrl] ?? 0) + 1;
        }

        return [
            $this->buildDuplicateCountCheck(
                key: 'profiles.portal_profile_unique',
                label: 'Unique portal_domain + profile_key',
                duplicates: array_filter($profileKeys, fn (int $count): bool => $count > 1),
            ),
            $this->buildDuplicateCountCheck(
                key: 'profiles.callback_base_url_unique',
                label: 'Globally unique callback_base_url',
                duplicates: array_filter($callbackBaseUrls, fn (int $count): bool => $count > 1),
            ),
        ];
    }

    /**
     * @param  list<Bitrix24Profile>  $profiles
     * @return list<array{key: string, label: string, value: string, status: string, required: bool, notes: string}>
     */
    private function buildProfileChecks($profiles): array
    {
        $checks = [];

        foreach ($profiles as $profile) {
            $prefix = 'profiles.'.$profile->profile_key;

            $checks[] = $this->buildPortalDomainCheck(
                key: $prefix.'.portal_domain',
                label: sprintf('Profile `%s` portal domain', $profile->profile_key),
                value: $profile->portal_domain,
            );
            $checks[] = $this->buildRequiredValueCheck(
                key: $prefix.'.display_name',
                label: sprintf('Profile `%s` display name', $profile->profile_key),
                value: $profile->display_name,
                notes: 'Required for diagnostics and operator-facing setup output.',
            );
            $checks[] = $this->buildRequiredValueCheck(
                key: $prefix.'.client_id',
                label: sprintf('Profile `%s` client_id', $profile->profile_key),
                value: (string) $profile->client_id,
                notes: 'Required by the profile registry contract.',
            );
            $checks[] = $this->buildRequiredValueCheck(
                key: $prefix.'.application_code',
                label: sprintf('Profile `%s` application code', $profile->profile_key),
                value: (string) $profile->application_code,
                notes: 'Required to validate install callbacks via app.info for the resolved profile.',
            );
            $checks[] = $this->buildCallbackBaseUrlCheck($profile);
            $checks[] = $this->buildProfileTypeCheck($profile);
            $checks[] = $this->buildCallbackMatrixCheck($profile);
        }

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<Bitrix24Profile>  $profiles
     * @return list<array{group: string, label: string, value: string}>
     */
    private function buildFrozenValues(array $config, array $profiles): array
    {
        $frozenValues = [
            $this->frozenValue('defaults', 'assigned_user_id', (string) data_get($config, 'defaults.assigned_user_id', '')),
            $this->frozenValue('defaults', 'deal_category_id', (string) data_get($config, 'defaults.deal_category_id', '')),
            $this->frozenValue('defaults', 'deal_stage_id', (string) data_get($config, 'defaults.deal_stage_id', '')),
            $this->frozenValue('values.name_source', 'automatic_information_id', (string) data_get($config, 'values.name_source.automatic_information_id', '')),
            $this->frozenValue('values.name_source', 'self_reported_id', (string) data_get($config, 'values.name_source.self_reported_id', '')),
            $this->frozenValue('values.name_source', 'training_verified_id', (string) data_get($config, 'values.name_source.training_verified_id', '')),
            $this->frozenValue('values.gender', 'male_id', (string) data_get($config, 'values.gender.male_id', '')),
            $this->frozenValue('values.gender', 'female_id', (string) data_get($config, 'values.gender.female_id', '')),
            $this->frozenValue('values.gender', 'unknown_id', (string) data_get($config, 'values.gender.unknown_id', '')),
        ];

        foreach ($profiles as $profile) {
            $frozenValues[] = $this->frozenValue('profiles', $profile->profile_key.'.portal_domain', $profile->portal_domain);
            $frozenValues[] = $this->frozenValue('profiles', $profile->profile_key.'.profile_type', $profile->profile_type);
            $frozenValues[] = $this->frozenValue('profiles', $profile->profile_key.'.callback_base_url', $profile->callback_base_url);
        }

        foreach ((array) ($config['sources'] ?? []) as $key => $value) {
            $frozenValues[] = $this->frozenValue('sources', $key, $this->stringifyFrozenValue($value));
        }

        foreach ((array) ($config['openlines'] ?? []) as $key => $value) {
            $frozenValues[] = $this->frozenValue('openlines', $key, $this->stringifyFrozenValue($value));
        }

        foreach ((array) ($config['fields'] ?? []) as $key => $value) {
            $frozenValues[] = $this->frozenValue('fields', $key, $this->stringifyFrozenValue($value));
        }

        return $frozenValues;
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function buildProfileRegistryPresenceCheck(int $count): array
    {
        if ($count === 0) {
            return $this->check(
                'profiles.registry',
                'Bitrix24 profile registry',
                '—',
                Bitrix24SetupReportResult::STATUS_MISSING,
                true,
                'Create at least one pre-created Bitrix24 profile before accepting callbacks.',
            );
        }

        return $this->check(
            'profiles.registry',
            'Bitrix24 profile registry',
            (string) $count,
            Bitrix24SetupReportResult::STATUS_OK,
            true,
            'Profile registry is present.',
        );
    }

    /**
     * @param  array<string, int>  $duplicates
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function buildDuplicateCountCheck(string $key, string $label, array $duplicates): array
    {
        if ($duplicates === []) {
            return $this->check($key, $label, 'ok', Bitrix24SetupReportResult::STATUS_OK, true, 'No duplicates detected.');
        }

        return $this->check(
            $key,
            $label,
            implode(', ', array_keys($duplicates)),
            Bitrix24SetupReportResult::STATUS_MISSING,
            true,
            'Duplicate registry identities must be resolved before callbacks are accepted.',
        );
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function buildPortalDomainCheck(string $key, string $label, string $value): array
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return $this->check($key, $label, '—', Bitrix24SetupReportResult::STATUS_MISSING, true, 'Portal domain is required.');
        }

        if (str_contains($trimmed, '://')) {
            return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_WARNING, true, 'Store the bare domain without scheme.');
        }

        return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_OK, true, 'Portal domain is frozen.');
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function buildCallbackBaseUrlCheck(Bitrix24Profile $profile): array
    {
        $label = sprintf('Profile `%s` callback_base_url', $profile->profile_key);
        $normalized = $this->normalizeCallbackBaseUrl->handle($profile->callback_base_url);

        if ($normalized === null) {
            return $this->check(
                'profiles.'.$profile->profile_key.'.callback_base_url',
                $label,
                $profile->callback_base_url,
                Bitrix24SetupReportResult::STATUS_MISSING,
                true,
                'callback_base_url must be a valid absolute base URL.',
            );
        }

        if ($normalized !== $profile->callback_base_url) {
            return $this->check(
                'profiles.'.$profile->profile_key.'.callback_base_url',
                $label,
                $profile->callback_base_url,
                Bitrix24SetupReportResult::STATUS_MISSING,
                true,
                sprintf(
                    'Stored callback_base_url must already be normalized to the canonical ingress form `%s`.',
                    $normalized,
                ),
            );
        }

        $isTunnel = str_contains($normalized, 'trycloudflare.com');
        $isDevProfile = str_starts_with($profile->profile_key, 'dev-');

        if ($isTunnel && ! $isDevProfile) {
            return $this->check(
                'profiles.'.$profile->profile_key.'.callback_base_url',
                $label,
                $normalized,
                Bitrix24SetupReportResult::STATUS_MISSING,
                true,
                'Tunnel callback_base_url values are allowed only for dev-* profiles.',
            );
        }

        return $this->check(
            'profiles.'.$profile->profile_key.'.callback_base_url',
            $label,
            $normalized,
            Bitrix24SetupReportResult::STATUS_OK,
            true,
            $isTunnel
                ? 'Dev profile uses an allowed tunnel callback_base_url.'
                : 'Callback base URL is valid for ingress resolution.',
        );
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function buildProfileTypeCheck(Bitrix24Profile $profile): array
    {
        $allowedTypes = [
            Bitrix24Profile::TYPE_FULL_LIVE,
            Bitrix24Profile::TYPE_CRM_ONLY,
        ];

        if (! in_array($profile->profile_type, $allowedTypes, true)) {
            return $this->check(
                'profiles.'.$profile->profile_key.'.profile_type',
                sprintf('Profile `%s` profile type', $profile->profile_key),
                $profile->profile_type,
                Bitrix24SetupReportResult::STATUS_MISSING,
                true,
                'profile_type must be one of: full_live, crm_only.',
            );
        }

        return $this->check(
            'profiles.'.$profile->profile_key.'.profile_type',
            sprintf('Profile `%s` profile type', $profile->profile_key),
            $profile->profile_type,
            Bitrix24SetupReportResult::STATUS_OK,
            true,
            'Profile type is valid.',
        );
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function buildCallbackMatrixCheck(Bitrix24Profile $profile): array
    {
        $value = match ($profile->profile_type) {
            Bitrix24Profile::TYPE_FULL_LIVE => 'install, events, openlines',
            Bitrix24Profile::TYPE_CRM_ONLY => 'install, events(optional), openlines(forbidden)',
            default => '—',
        };

        $status = $value === '—'
            ? Bitrix24SetupReportResult::STATUS_MISSING
            : Bitrix24SetupReportResult::STATUS_OK;

        return $this->check(
            'profiles.'.$profile->profile_key.'.callback_matrix',
            sprintf('Profile `%s` callback matrix', $profile->profile_key),
            $value,
            $status,
            true,
            $status === Bitrix24SetupReportResult::STATUS_OK
                ? 'Callback policy is frozen for this profile type.'
                : 'Callback matrix cannot be derived until profile_type is valid.',
        );
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function buildAbsoluteHttpsUrlCheck(string $key, string $label, string $value, string $notes): array
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return $this->check($key, $label, '—', Bitrix24SetupReportResult::STATUS_MISSING, true, $notes);
        }

        if (! filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_MISSING, true, 'Value must be a valid absolute URL. '.$notes);
        }

        if (parse_url($trimmed, PHP_URL_SCHEME) !== 'https') {
            return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_MISSING, true, 'Value must use HTTPS. '.$notes);
        }

        return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_OK, true, $notes);
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function buildDistinctConnectorCodesCheck(string $telegram, string $max): array
    {
        if ($telegram === '' || $max === '') {
            return $this->check(
                'openlines.connector_code_distinct',
                'Distinct connector_code values',
                '—',
                Bitrix24SetupReportResult::STATUS_MISSING,
                true,
                'Both connector codes must be filled before they can be compared.',
            );
        }

        if ($telegram === $max) {
            return $this->check(
                'openlines.connector_code_distinct',
                'Distinct connector_code values',
                $telegram,
                Bitrix24SetupReportResult::STATUS_MISSING,
                true,
                'Telegram and MAX connector codes must be different.',
            );
        }

        return $this->check(
            'openlines.connector_code_distinct',
            'Distinct connector_code values',
            $telegram.' / '.$max,
            Bitrix24SetupReportResult::STATUS_OK,
            true,
            'Connector codes are distinct.',
        );
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function buildNumericCheck(string $key, string $label, string $value, string $expected, string $notes): array
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return $this->check($key, $label, '—', Bitrix24SetupReportResult::STATUS_MISSING, true, $notes);
        }

        if (! ctype_digit(ltrim($trimmed, '-'))) {
            return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_MISSING, true, 'Expected numeric value. '.$notes);
        }

        if ($trimmed !== $expected) {
            return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_MISSING, true, 'Expected '.$expected.'. '.$notes);
        }

        return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_OK, true, $notes);
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function buildRequiredValueCheck(
        string $key,
        string $label,
        string $value,
        string $notes,
        ?string $expected = null,
    ): array {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return $this->check($key, $label, '—', Bitrix24SetupReportResult::STATUS_MISSING, true, $notes);
        }

        if ($expected !== null && $trimmed !== $expected) {
            return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_MISSING, true, 'Expected '.$expected.'. '.$notes);
        }

        return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_OK, true, $notes);
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function check(string $key, string $label, string $value, string $status, bool $required, string $notes): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'status' => $status,
            'required' => $required,
            'notes' => $notes,
        ];
    }

    /**
     * @return array{group: string, label: string, value: string}
     */
    private function frozenValue(string $group, string $label, string $value): array
    {
        return [
            'group' => $group,
            'label' => $label,
            'value' => $value,
        ];
    }

    private function stringifyFrozenValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
