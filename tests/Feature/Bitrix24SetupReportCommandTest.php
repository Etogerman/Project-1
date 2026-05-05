<?php

namespace Tests\Feature;

use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use App\Services\Bitrix24\Bitrix24ConnectionStateException;
use App\Services\Bitrix24\BuildBitrix24SetupReportAction;
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
        $this->seedReadyConfig('https://ruby-feat-food-medication.trycloudflare.com');
        $profile = $this->createProfile([
            'callback_base_url' => 'https://ruby-feat-food-medication.trycloudflare.com',
        ]);
        $this->createActiveConnection($profile);

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
            ->expectsOutputToContain('Current runtime Telegram SOURCE_ID')
            ->expectsOutputToContain('Profile `staging` callback_base_url')
            ->doesntExpectOutputToContain('client-secret')
            ->expectsOutputToContain('*** redacted ***')
            ->expectsOutputToContain('session_finish_event_names')
            ->expectsOutputToContain('Bitrix24 setup is ready for the integration foundation stage.')
            ->assertSuccessful();
    }

    public function test_command_succeeds_when_single_profile_reuses_line_id_across_connectors(): void
    {
        $this->seedReadyConfig();
        $profile = $this->createProfile([
            'telegram_line_id' => 'shared-line',
            'max_line_id' => 'shared-line',
        ]);
        $this->createActiveConnection($profile);

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Unique full_live LINE_ID values per portal')
            ->doesntExpectOutputToContain('Two full_live profiles cannot share the same LINE_ID within one portal.')
            ->assertSuccessful();
    }

    public function test_command_accepts_active_telegram_route_instead_of_profile_telegram_line_id(): void
    {
        $this->seedReadyConfig();
        $profile = $this->createProfile([
            'telegram_line_id' => null,
        ]);
        $this->createActiveConnection($profile);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => $profile->telegram_connector_code,
            'line_id' => 'line-from-route',
            'source_id' => $profile->telegram_source_id,
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $report = app(BuildBitrix24SetupReportAction::class)->handle();

        $this->assertFalse($report->hasBlockingIssues(), json_encode($report->blockingChecks()));
        $this->assertSame(
            'line-from-route',
            collect($report->checks)->firstWhere('key', 'runtime.current_profile.telegram_line_id')['value'] ?? null,
        );
    }

    public function test_command_accepts_active_max_route_instead_of_profile_max_line_id(): void
    {
        $this->seedReadyConfig();
        $profile = $this->createProfile([
            'max_line_id' => null,
        ]);
        $this->createActiveConnection($profile);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => $profile->max_connector_code,
            'line_id' => 'line-max-from-route',
            'source_id' => $profile->max_source_id,
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $report = app(BuildBitrix24SetupReportAction::class)->handle();

        $this->assertFalse($report->hasBlockingIssues(), json_encode($report->blockingChecks()));
        $this->assertSame(
            'line-max-from-route',
            collect($report->checks)->firstWhere('key', 'runtime.current_profile.max_line_id')['value'] ?? null,
        );
    }

    public function test_command_fails_when_current_runtime_callbacks_do_not_resolve_to_single_profile(): void
    {
        $this->seedReadyConfig();
        $this->createProfile();
        config()->set('bitrix24.callbacks.events_url', 'https://other.example.com/callbacks/bitrix24/events');

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Current runtime Bitrix24 profile')
            ->assertFailed();
    }

    public function test_command_fails_when_current_runtime_profile_does_not_allow_openlines_runtime(): void
    {
        $this->seedReadyConfig();
        $this->createProfile([
            'profile_type' => Bitrix24Profile::TYPE_CRM_ONLY,
            'telegram_connector_code' => null,
            'max_connector_code' => null,
            'telegram_line_id' => null,
            'max_line_id' => null,
        ]);

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Current runtime Bitrix24 profile')
            ->assertFailed();
    }

    public function test_command_fails_when_current_runtime_profile_has_no_active_connection(): void
    {
        $this->seedReadyConfig();
        $this->createProfile();

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Current runtime Bitrix24 connection')
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
            ->assertFailed();
    }

    public function test_command_fails_when_profile_callback_base_url_is_not_stored_in_canonical_form(): void
    {
        $this->seedReadyConfig('https://project.example.com/prefix');
        $this->insertRawProfile('HTTPS://Project.Example.com/prefix/');

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Profile `staging` callback_base_url')
            ->assertFailed();
    }

    public function test_command_fails_when_current_runtime_profile_routing_is_missing(): void
    {
        $this->seedReadyConfig();
        $profile = $this->createProfile([
            'telegram_connector_code' => null,
        ]);
        $this->createActiveConnection($profile);

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Current runtime Telegram connector_code')
            ->expectsOutputToContain('Telegram connector_code')
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
            ->assertFailed();
    }

    private function seedReadyConfig(string $callbackBaseUrl = 'https://project.example.com'): void
    {
        Bitrix24OpenLineRoute::query()->delete();
        Bitrix24Connection::query()->delete();
        Bitrix24Profile::query()->delete();

        $callbackBaseUrl = rtrim($callbackBaseUrl, '/');

        config()->set('bitrix24', array_replace_recursive(config('bitrix24'), [
            'application' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
            ],
            'oauth' => [
                'server_url' => 'https://oauth.example',
            ],
            'callbacks' => [
                'install_url' => $callbackBaseUrl.'/callbacks/bitrix24/install',
                'events_url' => $callbackBaseUrl.'/callbacks/bitrix24/events',
                'openlines_url' => $callbackBaseUrl.'/callbacks/bitrix24/openlines',
            ],
            'sources' => [
                'telegram_id' => 'ABRIKOSOFF_TELEGRAM',
                'max_id' => 'ABRIKOSOFF_MAX',
            ],
            'openlines' => [
                'telegram_line_id' => 'line-telegram',
                'max_line_id' => 'line-max',
                'telegram_connector_code' => 'abrikosoff_telegram',
                'max_connector_code' => 'abrikosoff_max',
                'session_finish_event_names' => ['OnSessionFinish'],
            ],
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProfile(array $overrides = []): Bitrix24Profile
    {
        return Bitrix24Profile::query()->create(array_replace([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'https://project.example.com',
            'telegram_source_id' => 'ABRIKOSOFF_TELEGRAM',
            'max_source_id' => 'ABRIKOSOFF_MAX',
            'telegram_connector_code' => 'abrikosoff_telegram',
            'max_connector_code' => 'abrikosoff_max',
            'telegram_line_id' => 'line-telegram',
            'max_line_id' => 'line-max',
        ], $overrides));
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
            'telegram_source_id' => 'ABRIKOSOFF_TELEGRAM',
            'max_source_id' => 'ABRIKOSOFF_MAX',
            'telegram_connector_code' => 'abrikosoff_telegram',
            'max_connector_code' => 'abrikosoff_max',
            'telegram_line_id' => 'line-telegram',
            'max_line_id' => 'line-max',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
