<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24SetupReportResult;

class BuildBitrix24SetupReportAction
{
    public function handle(): Bitrix24SetupReportResult
    {
        /** @var array<string, mixed> $config */
        $config = config('bitrix24', []);

        $checks = [
            $this->buildPortalDomainCheck((string) ($config['portal_domain'] ?? '')),
            $this->buildRequiredValueCheck(
                key: 'application.client_id',
                label: 'Bitrix24 client_id',
                value: (string) data_get($config, 'application.client_id', ''),
                notes: 'Required for production local app OAuth.',
            ),
            $this->buildRequiredValueCheck(
                key: 'application.client_secret',
                label: 'Bitrix24 client_secret',
                value: (string) data_get($config, 'application.client_secret', ''),
                notes: 'Required for production local app OAuth.',
            ),
            $this->buildRequiredValueCheck(
                key: 'application.code',
                label: 'Bitrix24 application code',
                value: (string) data_get($config, 'application.code', ''),
                notes: 'Required to verify install callbacks via app.info before activating the connection.',
            ),
            $this->buildAbsoluteHttpsUrlCheck(
                key: 'oauth.server_url',
                label: 'Bitrix24 OAuth server URL',
                value: (string) data_get($config, 'oauth.server_url', ''),
                notes: 'Required as the trusted OAuth refresh host for install and token refresh flows.',
            ),
            $this->buildCallbackCheck(
                key: 'callbacks.install_url',
                label: 'Install callback URL',
                value: (string) data_get($config, 'callbacks.install_url', ''),
            ),
            $this->buildCallbackCheck(
                key: 'callbacks.events_url',
                label: 'CRM events callback URL',
                value: (string) data_get($config, 'callbacks.events_url', ''),
            ),
            $this->buildCallbackCheck(
                key: 'callbacks.openlines_url',
                label: 'Open Lines callback URL',
                value: (string) data_get($config, 'callbacks.openlines_url', ''),
            ),
            $this->buildRequiredValueCheck(
                key: 'sources.telegram_id',
                label: 'Telegram SOURCE_ID',
                value: (string) data_get($config, 'sources.telegram_id', ''),
                notes: 'Create and freeze a clean Abrikosoff Telegram source value.',
            ),
            $this->buildRequiredValueCheck(
                key: 'sources.max_id',
                label: 'MAX SOURCE_ID',
                value: (string) data_get($config, 'sources.max_id', ''),
                notes: 'Create and freeze a clean Abrikosoff MAX source value.',
            ),
            $this->buildRequiredValueCheck(
                key: 'openlines.telegram_line_id',
                label: 'Telegram LINE_ID',
                value: (string) data_get($config, 'openlines.telegram_line_id', ''),
                notes: 'Required for the Telegram Open Lines connector.',
            ),
            $this->buildRequiredValueCheck(
                key: 'openlines.max_line_id',
                label: 'MAX LINE_ID',
                value: (string) data_get($config, 'openlines.max_line_id', ''),
                notes: 'Required for the MAX Open Lines connector.',
            ),
            $this->buildRequiredValueCheck(
                key: 'openlines.telegram_connector_code',
                label: 'Telegram connector_code',
                value: (string) data_get($config, 'openlines.telegram_connector_code', ''),
                notes: 'Required for stable custom connector registration.',
            ),
            $this->buildRequiredValueCheck(
                key: 'openlines.max_connector_code',
                label: 'MAX connector_code',
                value: (string) data_get($config, 'openlines.max_connector_code', ''),
                notes: 'Required for stable custom connector registration.',
            ),
            $this->buildOpenLinesServiceUserCheck(
                openLinesEnabled: (bool) data_get($config, 'features.openlines_enabled', false),
                fakeHappyPathEnabled: (bool) data_get($config, 'features.fake_happy_path_enabled', false),
                value: (string) data_get($config, 'openlines.service_user_id', ''),
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
                notes: 'Must be frozen before the integration foundation stage starts.',
            );
        }

        return new Bitrix24SetupReportResult(
            checks: $checks,
            frozenValues: $this->buildFrozenValues($config),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{group: string, label: string, value: string}>
     */
    private function buildFrozenValues(array $config): array
    {
        $frozenValues = [
            $this->frozenValue('portal', 'portal_domain', (string) ($config['portal_domain'] ?? '')),
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
    private function buildPortalDomainCheck(string $value): array
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return $this->check('portal_domain', 'Bitrix24 portal domain', '—', Bitrix24SetupReportResult::STATUS_MISSING, true, 'Set BITRIX24_PORTAL_DOMAIN.');
        }

        if (str_contains($trimmed, '://')) {
            return $this->check('portal_domain', 'Bitrix24 portal domain', $trimmed, Bitrix24SetupReportResult::STATUS_WARNING, true, 'Store the bare domain without scheme to avoid duplicate URL joins.');
        }

        return $this->check('portal_domain', 'Bitrix24 portal domain', $trimmed, Bitrix24SetupReportResult::STATUS_OK, true, 'Frozen discovery value.');
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function buildCallbackCheck(string $key, string $label, string $value): array
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return $this->check($key, $label, '—', Bitrix24SetupReportResult::STATUS_MISSING, true, 'Set a stable HTTPS production callback URL.');
        }

        if (! filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_MISSING, true, 'Callback must be a valid absolute URL.');
        }

        if (parse_url($trimmed, PHP_URL_SCHEME) !== 'https') {
            return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_MISSING, true, 'Production callback must use HTTPS.');
        }

