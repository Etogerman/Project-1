<?php

namespace Tests\Feature;

use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;
use App\Services\Bitrix24\Bitrix24ConnectionStateException;
use App\Services\Bitrix24\ResolveCurrentBitrix24ConnectionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class Bitrix24SetupReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fails_when_required_setup_values_are_missing(): void
    {
        config()->set('bitrix24', array_replace_recursive(config('bitrix24'), [
            'application' => [
                'client_id' => null,
                'client_secret' => null,
            ],
            'oauth' => [
                'server_url' => null,
            ],
            'sources' => [
                'telegram_id' => null,
                'max_id' => null,
            ],
            'openlines' => [
                'telegram_line_id' => null,
                'max_line_id' => null,
                'telegram_connector_code' => null,
                'max_connector_code' => null,
            ],
        ]));

        $this->artisan('bitrix24:setup-report')
            ->expectsOutput('Bitrix24 setup readiness check completed.')
            ->expectsOutputToContain('Bitrix24 profile registry')
            ->expectsOutputToContain('Bitrix24 OAuth server URL')
            ->expectsOutputToContain('Bitrix24 client_id')
            ->expectsOutputToContain('Bitrix24 client_secret')
            ->expectsOutputToContain('Bitrix24 setup is not ready for implementation. Resolve all missing required items first.')
            ->assertFailed();
    }

    public function test_command_fails_when_staging_profile_uses_tunnel_callback_base_url(): void
    {
        $this->seedReadyConfig();
        $this->createProfile(callbackBaseUrl: 'https://ruby-feat-food-medication.trycloudflare.com');

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Tunnel callback_base_url values are allowed only for dev-* profiles.')
            ->assertFailed();
    }

    public function test_command_succeeds_when_setup_contract_is_fully_frozen(): void
    {
        $this->seedReadyConfig();
        $profile = $this->createProfile();
        $this->createActiveConnection($profile);

        $this->artisan('bitrix24:setup-report')
            ->expectsOutput('Bitrix24 setup readiness check completed.')
            ->expectsOutputToContain('Bitrix24 profile registry')
            ->expectsOutputToContain('Current runtime Bitrix24 profile')
            ->expectsOutputToContain('Current runtime Bitrix24 connection')
            ->expectsOutputToContain('Profile `staging` callback_base_url')
            ->doesntExpectOutputToContain('client-secret')
            ->expectsOutputToContain('*** redacted ***')
            ->expectsOutputToContain('session_finish_event_names')
            ->expectsOutputToContain('["OnSessionFinish"]')
            ->expectsOutputToContain('Bitrix24 setup is ready for the integration foundation stage.')
            ->assertSuccessful();
    }

    public function test_command_fails_when_current_runtime_callbacks_do_not_resolve_to_single_profile(): void
    {
        $this->seedReadyConfig();
        $this->createProfile(callbackBaseUrl: 'https://project.example.com');
        config()->set('bitrix24.callbacks.events_url', 'https://other.example.com/callbacks/bitrix24/events');

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Current runtime Bitrix24 profile')
            ->expectsOutputToContain('Configured Bitrix24 callbacks resolve to different callback_base_url values')
            ->assertFailed();
    }

    public function test_command_fails_when_current_runtime_profile_does_not_allow_openlines_runtime(): void
    {
        $this->seedReadyConfig();
        $this->createProfile(profileType: Bitrix24Profile::TYPE_CRM_ONLY);

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Current runtime Bitrix24 profile')
            ->expectsOutputToContain('does not allow openlines runtime')
            ->assertFailed();
    }

    public function test_command_fails_when_current_runtime_profile_has_no_active_connection(): void
    {
        $this->seedReadyConfig();
        $this->createProfile();

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Current runtime Bitrix24 connection')
            ->expectsOutputToContain('No active Bitrix24 connection is configured for current runtime profile')
            ->assertFailed();
    }

    public function test_command_fails_when_current_runtime_profile_resolves_to_multiple_active_connections(): void
    {
        $this->seedReadyConfig();
        $profile = $this->createProfile();
        $this->createActiveConnection($profile);

        $this->mock(ResolveCurrentBitrix24ConnectionAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')
                ->once()
                ->andThrow(new Bitrix24ConnectionStateException(
                    'Multiple active Bitrix24 connections are configured for current runtime profile `staging`.',
                ));
        });

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Current runtime Bitrix24 connection')
            ->expectsOutputToContain('Multiple active Bitrix24 connections are configured for current runtime profile `staging`.')
            ->assertFailed();
    }

    public function test_command_fails_when_profile_callback_base_url_is_not_stored_in_canonical_form(): void
    {
        $this->seedReadyConfig();
        $this->insertRawProfile('HTTPS://Project.Example.com/prefix/');
        config()->set('bitrix24.callbacks.install_url', 'https://project.example.com/prefix/callbacks/bitrix24/install');
        config()->set('bitrix24.callbacks.events_url', 'https://project.example.com/prefix/callbacks/bitrix24/events');
        config()->set('bitrix24.callbacks.openlines_url', 'https://project.example.com/prefix/callbacks/bitrix24/openlines');

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Profile `staging` callback_base_url')
            ->expectsOutputToContain('Stored callback_base_url must already be normalized')
            ->assertFailed();
    }

    public function test_command_fails_when_frozen_required_value_drifts(): void
    {
        $this->seedReadyConfig();
        $profile = $this->createProfile();
        $this->createActiveConnection($profile);

        config()->set('bitrix24.defaults.deal_category_id', '99');

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Default deal category ID')
            ->expectsOutputToContain('Expected 22.')
            ->assertFailed();
    }

    private function seedReadyConfig(): void
    {
        config()->set('bitrix24', array_replace_recursive(config('bitrix24'), [
            'application' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
            ],
            'oauth' => [
                'server_url' => 'https://oauth.example',
            ],
            'callbacks' => [
                'install_url' => 'https://project.example.com/callbacks/bitrix24/install',
                'events_url' => 'https://project.example.com/callbacks/bitrix24/events',
                'openlines_url' => 'https://project.example.com/callbacks/bitrix24/openlines',
            ],
            'sources' => [
                'telegram_id' => 'ABRIKOSOFF_TG',
                'max_id' => 'ABRIKOSOFF_MAX',
            ],
            'openlines' => [
                'telegram_line_id' => '101',
                'max_line_id' => '102',
                'telegram_connector_code' => 'abrikosoff_telegram',
                'max_connector_code' => 'abrikosoff_max',
            ],
        ]));
    }

    private function createProfile(
        string $callbackBaseUrl = 'https://project.example.com',
        string $profileType = Bitrix24Profile::TYPE_FULL_LIVE,
    ): Bitrix24Profile
    {
        return Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => $profileType,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => $callbackBaseUrl,
        ]);
    }

    private function createActiveConnection(Bitrix24Profile $profile): Bitrix24Connection
    {
        return Bitrix24Connection::query()->forceCreate([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);
    }

    private function insertRawProfile(string $callbackBaseUrl): void
    {
        DB::table('bitrix24_profiles')->insert([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => $callbackBaseUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
