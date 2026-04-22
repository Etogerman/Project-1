<?php

namespace Tests\Feature\Concerns;

use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;

trait InteractsWithBitrix24RuntimeProfile
{
    /**
     * @param  array<string, mixed>  $connectionOverrides
     * @param  array<string, mixed>  $profileOverrides
     */
    protected function makeProfileLinkedActiveBitrix24Connection(
        array $connectionOverrides = [],
        array $profileOverrides = [],
        bool $useForCurrentRuntime = true,
    ): Bitrix24Connection {
        $portalDomain = (string) ($profileOverrides['portal_domain'] ?? $connectionOverrides['portal_domain'] ?? 'crm.alexlesley.biz');
        $profileKey = (string) ($profileOverrides['profile_key'] ?? Bitrix24Profile::PROFILE_KEY_STAGING);
        $callbackBaseUrl = (string) ($profileOverrides['callback_base_url'] ?? 'https://project.example.com');
        $clientId = (string) ($profileOverrides['client_id'] ?? $connectionOverrides['client_id'] ?? 'local.app');

        $profile = Bitrix24Profile::query()->updateOrCreate(
            [
                'portal_domain' => $portalDomain,
                'profile_key' => $profileKey,
            ],
            [
                'profile_type' => $profileOverrides['profile_type'] ?? Bitrix24Profile::TYPE_FULL_LIVE,
                'display_name' => $profileOverrides['display_name'] ?? ucfirst(str_replace('-', ' ', $profileKey)),
                'client_id' => $clientId,
                'application_code' => $profileOverrides['application_code'] ?? 'local.app.code'.($profileKey === Bitrix24Profile::PROFILE_KEY_STAGING ? '' : '.'.$profileKey),
                'callback_base_url' => $callbackBaseUrl,
            ],
        );

        if ($useForCurrentRuntime) {
            $this->configureCurrentBitrix24RuntimeProfile($profile);
        }

        /** @var array<string, mixed> $attributes */
        $attributes = array_merge([
            'profile_id' => $profile->id,
            'portal_domain' => $portalDomain,
            'application_name' => 'Abrikosoff Connector',
            'client_id' => $clientId,
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_encrypted' => 'access-token',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'scope' => ['crm'],
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'install_payload' => [],
            'installed_at' => now()->subHour(),
            'last_install_callback_at' => now()->subHour(),
        ], $connectionOverrides);

        return Bitrix24Connection::query()->forceCreate($attributes);
    }

    protected function configureCurrentBitrix24RuntimeProfile(Bitrix24Profile|string $profile): void
    {
        $baseUrl = $profile instanceof Bitrix24Profile
            ? $profile->callback_base_url
            : (string) $profile;

        $baseUrl = rtrim($baseUrl, '/');

        config()->set('bitrix24.callbacks.install_url', $baseUrl.Bitrix24Profile::INSTALL_CALLBACK_PATH);
        config()->set('bitrix24.callbacks.events_url', $baseUrl.Bitrix24Profile::EVENTS_CALLBACK_PATH);
        config()->set('bitrix24.callbacks.openlines_url', $baseUrl.Bitrix24Profile::OPENLINES_CALLBACK_PATH);
    }
}
