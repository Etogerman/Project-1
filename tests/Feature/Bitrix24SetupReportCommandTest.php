<?php

namespace Tests\Feature;

use App\Models\Bitrix24Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Bitrix24SetupReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fails_when_required_setup_values_are_missing(): void
    {
        config()->set('bitrix24', array_replace_recursive(config('bitrix24'), [
            'application' => [
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
        $this->createProfile();

        $this->artisan('bitrix24:setup-report')
            ->expectsOutput('Bitrix24 setup readiness check completed.')
            ->expectsOutputToContain('Bitrix24 profile registry')
            ->expectsOutputToContain('Profile `staging` callback_base_url')
            ->doesntExpectOutputToContain('client-secret')
            ->expectsOutputToContain('*** redacted ***')
            ->expectsOutputToContain('session_finish_event_names')
            ->expectsOutputToContain('["OnSessionFinish"]')
            ->expectsOutputToContain('Bitrix24 setup is ready for the integration foundation stage.')
            ->assertSuccessful();
    }

    private function seedReadyConfig(): void
    {
        config()->set('bitrix24', array_replace_recursive(config('bitrix24'), [
            'application' => [
                'client_secret' => 'client-secret',
            ],
            'oauth' => [
                'server_url' => 'https://oauth.example',
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

    private function createProfile(string $callbackBaseUrl = 'https://project.example.com'): void
    {
        Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => $callbackBaseUrl,
        ]);
    }
}