        if (str_contains($trimmed, 'trycloudflare.com') || str_contains($trimmed, '/callbacks/bitrix24/probe')) {
            return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_MISSING, true, 'Discovery probe callbacks are not valid production endpoints.');
        }

        return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_OK, true, 'Production callback is frozen.');
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
    private function buildOpenLinesServiceUserCheck(
        bool $openLinesEnabled,
        bool $fakeHappyPathEnabled,
        string $value,
    ): array
    {
        $trimmed = trim($value);
        $label = 'Open Lines service user ID';
        $notes = 'Required for manual reply author export via imopenlines.crm.message.add.';

        if (! $openLinesEnabled) {
            return $this->check(
                'openlines.service_user_id',
                $label,
                $trimmed === '' ? '—' : $trimmed,
                Bitrix24SetupReportResult::STATUS_OK,
                false,
                'Open Lines live export is disabled, so the service user is not a blocking requirement.',
            );
        }

        if ($fakeHappyPathEnabled) {
            $fakeHappyPathAllowed = ! app()->environment('production');

            if (! $fakeHappyPathAllowed) {
                $notes .= ' Fake happy-path is ignored in production.';
            } else {
                return $this->check(
                    'openlines.service_user_id',
                    $label,
                    $trimmed === '' ? '—' : $trimmed,
                    Bitrix24SetupReportResult::STATUS_OK,
                    false,
                    'Fake happy-path is enabled outside production, so the service user is not a blocking requirement until real transport acceptance.',
                );
            }
        }

        if ($trimmed === '' || ! ctype_digit($trimmed) || (int) $trimmed <= 0) {
            return $this->check(
                'openlines.service_user_id',
                $label,
                $trimmed === '' ? '—' : $trimmed,
                Bitrix24SetupReportResult::STATUS_MISSING,
                true,
                'Set a positive BITRIX24_OPENLINES_SERVICE_USER_ID. '.$notes
            );
        }

        return $this->check(
            'openlines.service_user_id',
            $label,
            $trimmed,
            Bitrix24SetupReportResult::STATUS_OK,
            true,
            $notes,
        );
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function buildNumericCheck(
        string $key,
        string $label,
        string $value,
        string $expected,
        string $notes,
    ): array {
        $trimmed = trim($value);

        if ($trimmed === '' || ! ctype_digit(ltrim($trimmed, '-'))) {
            return $this->check($key, $label, $trimmed === '' ? '—' : $trimmed, Bitrix24SetupReportResult::STATUS_MISSING, true, 'Expected numeric value. '.$notes);
        }

        if ($trimmed !== $expected) {
            return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_MISSING, true, 'Discovery froze `'.$expected.'`. '.$notes);
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
            return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_MISSING, true, 'Discovery froze `'.$expected.'`. '.$notes);
        }

        return $this->check($key, $label, $trimmed, Bitrix24SetupReportResult::STATUS_OK, true, $notes);
    }

    /**
     * @return array{group: string, label: string, value: string}
     */
    private function frozenValue(string $group, string $label, string $value): array
    {
        return [
            'group' => $group,
            'label' => $label,
            'value' => $value === '' ? '—' : $value,
        ];
    }

    private function stringifyFrozenValue(mixed $value): string
    {
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : '[unserializable array]';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    /**
     * @return array{key: string, label: string, value: string, status: string, required: bool, notes: string}
     */
    private function check(
        string $key,
        string $label,
        string $value,
        string $status,
        bool $required,
        string $notes,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'status' => $status,
            'required' => $required,
            'notes' => $notes,
        ];
    }
}
