<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24DevProfileBootstrapResultData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24WebhookEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class BootstrapBitrix24DevProfileAction
{
    public function __construct(
        private readonly NormalizeBitrix24CallbackBaseUrlAction $normalizeCallbackBaseUrl,
        private readonly Bitrix24ApiClient $bitrix24ApiClient,
    ) {}

    public function handle(
        string $profileKey,
        string $callbackBaseUrl,
        mixed $clientId = null,
        mixed $applicationCode = null,
        mixed $telegramLineId = null,
        mixed $maxLineId = null,
        mixed $displayName = null,
        mixed $portalDomain = null,
    ): Bitrix24DevProfileBootstrapResultData {
        $profileKey = $this->normalizeProfileKey($profileKey);
        $this->assertValidDevProfileKey($profileKey);

        $normalizedCallbackBaseUrl = $this->normalizeCallbackBaseUrl->handle($callbackBaseUrl);

        if ($normalizedCallbackBaseUrl === null) {
            throw new Bitrix24DevProfileBootstrapException('callback_base_url must be a valid absolute URL.');
        }

        if ($telegramLineId !== null || $maxLineId !== null) {
            throw new Bitrix24DevProfileBootstrapException(
                'LINE_ID is configured per concrete channel route in the Bitrix24 admin page, not on the Bitrix24 profile.',
            );
        }

        $resolvedPortalDomain = $this->resolvePortalDomain($portalDomain);
        $existingProfile = Bitrix24Profile::query()
            ->where('portal_domain', $resolvedPortalDomain)
            ->where('profile_key', $profileKey)
            ->first();
        $callbackBaseUrlRotated = $existingProfile instanceof Bitrix24Profile
            && $existingProfile->callback_base_url !== $normalizedCallbackBaseUrl;

        $otherProfiles = Bitrix24Profile::query()
            ->when(
                $existingProfile instanceof Bitrix24Profile,
                fn ($query) => $query->whereKeyNot($existingProfile->getKey()),
            )
            ->get();

        $resolvedClientId = $this->resolvePersistedValue($clientId, $existingProfile?->client_id);
        $resolvedApplicationCode = $this->resolvePersistedValue($applicationCode, $existingProfile?->application_code);
        $resolvedDisplayName = $this->resolveDisplayName($displayName, $existingProfile?->display_name, $profileKey);

        $telegramSourceId = $this->buildSourceId($profileKey, 'TELEGRAM');
        $maxSourceId = $this->buildSourceId($profileKey, 'MAX');
        $telegramConnectorCode = $this->buildConnectorCode($profileKey, 'telegram');
        $maxConnectorCode = $this->buildConnectorCode($profileKey, 'max');

        $this->assertCallbackBaseUrlIsAvailable($otherProfiles, $normalizedCallbackBaseUrl);

        if ($clientId !== null) {
            $this->assertPortalFieldIsAvailable(
                $otherProfiles,
                $resolvedPortalDomain,
                'client_id',
                $resolvedClientId,
                'Bitrix app client_id',
            );
        }

        if ($applicationCode !== null) {
            $this->assertPortalFieldIsAvailable(
                $otherProfiles,
                $resolvedPortalDomain,
                'application_code',
                $resolvedApplicationCode,
                'Bitrix app application_code',
            );
        }

        $this->assertPortalFieldIsAvailable(
            $otherProfiles,
            $resolvedPortalDomain,
            'telegram_source_id',
            $telegramSourceId,
            'Telegram SOURCE_ID',
        );
        $this->assertPortalFieldIsAvailable(
            $otherProfiles,
            $resolvedPortalDomain,
            'max_source_id',
            $maxSourceId,
            'MAX SOURCE_ID',
        );
        $this->assertPortalFieldIsAvailable(
            $otherProfiles,
            $resolvedPortalDomain,
            'telegram_connector_code',
            $telegramConnectorCode,
            'Telegram connector_code',
        );
        $this->assertPortalFieldIsAvailable(
            $otherProfiles,
            $resolvedPortalDomain,
            'max_connector_code',
            $maxConnectorCode,
            'MAX connector_code',
        );

        $created = ! $existingProfile instanceof Bitrix24Profile;

        $profile = Bitrix24Profile::query()->updateOrCreate(
            [
                'portal_domain' => $resolvedPortalDomain,
                'profile_key' => $profileKey,
            ],
            [
                'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
                'display_name' => $resolvedDisplayName,
                'client_id' => $resolvedClientId,
                'application_code' => $resolvedApplicationCode,
                'callback_base_url' => $normalizedCallbackBaseUrl,
                'telegram_source_id' => $telegramSourceId,
                'max_source_id' => $maxSourceId,
                'telegram_connector_code' => $telegramConnectorCode,
                'max_connector_code' => $maxConnectorCode,
                'default_assigned_user_id' => (int) config('bitrix24.defaults.assigned_user_id', 1),
                'default_deal_category_id' => (int) config('bitrix24.defaults.deal_category_id', 22),
                'default_deal_stage_id' => (string) config('bitrix24.defaults.deal_stage_id', 'C22:NEW'),
            ],
        );

        $profile->refresh();

        return new Bitrix24DevProfileBootstrapResultData(
            profile: $profile,
            created: $created,
            checks: $this->buildChecks($profile, $callbackBaseUrlRotated),
            instructionSteps: $this->buildInstructionSteps($profile),
        );
    }

    private function normalizeProfileKey(string $profileKey): string
    {
        return Str::lower(trim($profileKey));
    }

    private function assertValidDevProfileKey(string $profileKey): void
    {
        if ($profileKey === Bitrix24Profile::PROFILE_KEY_STAGING) {
            throw new Bitrix24DevProfileBootstrapException('The dev bootstrap path cannot mutate the fixed `staging` profile.');
        }

        if (! preg_match('/^dev-[a-z0-9]+(?:-[a-z0-9]+)*$/', $profileKey)) {
            throw new Bitrix24DevProfileBootstrapException(
                'profile_key must use the canonical dev-* ASCII form, for example `dev-ivan-main`.',
            );
        }
    }

    private function resolvePortalDomain(mixed $portalDomain): string
    {
        $candidate = $portalDomain;

        if ($candidate === null) {
            $candidate = config('bitrix24.portal_domain');
        }

        if (! is_scalar($candidate)) {
            throw new Bitrix24DevProfileBootstrapException('portal_domain must be a non-empty bare domain.');
        }

        $resolved = trim(Str::lower((string) $candidate));

        if ($resolved === '') {
            throw new Bitrix24DevProfileBootstrapException('portal_domain must be a non-empty bare domain.');
        }

        if (str_contains($resolved, '://') || str_contains($resolved, '/')) {
            throw new Bitrix24DevProfileBootstrapException('portal_domain must be stored without scheme or path.');
        }

        return $resolved;
    }

    private function resolvePersistedValue(mixed $newValue, ?string $existingValue): ?string
    {
        if ($newValue === null) {
            return $existingValue;
        }

        if (! is_scalar($newValue)) {
            return null;
        }

        $trimmed = trim((string) $newValue);

        return $trimmed === '' ? null : $trimmed;
    }

    private function resolveDisplayName(mixed $displayName, ?string $existingDisplayName, string $profileKey): string
    {
        $resolved = $this->resolvePersistedValue($displayName, $existingDisplayName);

        if (filled($resolved)) {
            return (string) $resolved;
        }

        return Str::headline(str_replace('-', ' ', $profileKey));
    }

    private function buildSourceId(string $profileKey, string $platform): string
    {
        return sprintf(
            'ABC_%s_%s',
            $platform,
            Str::upper(str_replace('-', '_', $profileKey)),
        );
    }

    private function buildConnectorCode(string $profileKey, string $platform): string
    {
        return sprintf(
            'abc_%s_%s',
            $platform,
            str_replace('-', '_', $profileKey),
        );
    }

    private function assertCallbackBaseUrlIsAvailable(Collection $otherProfiles, string $callbackBaseUrl): void
    {
        $conflict = $otherProfiles->first(
            fn (Bitrix24Profile $profile): bool => $profile->callback_base_url === $callbackBaseUrl,
        );

        if (! $conflict instanceof Bitrix24Profile) {
            return;
        }

        throw new Bitrix24DevProfileBootstrapException(sprintf(
            'callback_base_url `%s` is already assigned to profile `%s` on portal `%s`.',
            $callbackBaseUrl,
            $conflict->profile_key,
            $conflict->portal_domain,
        ));
    }

    private function assertPortalFieldIsAvailable(
        Collection $otherProfiles,
        string $portalDomain,
        string $field,
        ?string $value,
        string $label,
    ): void {
        if (! filled($value)) {
            return;
        }

        $conflict = $otherProfiles
            ->where('portal_domain', $portalDomain)
            ->first(fn (Bitrix24Profile $profile): bool => $profile->{$field} === $value);

        if (! $conflict instanceof Bitrix24Profile) {
            return;
        }

        throw new Bitrix24DevProfileBootstrapException(sprintf(
            '%s `%s` is already assigned to profile `%s`.',
            $label,
            $value,
            $conflict->profile_key,
        ));
    }

    /**
     * @return list<array{label: string, required: bool, status: string, value: string, notes: string}>
     */
    private function buildChecks(Bitrix24Profile $profile, bool $callbackBaseUrlRotated = false): array
    {
        $portalProfiles = Bitrix24Profile::query()
            ->where('portal_domain', $profile->portal_domain)
            ->whereKeyNot($profile->getKey())
            ->get();
        $activeConnections = Bitrix24Connection::query()
            ->where('profile_id', $profile->getKey())
            ->where('status', Bitrix24Connection::STATUS_ACTIVE)
            ->get();
        $verifiedConnection = $activeConnections->count() === 1
            ? $activeConnections->first()
            : null;
        $telegramRouteLineIds = $this->activeRouteLineIds(
            $profile,
            Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
        );
        $maxRouteLineIds = $this->activeRouteLineIds(
            $profile,
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX,
        );

        return [
            $this->check(
                'Profile type',
                $profile->profile_type,
                Bitrix24DevProfileBootstrapResultData::STATUS_OK,
                true,
                'Dev bootstrap always keeps the profile on the full_live contract.',
            ),
            $this->requiredCheck(
                'Bitrix app client_id',
                $profile->client_id,
                'Set the app client_id after you create a dedicated Bitrix app for this profile.',
            ),
            $this->requiredCheck(
                'Bitrix app application_code',
                $profile->application_code,
                'Set the app application_code after you create a dedicated Bitrix app for this profile.',
            ),
            $this->callbackBaseUrlCheck($profile),
            $this->requiredCheck(
                'Telegram SOURCE_ID',
                $profile->telegram_source_id,
                'Bootstrap must persist a deterministic Telegram SOURCE_ID.',
            ),
            $this->requiredCheck(
                'MAX SOURCE_ID',
                $profile->max_source_id,
                'Bootstrap must persist a deterministic MAX SOURCE_ID.',
            ),
            $this->requiredCheck(
                'Telegram connector_code',
                $profile->telegram_connector_code,
                'Bootstrap must persist a deterministic Telegram connector_code.',
            ),
            $this->requiredCheck(
                'MAX connector_code',
                $profile->max_connector_code,
                'Bootstrap must persist a deterministic MAX connector_code.',
            ),
            $this->requiredRouteLineIdsCheck(
                'Active Telegram channel routes have LINE_ID',
                $telegramRouteLineIds,
                'Configure Telegram LINE_ID on concrete channel routes in the Bitrix24 admin page.',
            ),
            $this->requiredRouteLineIdsCheck(
                'Active MAX channel routes have LINE_ID',
                $maxRouteLineIds,
                'Configure MAX LINE_ID on concrete channel routes in the Bitrix24 admin page.',
            ),
            $this->uniquePortalValueCheck(
                $portalProfiles,
                'Bitrix app client_id is unique within portal',
                'client_id',
                $profile->client_id,
                'Bitrix app client_id must not be reused by another profile on the same portal.',
            ),
            $this->uniquePortalValueCheck(
                $portalProfiles,
                'Bitrix app application_code is unique within portal',
                'application_code',
                $profile->application_code,
                'Bitrix app application_code must not be reused by another profile on the same portal.',
            ),
            $this->uniquePortalValueCheck(
                $portalProfiles,
                'Telegram SOURCE_ID is unique within portal',
                'telegram_source_id',
                $profile->telegram_source_id,
                'Telegram SOURCE_ID must stay unique per profile on the same portal.',
            ),
            $this->uniquePortalValueCheck(
                $portalProfiles,
                'MAX SOURCE_ID is unique within portal',
                'max_source_id',
                $profile->max_source_id,
                'MAX SOURCE_ID must stay unique per profile on the same portal.',
            ),
            $this->uniquePortalValueCheck(
                $portalProfiles,
                'Telegram connector_code is unique within portal',
                'telegram_connector_code',
                $profile->telegram_connector_code,
                'Telegram connector_code must stay unique per profile on the same portal.',
            ),
            $this->uniquePortalValueCheck(
                $portalProfiles,
                'MAX connector_code is unique within portal',
                'max_connector_code',
                $profile->max_connector_code,
                'MAX connector_code must stay unique per profile on the same portal.',
            ),
            $this->activeInstallConnectionCheck($profile, $activeConnections),
            $this->installedConnectionClientIdCheck($profile, $verifiedConnection),
            $this->installCallbackIngressCheck($profile, $verifiedConnection, $callbackBaseUrlRotated),
            $this->bitrixAppProbeCheck($profile, $verifiedConnection),
            $this->bitrixRouteLineProbeCheck(
                'Telegram route LINE_ID values exist on Bitrix',
                $telegramRouteLineIds,
                $verifiedConnection,
                'Telegram route LINE_ID values can be verified only after one active install connection is attached to the profile.',
            ),
            $this->bitrixRouteLineProbeCheck(
                'MAX route LINE_ID values exist on Bitrix',
                $maxRouteLineIds,
                $verifiedConnection,
                'MAX route LINE_ID values can be verified only after one active install connection is attached to the profile.',
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function buildInstructionSteps(Bitrix24Profile $profile): array
    {
        return [
            sprintf(
                'Use one dedicated Bitrix app only for profile `%s`; do not reuse the app from `staging` or another dev profile.',
                $profile->profile_key,
            ),
            sprintf(
                'Set install/events/openlines callbacks in Bitrix to: %s | %s | %s.',
                $profile->installCallbackUrl(),
                $profile->eventsCallbackUrl(),
                $profile->openlinesCallbackUrl(),
            ),
            sprintf(
                'Create separate Open Lines for Telegram and MAX. Suggested names: `ABC Telegram %s` and `ABC MAX %s`.',
                $profile->profile_key,
                $profile->profile_key,
            ),
            sprintf(
                'Record the resulting LINE_ID values on concrete channel routes in the Bitrix24 admin page, then rerun this command.',
            ),
            sprintf(
                'Expected routing values are Telegram SOURCE_ID `%s`, MAX SOURCE_ID `%s`, Telegram connector_code `%s`, MAX connector_code `%s`.',
                $profile->telegram_source_id,
                $profile->max_source_id,
                $profile->telegram_connector_code,
                $profile->max_connector_code,
            ),
        ];
    }

    /**
     * @return array{label: string, required: bool, status: string, value: string, notes: string}
     */
    private function check(string $label, ?string $value, string $status, bool $required, string $notes): array
    {
        return [
            'label' => $label,
            'required' => $required,
            'status' => $status,
            'value' => is_string($value) ? $value : '',
            'notes' => $notes,
        ];
    }

    /**
     * @return array{label: string, required: bool, status: string, value: string, notes: string}
     */
    private function requiredCheck(string $label, ?string $value, string $missingNotes): array
    {
        if (! filled($value)) {
            return $this->check(
                $label,
                $value,
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                $missingNotes,
            );
        }

        return $this->check(
            $label,
            $value,
            Bitrix24DevProfileBootstrapResultData::STATUS_OK,
            true,
            'Value is filled.',
        );
    }

    /**
     * @return array{label: string, required: bool, status: string, value: string, notes: string}
     */
    private function installCallbackIngressCheck(
        Bitrix24Profile $profile,
        ?Bitrix24Connection $connection,
        bool $callbackBaseUrlRotated,
    ): array {
        $successfulStatuses = [
            Bitrix24WebhookEvent::STATUS_PENDING,
            Bitrix24WebhookEvent::STATUS_PROCESSED,
        ];
        $required = $connection instanceof Bitrix24Connection
            && filled($profile->client_id)
            && filled($profile->application_code);

        if (! $required) {
            return $this->check(
                'Install callback reached current callback_base_url',
                '',
                Bitrix24DevProfileBootstrapResultData::STATUS_WARNING,
                false,
                'The command verifies the current callback ingress after one active install connection is attached to the profile.',
            );
        }

        $installEvents = Bitrix24WebhookEvent::query()
            ->where('connection_id', $connection->getKey())
            ->where('callback_type', Bitrix24WebhookEvent::TYPE_INSTALL)
            ->where('callback_base_url', $profile->callback_base_url);

        $latestInstallEvent = (clone $installEvents)
            ->latest('id')
            ->first();
        $latestSuccessfulInstallEvent = (clone $installEvents)
            ->whereIn('processing_status', $successfulStatuses)
            ->latest('id')
            ->first();

        if (! $latestSuccessfulInstallEvent instanceof Bitrix24WebhookEvent) {
            $failedStatus = $latestInstallEvent instanceof Bitrix24WebhookEvent
                ? $latestInstallEvent->processing_status
                : null;

            return $this->check(
                'Install callback reached current callback_base_url',
                $profile->callback_base_url,
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                $failedStatus !== null
                    ? sprintf(
                        'Only install callbacks with status `%s` have been recorded for the current callback_base_url. Complete a valid Bitrix reinstall so the callback is stored as pending/processed on this ingress, then rerun the command.',
                        $failedStatus,
                    )
                    : 'No install callback has been recorded for the current callback_base_url yet. Re-save the Bitrix app install callback so it reaches this ingress, then rerun the command.',
            );
        }

        if (
            $callbackBaseUrlRotated
            && $profile->updated_at !== null
            && $latestSuccessfulInstallEvent->created_at !== null
            && $latestSuccessfulInstallEvent->created_at->lt($profile->updated_at)
        ) {
            return $this->check(
                'Install callback reached current callback_base_url',
                $profile->callback_base_url,
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                'A stale install callback exists for this callback_base_url, but it predates the current tunnel rotation. Trigger a fresh install callback on the new ingress, then rerun the command.',
            );
        }

        return $this->check(
            'Install callback reached current callback_base_url',
            $profile->callback_base_url,
            Bitrix24DevProfileBootstrapResultData::STATUS_OK,
            true,
            $callbackBaseUrlRotated
                ? 'A fresh install callback has been recorded on the rotated callback_base_url.'
                : 'An install callback is already recorded on the current callback_base_url.',
        );
    }

    /**
     * @return array{label: string, required: bool, status: string, value: string, notes: string}
     */
    private function callbackBaseUrlCheck(Bitrix24Profile $profile): array
    {
        $normalized = $this->normalizeCallbackBaseUrl->handle($profile->callback_base_url);

        if ($normalized === null) {
            return $this->check(
                'Dev callback_base_url',
                $profile->callback_base_url,
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                'callback_base_url must stay a valid absolute URL.',
            );
        }

        if (! str_starts_with($profile->profile_key, 'dev-')) {
            return $this->check(
                'Dev callback_base_url',
                $normalized,
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                'Only dev-* profiles may use the dev bootstrap callback path.',
            );
        }

        $notes = str_contains($normalized, 'trycloudflare.com')
            ? 'Tunnel callback_base_url is allowed for dev-* profiles and can rotate between sessions.'
            : 'Stable callback_base_url is valid for the dev profile.';

        return $this->check(
            'Dev callback_base_url',
            $normalized,
            Bitrix24DevProfileBootstrapResultData::STATUS_OK,
            true,
            $notes,
        );
    }

    /**
     * @return list<string>
     */
    private function activeRouteLineIds(Bitrix24Profile $profile, string $channelType): array
    {
        return Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $profile->getKey())
            ->where('channel_type', $channelType)
            ->where('status', Bitrix24OpenLineRoute::STATUS_ACTIVE)
            ->whereNotNull('line_id')
            ->pluck('line_id')
            ->map(fn (mixed $lineId): string => trim((string) $lineId))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $lineIds
     * @return array{label: string, required: bool, status: string, value: string, notes: string}
     */
    private function requiredRouteLineIdsCheck(string $label, array $lineIds, string $missingNotes): array
    {
        if ($lineIds === []) {
            return $this->check(
                $label,
                '',
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                $missingNotes,
            );
        }

        return $this->check(
            $label,
            implode(', ', $lineIds),
            Bitrix24DevProfileBootstrapResultData::STATUS_OK,
            true,
            'LINE_ID values are configured on active channel routes.',
        );
    }

    /**
     * @param  Collection<int, Bitrix24Connection>  $activeConnections
     * @return array{label: string, required: bool, status: string, value: string, notes: string}
     */
    private function activeInstallConnectionCheck(Bitrix24Profile $profile, Collection $activeConnections): array
    {
        $required = filled($profile->client_id) && filled($profile->application_code);
        $count = $activeConnections->count();

        if (! $required) {
            return $this->check(
                'Active Bitrix install connection exists for profile',
                '',
                Bitrix24DevProfileBootstrapResultData::STATUS_WARNING,
                false,
                'Bitrix-side verification starts after client_id and application_code are filled and the install callback reaches this profile.',
            );
        }

        if ($count === 0) {
            return $this->check(
                'Active Bitrix install connection exists for profile',
                '',
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                'No active install connection exists yet. Finish Bitrix app installation so the install callback can attach one active connection to this profile.',
            );
        }

        if ($count > 1) {
            return $this->check(
                'Active Bitrix install connection exists for profile',
                (string) $count,
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                'Exactly one active install connection must remain attached to this profile before the bootstrap command can certify it as ready.',
            );
        }

        $connection = $activeConnections->first();

        return $this->check(
            'Active Bitrix install connection exists for profile',
            $connection instanceof Bitrix24Connection ? (string) ($connection->member_id ?? 'active') : 'active',
            Bitrix24DevProfileBootstrapResultData::STATUS_OK,
            true,
            'Exactly one active install connection is attached to this profile.',
        );
    }

    /**
     * @return array{label: string, required: bool, status: string, value: string, notes: string}
     */
    private function installedConnectionClientIdCheck(Bitrix24Profile $profile, ?Bitrix24Connection $connection): array
    {
        $required = $connection instanceof Bitrix24Connection && filled($profile->client_id);

        if (! $required) {
            return $this->check(
                'Installed connection client_id matches profile',
                '',
                Bitrix24DevProfileBootstrapResultData::STATUS_WARNING,
                false,
                'The command verifies client_id after one active install connection is attached to the profile.',
            );
        }

        if ($this->nullableString($connection->client_id) !== $this->nullableString($profile->client_id)) {
            return $this->check(
                'Installed connection client_id matches profile',
                (string) ($connection->client_id ?? ''),
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                'The active install connection carries a different client_id than the profile. Reinstall the dedicated Bitrix app or correct the stored client_id.',
            );
        }

        return $this->check(
            'Installed connection client_id matches profile',
            (string) $profile->client_id,
            Bitrix24DevProfileBootstrapResultData::STATUS_OK,
            true,
            'The active install connection carries the same client_id as the profile.',
        );
    }

    /**
     * @return array{label: string, required: bool, status: string, value: string, notes: string}
     */
    private function bitrixAppProbeCheck(Bitrix24Profile $profile, ?Bitrix24Connection $connection): array
    {
        $required = $connection instanceof Bitrix24Connection && filled($profile->application_code);

        if (! $required) {
            return $this->check(
                'Bitrix app probe confirms application_code',
                '',
                Bitrix24DevProfileBootstrapResultData::STATUS_WARNING,
                false,
                'The command probes app.info after one active install connection is attached to the profile.',
            );
        }

        try {
            $response = $this->bitrix24ApiClient->call('app.info', [], $connection);
        } catch (Throwable $exception) {
            return $this->check(
                'Bitrix app probe confirms application_code',
                '',
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                'The app.info probe failed: '.$exception->getMessage(),
            );
        }

        if (! $response->successful) {
            return $this->check(
                'Bitrix app probe confirms application_code',
                '',
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                'The app.info probe did not confirm the installed Bitrix application context for this profile.',
            );
        }

        $result = $response->result;

        if (! is_array($result)) {
            return $this->check(
                'Bitrix app probe confirms application_code',
                '',
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                'The app.info probe returned an invalid payload.',
            );
        }

        $returnedApplicationCode = $this->nullableString($result['CODE'] ?? null);

        if ($returnedApplicationCode === null) {
            return $this->check(
                'Bitrix app probe confirms application_code',
                '',
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                'The app.info probe did not return application CODE.',
            );
        }

        if ($returnedApplicationCode !== $this->nullableString($profile->application_code)) {
            return $this->check(
                'Bitrix app probe confirms application_code',
                $returnedApplicationCode,
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                'The app.info probe returned a different application CODE than the profile expects.',
            );
        }

        if (! $this->isInstalled($result['INSTALLED'] ?? null)) {
            return $this->check(
                'Bitrix app probe confirms application_code',
                $returnedApplicationCode,
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                'The app.info probe reported the application as not installed.',
            );
        }

        return $this->check(
            'Bitrix app probe confirms application_code',
            $returnedApplicationCode,
            Bitrix24DevProfileBootstrapResultData::STATUS_OK,
            true,
            'The app.info probe confirms the installed application context for this profile.',
        );
    }

    /**
     * @param  list<string>  $lineIds
     * @return array{label: string, required: bool, status: string, value: string, notes: string}
     */
    private function bitrixRouteLineProbeCheck(
        string $label,
        array $lineIds,
        ?Bitrix24Connection $connection,
        string $unverifiedNotes,
    ): array {
        $required = $connection instanceof Bitrix24Connection && $lineIds !== [];

        if (! $required) {
            return $this->check(
                $label,
                implode(', ', $lineIds),
                Bitrix24DevProfileBootstrapResultData::STATUS_WARNING,
                false,
                $unverifiedNotes,
            );
        }

        foreach ($lineIds as $lineId) {
            try {
                $response = $this->bitrix24ApiClient->call('imopenlines.config.get', [
                    'CONFIG_ID' => $lineId,
                ], $connection);
            } catch (Throwable $exception) {
                return $this->check(
                    $label,
                    $lineId,
                    Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                    true,
                    'The Open Lines probe failed: '.$exception->getMessage(),
                );
            }

            if (! $response->successful || ! $this->lineProbeMatches($response->result, $lineId)) {
                return $this->check(
                    $label,
                    $lineId,
                    Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                    true,
                    'Bitrix did not confirm this LINE_ID through imopenlines.config.get.',
                );
            }
        }

        return $this->check(
            $label,
            implode(', ', $lineIds),
            Bitrix24DevProfileBootstrapResultData::STATUS_OK,
            true,
            'Bitrix confirmed all Open Lines configurations through imopenlines.config.get.',
        );
    }

    /**
     * @return array{label: string, required: bool, status: string, value: string, notes: string}
     */
    private function uniquePortalValueCheck(
        Collection $portalProfiles,
        string $label,
        string $field,
        ?string $value,
        string $duplicateNotes,
    ): array {
        if (! filled($value)) {
            return $this->check(
                $label,
                $value,
                Bitrix24DevProfileBootstrapResultData::STATUS_WARNING,
                false,
                'Uniqueness check is skipped until the value is filled.',
            );
        }

        $conflict = $portalProfiles->first(
            fn (Bitrix24Profile $profile): bool => $profile->{$field} === $value,
        );

        if ($conflict instanceof Bitrix24Profile) {
            return $this->check(
                $label,
                $value,
                Bitrix24DevProfileBootstrapResultData::STATUS_MISSING,
                true,
                $duplicateNotes.' Conflicts with profile `'.$conflict->profile_key.'`.',
            );
        }

        return $this->check(
            $label,
            $value,
            Bitrix24DevProfileBootstrapResultData::STATUS_OK,
            true,
            'No collisions detected inside the portal profile registry.',
        );
    }

    private function lineProbeMatches(mixed $result, ?string $lineId): bool
    {
        if ($lineId === null || $result === null) {
            return false;
        }

        if (is_scalar($result)) {
            return $this->nullableString($result) === $lineId;
        }

        if (! is_array($result)) {
            return false;
        }

        $returnedId = $this->nullableString($result['ID'] ?? $result['CONFIG_ID'] ?? null);

        if ($returnedId !== null) {
            return $returnedId === $lineId;
        }

        return false;
    }

    private function isInstalled(mixed $value): bool
    {
        return match (true) {
            $value === true => true,
            is_int($value) => $value === 1,
            is_string($value) => in_array(mb_strtolower(trim($value)), ['1', 'true', 'y', 'yes'], true),
            default => false,
        };
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
