<?php

namespace Tests\Feature;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24WebhookEvent;
use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Bitrix24DevProfileBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.portal_domain', 'crm.alexlesley.biz');
        config()->set('bitrix24.http.retry_sleep_milliseconds', 0);
    }

    public function test_command_creates_new_dev_profile_with_deterministic_routing_and_callbacks_but_keeps_it_unready_until_install_verification(): void
    {
        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://spark-rocket.trycloudflare.com',
            '--client-id' => 'client-id-ivan',
            '--application-code' => 'local.app.dev.ivan',
        ])
            ->expectsOutput('Bitrix24 dev-profile bootstrap completed.')
            ->expectsOutputToContain('Profile action: created.')
            ->expectsOutputToContain('ABC_TELEGRAM_DEV_IVAN_MAIN')
            ->expectsOutputToContain('abc_telegram_dev_ivan_main')
            ->expectsOutputToContain('https://spark-rocket.trycloudflare.com/callbacks/bitrix24/install')
            ->expectsOutputToContain('Active Telegram channel routes have LINE_ID')
            ->expectsOutputToContain('Active Bitrix install connection exists for profile')
            ->expectsOutputToContain('Dev-profile сохранён, но full_live setup ещё не готов.')
            ->assertFailed();

        $profile = Bitrix24Profile::query()->where('profile_key', 'dev-ivan-main')->firstOrFail();

        $this->assertSame('crm.alexlesley.biz', $profile->portal_domain);
        $this->assertSame(Bitrix24Profile::TYPE_FULL_LIVE, $profile->profile_type);
        $this->assertSame('https://spark-rocket.trycloudflare.com', $profile->callback_base_url);
        $this->assertSame('client-id-ivan', $profile->client_id);
        $this->assertSame('local.app.dev.ivan', $profile->application_code);
        $this->assertSame('ABC_TELEGRAM_DEV_IVAN_MAIN', $profile->telegram_source_id);
        $this->assertSame('ABC_MAX_DEV_IVAN_MAIN', $profile->max_source_id);
        $this->assertSame('abc_telegram_dev_ivan_main', $profile->telegram_connector_code);
        $this->assertSame('abc_max_dev_ivan_main', $profile->max_connector_code);
    }

    public function test_command_marks_existing_profile_ready_after_bitrix_side_verification_succeeds(): void
    {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => 'dev-ivan-main',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Dev Ivan Main',
            'client_id' => 'client-id-ivan',
            'application_code' => 'local.app.dev.ivan',
            'callback_base_url' => 'https://spark-rocket.trycloudflare.com',
            'telegram_source_id' => 'ABC_TELEGRAM_DEV_IVAN_MAIN',
            'max_source_id' => 'ABC_MAX_DEV_IVAN_MAIN',
            'telegram_connector_code' => 'abc_telegram_dev_ivan_main',
            'max_connector_code' => 'abc_max_dev_ivan_main',
        ]);

        $this->createActiveConnection($profile);
        $this->recordInstallCallbackEvent($profile, 'https://spark-rocket.trycloudflare.com');
        $this->createOpenLineRoutes($profile);
        $this->fakeBitrixVerifySuccess($profile);

        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://spark-rocket.trycloudflare.com',
        ])
            ->expectsOutputToContain('Bitrix app probe confirms application_code')
            ->expectsOutputToContain('Telegram route LINE_ID values exist on Bitrix')
            ->expectsOutputToContain('MAX route LINE_ID values exist on Bitrix')
            ->expectsOutputToContain('Dev-profile готов к full_live handoff и verify-контуру.')
            ->assertSuccessful();
    }

    public function test_command_updates_existing_dev_profile_callback_without_recreating_profile_but_keeps_profile_unready_until_fresh_install_callback_reaches_new_ingress(): void
    {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => 'dev-ivan-main',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Dev Ivan Main',
            'client_id' => 'client-id-ivan',
            'application_code' => 'local.app.dev.ivan',
            'callback_base_url' => 'https://old-tunnel.trycloudflare.com',
            'telegram_source_id' => 'ABC_TELEGRAM_DEV_IVAN_MAIN',
            'max_source_id' => 'ABC_MAX_DEV_IVAN_MAIN',
            'telegram_connector_code' => 'abc_telegram_dev_ivan_main',
            'max_connector_code' => 'abc_max_dev_ivan_main',
        ]);

        $this->createActiveConnection($profile);
        $this->recordInstallCallbackEvent($profile, 'https://old-tunnel.trycloudflare.com');
        $this->createOpenLineRoutes($profile);
        $this->fakeBitrixVerifySuccess($profile);

        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://new-tunnel.trycloudflare.com',
        ])
            ->expectsOutputToContain('Profile action: updated.')
            ->expectsOutputToContain('https://new-tunnel.trycloudflare.com/callbacks/bitrix24/events')
            ->expectsOutputToContain('Install callback reached current callback_base_url')
            ->expectsOutputToContain('Dev-profile сохранён, но full_live setup ещё не готов.')
            ->assertFailed();

        $this->assertSame(1, Bitrix24Profile::query()->count());

        $profile->refresh();

        $this->assertSame('https://new-tunnel.trycloudflare.com', $profile->callback_base_url);
        $this->assertSame('client-id-ivan', $profile->client_id);
        $this->assertSame(['max-line-ivan', 'telegram-line-ivan'], $profile->openLineRoutes()->pluck('line_id')->sort()->values()->all());
    }

    public function test_command_marks_rotated_profile_ready_after_fresh_install_callback_reaches_new_ingress(): void
    {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => 'dev-ivan-main',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Dev Ivan Main',
            'client_id' => 'client-id-ivan',
            'application_code' => 'local.app.dev.ivan',
            'callback_base_url' => 'https://new-tunnel.trycloudflare.com',
            'telegram_source_id' => 'ABC_TELEGRAM_DEV_IVAN_MAIN',
            'max_source_id' => 'ABC_MAX_DEV_IVAN_MAIN',
            'telegram_connector_code' => 'abc_telegram_dev_ivan_main',
            'max_connector_code' => 'abc_max_dev_ivan_main',
        ]);

        $this->createActiveConnection($profile);
        $this->recordInstallCallbackEvent($profile, 'https://new-tunnel.trycloudflare.com');
        $this->createOpenLineRoutes($profile);
        $this->fakeBitrixVerifySuccess($profile);

        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://new-tunnel.trycloudflare.com',
        ])
            ->expectsOutputToContain('Install callback reached current callback_base_url')
            ->expectsOutputToContain('Dev-profile готов к full_live handoff и verify-контуру.')
            ->assertSuccessful();
    }

    public function test_command_fails_when_only_failed_install_callback_exists_on_current_ingress(): void
    {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => 'dev-ivan-main',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Dev Ivan Main',
            'client_id' => 'client-id-ivan',
            'application_code' => 'local.app.dev.ivan',
            'callback_base_url' => 'https://new-tunnel.trycloudflare.com',
            'telegram_source_id' => 'ABC_TELEGRAM_DEV_IVAN_MAIN',
            'max_source_id' => 'ABC_MAX_DEV_IVAN_MAIN',
            'telegram_connector_code' => 'abc_telegram_dev_ivan_main',
            'max_connector_code' => 'abc_max_dev_ivan_main',
        ]);

        $this->createActiveConnection($profile);
        $this->createOpenLineRoutes($profile);
        $this->recordInstallCallbackEvent(
            $profile,
            'https://new-tunnel.trycloudflare.com',
            Bitrix24WebhookEvent::STATUS_FAILED,
        );
        $this->fakeBitrixVerifySuccess($profile);

        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://new-tunnel.trycloudflare.com',
        ])
            ->expectsOutputToContain('Install callback reached current callback_base_url')
            ->expectsOutputToContain('Dev-profile сохранён, но full_live setup ещё не готов.')
            ->assertFailed();
    }

    public function test_command_saves_profile_but_fails_when_bitrix_side_values_are_missing(): void
    {
        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://draft-tunnel.trycloudflare.com',
        ])
            ->expectsOutputToContain('Bitrix app client_id')
            ->expectsOutputToContain('Telegram LINE_ID')
            ->expectsOutputToContain('Dev-profile сохранён, но full_live setup ещё не готов.')
            ->assertFailed();

        $profile = Bitrix24Profile::query()->where('profile_key', 'dev-ivan-main')->firstOrFail();

        $this->assertSame('https://draft-tunnel.trycloudflare.com', $profile->callback_base_url);
        $this->assertNull($profile->client_id);
        $this->assertSame('ABC_TELEGRAM_DEV_IVAN_MAIN', $profile->telegram_source_id);
    }

    public function test_command_rejects_deprecated_line_id_options(): void
    {
        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://spark-rocket.trycloudflare.com',
            '--telegram-line-id' => 'telegram-line-ivan',
            '--max-line-id' => 'max-line-ivan',
        ])
            ->expectsOutputToContain('LINE_ID is configured per concrete channel route')
            ->assertExitCode(2);

        $this->assertSame(0, Bitrix24Profile::query()->count());
    }

    public function test_command_rejects_callback_base_url_used_by_another_profile_callback_owner(): void
    {
        $stagingProfile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'callback_base_url' => 'https://project.example.com',
        ]);

        Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $stagingProfile->id,
            'owner_key' => Bitrix24CallbackOwner::DEFAULT_LOCAL_OWNER_KEY,
            'display_name' => 'Локалка 1',
            'callback_base_url' => 'https://spark-rocket.trycloudflare.com',
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);

        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://spark-rocket.trycloudflare.com',
        ])
            ->expectsOutputToContain('callback_base_url `https://spark-rocket.trycloudflare.com` is already assigned to callback owner `local-1` on profile `staging`.')
            ->assertExitCode(2);

        $this->assertDatabaseMissing('bitrix24_profiles', [
            'profile_key' => 'dev-ivan-main',
        ]);
    }

    public function test_command_rejects_staging_profile_key(): void
    {
        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'callback_base_url' => 'https://draft-tunnel.trycloudflare.com',
        ])
            ->expectsOutputToContain('cannot mutate the fixed `staging` profile')
            ->assertExitCode(2);
    }

    public function test_command_fails_when_open_lines_probe_returns_unrelated_non_empty_payload(): void
    {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => 'dev-ivan-main',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Dev Ivan Main',
            'client_id' => 'client-id-ivan',
            'application_code' => 'local.app.dev.ivan',
            'callback_base_url' => 'https://spark-rocket.trycloudflare.com',
            'telegram_source_id' => 'ABC_TELEGRAM_DEV_IVAN_MAIN',
            'max_source_id' => 'ABC_MAX_DEV_IVAN_MAIN',
            'telegram_connector_code' => 'abc_telegram_dev_ivan_main',
            'max_connector_code' => 'abc_max_dev_ivan_main',
        ]);

        $this->createActiveConnection($profile);
        $this->recordInstallCallbackEvent($profile, 'https://spark-rocket.trycloudflare.com');
        $this->createOpenLineRoutes($profile);
        $this->fakeBitrixVerifyWithUnexpectedLinePayload($profile);

        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://spark-rocket.trycloudflare.com',
        ])
            ->expectsOutputToContain('Telegram route LINE_ID values exist on Bitrix')
            ->expectsOutputToContain('Bitrix did not confirm this LINE_ID through imopenlines.config.get.')
            ->expectsOutputToContain('Dev-profile сохранён, но full_live setup ещё не готов.')
            ->assertFailed();
    }

    private function createActiveConnection(Bitrix24Profile $profile): Bitrix24Connection
    {
        return Bitrix24Connection::query()->forceCreate([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'application_name' => 'Abrikosoff Connector',
            'client_id' => $profile->client_id,
            'member_id' => 'member-dev-profile',
            'application_token' => 'application-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_encrypted' => 'access-token',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'scope' => ['crm', 'imopenlines'],
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'install_payload' => [],
            'installed_at' => now()->subHour(),
            'last_install_callback_at' => now()->subHour(),
        ]);
    }

    private function createOpenLineRoutes(
        Bitrix24Profile $profile,
        string $telegramLineId = 'telegram-line-ivan',
        string $maxLineId = 'max-line-ivan',
    ): void {
        $telegram = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $max = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $telegram->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($telegram),
            'connector_code' => $profile->telegram_connector_code,
            'line_id' => $telegramLineId,
            'source_id' => $profile->telegram_source_id,
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $max->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($max),
            'connector_code' => $profile->max_connector_code,
            'line_id' => $maxLineId,
            'source_id' => $profile->max_source_id,
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
    }

    private function fakeBitrixVerifySuccess(Bitrix24Profile $profile): void
    {
        $lineIds = $profile->openLineRoutes()
            ->where('status', Bitrix24OpenLineRoute::STATUS_ACTIVE)
            ->pluck('line_id')
            ->map(fn (mixed $lineId): string => trim((string) $lineId))
            ->filter()
            ->values()
            ->all();

        Http::fake([
            'https://client-endpoint.example/rest/app.info.json' => Http::response([
                'result' => [
                    'CODE' => $profile->application_code,
                    'INSTALLED' => 1,
                ],
            ]),
            'https://client-endpoint.example/rest/imopenlines.config.get.json' => function ($request) use ($lineIds) {
                $lineId = trim((string) $request['CONFIG_ID']);

                if (in_array($lineId, $lineIds, true)) {
                    return Http::response([
                        'result' => [
                            'ID' => $lineId,
                        ],
                    ]);
                }

                return Http::response([
                    'error' => 'ERROR_NOT_FOUND',
                    'error_description' => 'Open line not found.',
                ], 404);
            },
        ]);
    }

    private function fakeBitrixVerifyWithUnexpectedLinePayload(Bitrix24Profile $profile): void
    {
        Http::fake([
            'https://client-endpoint.example/rest/app.info.json' => Http::response([
                'result' => [
                    'CODE' => $profile->application_code,
                    'INSTALLED' => 1,
                ],
            ]),
            'https://client-endpoint.example/rest/imopenlines.config.get.json' => Http::response([
                'result' => [
                    'TITLE' => 'Unexpected payload without line identifier',
                ],
            ]),
        ]);
    }

    private function recordInstallCallbackEvent(
        Bitrix24Profile $profile,
        string $callbackBaseUrl,
        string $processingStatus = Bitrix24WebhookEvent::STATUS_PENDING,
    ): Bitrix24WebhookEvent {
        $connection = $profile->connections()->firstOrFail();
        $recordedAt = now();

        $event = Bitrix24WebhookEvent::query()->create([
            'connection_id' => $connection->id,
            'callback_base_url' => $callbackBaseUrl,
            'callback_type' => Bitrix24WebhookEvent::TYPE_INSTALL,
            'event_name' => 'ONAPPINSTALL',
            'member_id' => (string) $connection->member_id,
            'application_token' => 'application-token-'.$profile->profile_key,
            'payload_hash' => hash('sha256', $callbackBaseUrl.'|'.$profile->profile_key.'|'.$recordedAt->toIso8601String()),
            'payload' => ['event' => 'ONAPPINSTALL'],
            'headers' => [],
            'query' => [],
            'processing_status' => $processingStatus,
        ]);

        $event->forceFill([
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ])->saveQuietly();

        $connection->forceFill([
            'last_install_callback_at' => $recordedAt,
        ])->saveQuietly();

        return $event;
    }
}
