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

    public function test_command_succeeds_when_route_line_ids_are_stored_on_channel_routes(): void
    {
        $this->seedReadyConfig();
        $profile = $this->createProfile();
        $this->createActiveConnection($profile);

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Unique usable Open Lines route LINE_ID values per portal')
            ->expectsOutputToContain('Current runtime active Telegram route LINE_ID values')
            ->expectsOutputToContain('Current runtime active MAX route LINE_ID values')
            ->assertSuccessful();
    }

    public function test_setup_report_reads_crm_schema_from_profile_settings(): void
    {
        $this->seedReadyConfig();
        $profile = $this->createProfile([
            'crm_field_gender' => 'UF_CRM_PROFILE_GENDER',
            'crm_gender_male_id' => 9001,
        ]);
        $this->createActiveConnection($profile);

        $report = app(BuildBitrix24SetupReportAction::class)->handle();
        $checks = collect($report->checks);
        $frozenValues = collect($report->frozenValues);

        $this->assertFalse($report->hasBlockingIssues(), json_encode($report->blockingChecks()));
        $this->assertSame(
            'UF_CRM_PROFILE_GENDER',
            $checks->firstWhere('key', 'profiles.staging.crm_schema.fields.gender')['value'] ?? null,
        );
        $this->assertSame(
            '9001',
            $checks->firstWhere('key', 'profiles.staging.crm_schema.values.gender.male_id')['value'] ?? null,
        );
        $this->assertSame(
            'UF_CRM_PROFILE_GENDER',
            $frozenValues
                ->where('group', 'profile_crm_fields')
                ->firstWhere('label', 'staging.gender')['value'] ?? null,
        );
        $this->assertSame(
            '9001',
            $frozenValues
                ->where('group', 'profile_crm_values')
                ->firstWhere('label', 'staging.gender.male_id')['value'] ?? null,
        );
    }

    public function test_command_accepts_active_telegram_route_instead_of_profile_telegram_line_id(): void
    {
        $this->seedReadyConfig();
        $profile = $this->createProfile([
            'create_open_line_routes' => false,
        ]);
        $this->createActiveConnection($profile);
        $this->createOpenLineRoute($profile, Channel::PLATFORM_TELEGRAM, 'line-from-route');
        $this->createOpenLineRoute($profile, Channel::PLATFORM_MAX, 'line-max');

        $report = app(BuildBitrix24SetupReportAction::class)->handle();

        $this->assertFalse($report->hasBlockingIssues(), json_encode($report->blockingChecks()));
        $this->assertSame(
            'line-from-route',
            collect($report->checks)->firstWhere('key', 'runtime.openline_routes.telegram_line_ids')['value'] ?? null,
        );
    }

    public function test_command_accepts_active_max_route_instead_of_profile_max_line_id(): void
    {
        $this->seedReadyConfig();
        $profile = $this->createProfile([
            'create_open_line_routes' => false,
        ]);
        $this->createActiveConnection($profile);
        $this->createOpenLineRoute($profile, Channel::PLATFORM_TELEGRAM, 'line-telegram');
        $this->createOpenLineRoute($profile, Channel::PLATFORM_MAX, 'line-max-from-route');

        $report = app(BuildBitrix24SetupReportAction::class)->handle();

        $this->assertFalse($report->hasBlockingIssues(), json_encode($report->blockingChecks()));
        $this->assertSame(
            'line-max-from-route',
            collect($report->checks)->firstWhere('key', 'runtime.openline_routes.max_line_ids')['value'] ?? null,
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
                'telegram_id' => 'ABC_TELEGRAM',
                'max_id' => 'ABC_MAX',
            ],
            'openlines' => [
                'telegram_connector_code' => 'abc_telegram',
                'max_connector_code' => 'abc_max',
                'session_finish_event_names' => ['OnSessionFinish'],
            ],
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProfile(array $overrides = []): Bitrix24Profile
    {
        $createOpenLineRoutes = (bool) ($overrides['create_open_line_routes'] ?? true);
        unset($overrides['create_open_line_routes']);

        $profile = Bitrix24Profile::query()->create(array_replace([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'https://project.example.com',
            'telegram_source_id' => 'ABC_TELEGRAM',
            'max_source_id' => 'ABC_MAX',
            'telegram_connector_code' => 'abc_telegram',
            'max_connector_code' => 'abc_max',
        ], $overrides));

        if ($createOpenLineRoutes && $profile->profile_type === Bitrix24Profile::TYPE_FULL_LIVE) {
            $this->createOpenLineRoute($profile, Channel::PLATFORM_TELEGRAM, 'line-telegram');
            $this->createOpenLineRoute($profile, Channel::PLATFORM_MAX, 'line-max');
        }

        return $profile;
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

    private function createOpenLineRoute(Bitrix24Profile $profile, string $platform, string $lineId): Bitrix24OpenLineRoute
    {
        $channel = Channel::factory()->create([
            'platform' => $platform,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        return Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => $platform === Channel::PLATFORM_MAX
                ? $profile->max_connector_code
                : $profile->telegram_connector_code,
            'line_id' => $lineId,
            'source_id' => $platform === Channel::PLATFORM_MAX
                ? $profile->max_source_id
                : $profile->telegram_source_id,
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
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
            'telegram_source_id' => 'ABC_TELEGRAM',
            'max_source_id' => 'ABC_MAX',
            'telegram_connector_code' => 'abc_telegram',
            'max_connector_code' => 'abc_max',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
