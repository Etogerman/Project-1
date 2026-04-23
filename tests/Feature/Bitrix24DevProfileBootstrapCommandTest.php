<?php

namespace Tests\Feature;

use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24WebhookEvent;
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
            '--telegram-line-id' => 'telegram-line-ivan',
            '--max-line-id' => 'max-line-ivan',
        ])
            ->expectsOutput('Bitrix24 dev-profile bootstrap completed.')
            ->expectsOutputToContain('Profile action: created.')
            ->expectsOutputToContain('ABC_TELEGRAM_DEV_IVAN_MAIN')
            ->expectsOutputToContain('abc_telegram_dev_ivan_main')
            ->expectsOutputToContain('https://spark-rocket.trycloudflare.com/callbacks/bitrix24/install')
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
        $this->assertSame('telegram-line-ivan', $profile->telegram_line_id);
        $this->assertSame('max-line-ivan', $profile->max_line_id);
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
            'telegram_line_id' => 'telegram-line-ivan',
            'max_line_id' => 'max-line-ivan',
        ]);

        $this->createActiveConnection($profile);
        $this->recordInstallCallbackEvent($profile, 'https://spark-rocket.trycloudflare.com');
        $this->fakeBitrixVerifySuccess($profile);

        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://spark-rocket.trycloudflare.com',
        ])
            ->expectsOutputToContain('Bitrix app probe confirms application_code')
            ->expectsOutputToContain('Telegram LINE_ID exists on Bitrix')
            ->expectsOutputToContain('MAX LINE_ID exists on Bitrix')
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
            'telegram_line_id' => 'telegram-line-ivan',
            'max_line_id' => 'max-line-ivan',
        ]);

        $this->createActiveConnection($profile);
        $this->recordInstallCallbackEvent($profile, 'https://old-tunnel.trycloudflare.com');
        $this->fakeBitrixVerifySuccess($profile);

        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://new-tunnel.trycloudflare.com',
        ])
            ->expectsOutputToContain('Profile action: updated.')
            ->expectsOutputToContain('https://new-tunnel.trycloudflare.com/callbacks/bitrix24/events')
            ->expectsOutputToContain('Install callback reached current callback_base_url')
            ->expectsOutputToContain('No install callback has been recorded for the current callback_base_url yet')
            ->expectsOutputToContain('Dev-profile сохранён, но full_live setup ещё не готов.')
            ->assertFailed();

        $this->assertSame(1, Bitrix24Profile::query()->count());

        $profile->refresh();

        $this->assertSame('https://new-tunnel.trycloudflare.com', $profile->callback_base_url);
        $this->assertSame('client-id-ivan', $profile->client_id);
        $this->assertSame('telegram-line-ivan', $profile->telegram_line_id);
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
            'telegram_line_id' => 'telegram-line-ivan',
            'max_line_id' => 'max-line-ivan',
        ]);

        $this->createActiveConnection($profile);
        $this->recordInstallCallbackEvent($profile, 'https://new-tunnel.trycloudflare.com');
        $this->fakeBitrixVerifySuccess($profile);

        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://new-tunnel.trycloudflare.com',
        ])
            ->expectsOutputToContain('Install callback reached current callback_base_url')
            ->expectsOutputToContain('An install callback is already recorded on the current callback_base_url.')
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
            'telegram_line_id' => 'telegram-line-ivan',
            'max_line_id' => 'max-line-ivan',
        ]);

        $this->createActiveConnection($profile);
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
            ->expectsOutputToContain('Only install callbacks with status `failed` have been recorded')
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
        $this->assertNull($profile->telegram_line_id);
        $this->assertSame('ABC_TELEGRAM_DEV_IVAN_MAIN', $profile->telegram_source_id);
    }

    public function test_command_fails_when_telegram_and_max_reuse_one_line_id(): void
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
            'telegram_line_id' => 'shared-line',
            'max_line_id' => 'shared-line',
        ]);

        $this->createActiveConnection($profile);
        $this->fakeBitrixVerifySuccess($profile);

        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://spark-rocket.trycloudflare.com',
        ])
            ->expectsOutputToContain('Telegram and MAX use different LINE_ID values')
            ->expectsOutputToContain('one shared LINE_ID is not allowed')
            ->assertFailed();
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

    public function test_command_rejects_duplicate_explicit_line_id_assignment(): void
    {
        Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => 'dev-alex-main',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Dev Alex Main',
            'client_id' => 'client-id-alex',
            'application_code' => 'local.app.dev.alex',
            'callback_base_url' => 'https://alex-tunnel.trycloudflare.com',
            'telegram_source_id' => 'ABC_TELEGRAM_DEV_ALEX_MAIN',
            'max_source_id' => 'ABC_MAX_DEV_ALEX_MAIN',
            'telegram_connector_code' => 'abc_telegram_dev_alex_main',
            'max_connector_code' => 'abc_max_dev_alex_main',
            'telegram_line_id' => 'telegram-line-shared',
            'max_line_id' => 'max-line-alex',
        ]);

        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://ivan-tunnel.trycloudflare.com',
            '--telegram-line-id' => 'telegram-line-shared',
        ])
            ->expectsOutputToContain('Telegram LINE_ID `telegram-line-shared` is already assigned to profile `dev-alex-main`.')
            ->assertExitCode(2);

        $this->assertSame(1, Bitrix24Profile::query()->count());
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
            'telegram_line_id' => 'telegram-line-ivan',
            'max_line_id' => 'max-line-ivan',
        ]);

        $this->createActiveConnection($profile);
        $this->recordInstallCallbackEvent($profile, 'https://spark-rocket.trycloudflare.com');
        $this->fakeBitrixVerifyWithUnexpectedLinePayload($profile);

        $this->artisan('bitrix24:dev-profile-bootstrap', [
            'profile_key' => 'dev-ivan-main',
            'callback_base_url' => 'https://spark-rocket.trycloudflare.com',
        ])
            ->expectsOutputToContain('Telegram LINE_ID exists on Bitrix')
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

    private function fakeBitrixVerifySuccess(Bitrix24Profile $profile): void
    {
        Http::fake([
            'https://client-endpoint.example/rest/app.info.json' => Http::response([
                'result' => [
                    'CODE' => $profile->application_code,
                    'INSTALLED' => 1,
                ],
            ]),
            'https://client-endpoint.example/rest/imopenlines.config.get.json' => function ($request) use ($profile) {
                $lineId = trim((string) $request['CONFIG_ID']);

                if (in_array($lineId, [
                    (string) $profile->telegram_line_id,
                    (string) $profile->max_line_id,
                ], true)) {
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
