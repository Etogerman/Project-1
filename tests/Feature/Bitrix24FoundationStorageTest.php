<?php

namespace Tests\Feature;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Services\Bitrix24\BackfillBitrix24ConnectionProfilesAction;
use App\Services\Bitrix24\BackfillBitrix24ProfileRoutingFieldsAction;
use App\Services\Bitrix24\Bitrix24ConnectionStateException;
use App\Services\Bitrix24\NormalizeBitrix24ProfileCallbackBaseUrlsAction;
use App\Services\Bitrix24\ResolveCurrentBitrix24CallbackBaseUrlAction;
use App\Services\Bitrix24\ResolveCurrentBitrix24ConnectionAction;
use App\Services\Bitrix24\ResolveCurrentBitrix24ProfileAction;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class Bitrix24FoundationStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_bitrix24_connection_encrypts_tokens_and_casts_json_and_datetimes(): void
    {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'https://project.example.com',
        ]);

        $connection = Bitrix24Connection::query()->forceCreate([
            'profile_id' => $profile->id,
            'portal_domain' => 'crm.alexlesley.biz',
            'application_name' => 'Abrikosoff Connector',
            'client_id' => 'local.test',
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_encrypted' => 'plain-access-token',
            'refresh_token_encrypted' => 'plain-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'scope' => ['crm', 'tasks'],
            'install_payload' => [
                'event' => 'ONAPPINSTALL',
                'auth' => [
                    'domain' => 'crm.alexlesley.biz',
                ],
            ],
            'installed_at' => now(),
        ]);

        $storedAccessToken = DB::table('bitrix24_connections')
            ->where('id', $connection->id)
            ->value('access_token_encrypted');

        $storedRefreshToken = DB::table('bitrix24_connections')
            ->where('id', $connection->id)
            ->value('refresh_token_encrypted');
        $storedApplicationToken = DB::table('bitrix24_connections')
            ->where('id', $connection->id)
            ->value('application_token');
        $storedApplicationTokenHash = DB::table('bitrix24_connections')
            ->where('id', $connection->id)
            ->value('application_token_hash');

        $this->assertIsString($storedAccessToken);
        $this->assertIsString($storedRefreshToken);
        $this->assertStringNotContainsString('plain-access-token', $storedAccessToken);
        $this->assertStringNotContainsString('plain-refresh-token', $storedRefreshToken);
        $this->assertNull($storedApplicationToken);
        $this->assertSame(
            hash('sha256', 'application-token'),
            $storedApplicationTokenHash,
        );

        $connection->refresh();

        $this->assertNull($connection->application_token);
        $this->assertSame('plain-access-token', $connection->access_token_encrypted);
        $this->assertSame('plain-refresh-token', $connection->refresh_token_encrypted);
        $this->assertIsArray($connection->scope);
        $this->assertSame(['crm', 'tasks'], $connection->scope);
        $this->assertIsArray($connection->install_payload);
        $this->assertNotNull($connection->access_token_expires_at);
        $this->assertNotNull($connection->installed_at);
        $this->assertTrue($connection->profile->is($profile));
    }

    public function test_bitrix24_webhook_event_uses_dedupe_constraint_and_array_casts(): void
    {
        $connection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $event = Bitrix24WebhookEvent::query()->create([
            'connection_id' => $connection->id,
            'callback_base_url' => 'https://project.example.com',
            'callback_type' => Bitrix24WebhookEvent::TYPE_EVENTS,
            'event_name' => 'ONCRMCONTACTUPDATE',
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'portal_domain' => 'crm.alexlesley.biz',
            'payload_hash' => str_repeat('a', 64),
            'payload' => [
                'event' => 'ONCRMCONTACTUPDATE',
            ],
            'headers' => [
                'x-bitrix-test' => '1',
            ],
            'query' => [
                'event' => 'ONCRMCONTACTUPDATE',
            ],
            'processing_status' => Bitrix24WebhookEvent::STATUS_PENDING,
        ]);

        $storedEventToken = DB::table('bitrix24_webhook_events')
            ->where('id', $event->id)
            ->value('application_token');
        $storedEventTokenHash = DB::table('bitrix24_webhook_events')
            ->where('id', $event->id)
            ->value('application_token_hash');

        $this->assertIsArray($event->payload);
        $this->assertIsArray($event->headers);
        $this->assertIsArray($event->query);
        $this->assertTrue($event->connection()->is($connection));
        $this->assertSame('', $storedEventToken);
        $this->assertSame(
            hash('sha256', 'application-token'),
            $storedEventTokenHash,
        );

        $this->expectException(QueryException::class);

        Bitrix24WebhookEvent::query()->create([
            'connection_id' => $connection->id,
            'callback_base_url' => 'https://project.example.com',
            'callback_type' => Bitrix24WebhookEvent::TYPE_EVENTS,
            'event_name' => 'ONCRMCONTACTUPDATE',
            'member_id' => 'member-1',
            'application_token' => 'another-token',
            'portal_domain' => 'crm.alexlesley.biz',
            'payload_hash' => str_repeat('a', 64),
            'payload' => [
                'event' => 'ONCRMCONTACTUPDATE',
            ],
            'processing_status' => Bitrix24WebhookEvent::STATUS_PENDING,
        ]);
    }

    public function test_bitrix24_sync_log_is_append_only_and_relates_to_connection(): void
    {
        $connection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $log = Bitrix24SyncLog::query()->create([
            'connection_id' => $connection->id,
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'install_upsert',
            'entity_type' => 'connection',
            'entity_id' => (string) $connection->id,
            'request_payload' => [
                'portal_domain' => 'crm.alexlesley.biz',
            ],
            'response_payload' => [
                'status' => 'ok',
            ],
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
            'http_status' => 200,
            'fingerprint' => 'install-upsert:1',
        ]);

        $this->assertIsArray($log->request_payload);
        $this->assertIsArray($log->response_payload);
        $this->assertNotNull($log->created_at);
        $this->assertNull($log->updated_at);
        $this->assertTrue($log->connection()->is($connection));
        $this->assertFalse(Schema::hasColumn('bitrix24_sync_logs', 'updated_at'));
    }

    public function test_bitrix24_connection_relations_load_events_and_sync_logs(): void
    {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'https://project.example.com',
        ]);

        $connection = Bitrix24Connection::query()->forceCreate([
            'profile_id' => $profile->id,
            'portal_domain' => 'crm.alexlesley.biz',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        Bitrix24WebhookEvent::query()->create([
            'connection_id' => $connection->id,
            'callback_type' => Bitrix24WebhookEvent::TYPE_INSTALL,
            'event_name' => 'ONAPPINSTALL',
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'payload_hash' => str_repeat('b', 64),
            'payload' => ['event' => 'ONAPPINSTALL'],
            'processing_status' => Bitrix24WebhookEvent::STATUS_PENDING,
        ]);

        Bitrix24SyncLog::query()->create([
            'connection_id' => $connection->id,
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'callback_validation',
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
        ]);

        $connection->refresh();

        $this->assertCount(1, $connection->webhookEvents);
        $this->assertCount(1, $connection->syncLogs);
        $this->assertTrue($connection->profile->is($profile));
    }

    public function test_bitrix24_connection_enforces_unique_profile_id(): void
    {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'https://project.example.com',
        ]);

        Bitrix24Connection::query()->forceCreate([
            'profile_id' => $profile->id,
            'portal_domain' => 'crm.alexlesley.biz',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $this->expectException(QueryException::class);

        Bitrix24Connection::query()->forceCreate([
            'profile_id' => $profile->id,
            'portal_domain' => 'crm.duplicate.biz',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);
    }

    public function test_bitrix24_profile_registry_enforces_unique_portal_profile_key_and_callback_base_url(): void
    {
        Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'https://project.example.com',
        ]);

        $this->expectException(QueryException::class);

        Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_CRM_ONLY,
            'display_name' => 'Duplicate',
            'client_id' => 'client-id-2',
            'application_code' => 'local.app.code.2',
            'callback_base_url' => 'https://second.example.com',
        ]);
    }

    public function test_bitrix24_profile_registry_enforces_global_unique_callback_base_url(): void
    {
        Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'https://project.example.com',
        ]);

        $this->expectException(QueryException::class);

        Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.second.biz',
            'profile_key' => 'dev-alex',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Dev Alex',
            'client_id' => 'client-id-2',
            'application_code' => 'local.app.code.2',
            'callback_base_url' => 'https://project.example.com',
        ]);
    }

    public function test_bitrix24_profile_normalizes_callback_base_url_on_write(): void
    {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'HTTPS://Project.Example.com/prefix/',
        ]);

        $profile->refresh();

        $this->assertSame('https://project.example.com/prefix', $profile->callback_base_url);
        $this->assertSame(
            'https://project.example.com/prefix/callbacks/bitrix24/install',
            $profile->installCallbackUrl(),
        );
    }

    public function test_backfill_profiles_assigns_only_connections_for_configured_portal(): void
    {
        config()->set('bitrix24.portal_domain', 'crm.alexlesley.biz');
        config()->set('bitrix24.application.client_id', 'client-id');
        config()->set('bitrix24.application.code', 'local.app.code');
        config()->set('bitrix24.callbacks.install_url', 'https://project.example.com/callbacks/bitrix24/install');

        $matchingConnection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'application_token' => 'application-token-1',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $foreignConnection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.foreign.biz',
            'member_id' => 'member-2',
            'application_token' => 'application-token-2',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        app(BackfillBitrix24ConnectionProfilesAction::class)->handle();

        $matchingConnection->refresh();
        $foreignConnection->refresh();

        $this->assertNotNull($matchingConnection->profile_id);
        $this->assertNull($foreignConnection->profile_id);
        $this->assertSame(1, Bitrix24Profile::query()->count());

        $profile = Bitrix24Profile::query()->firstOrFail();

        $this->assertSame('crm.alexlesley.biz', $profile->portal_domain);
        $this->assertSame(Bitrix24Profile::PROFILE_KEY_STAGING, $profile->profile_key);
        $this->assertSame('https://project.example.com', $profile->callback_base_url);
    }

    public function test_backfill_profiles_strips_callback_suffix_from_path_prefixed_urls(): void
    {
        config()->set('bitrix24.portal_domain', 'crm.alexlesley.biz');
        config()->set('bitrix24.application.client_id', 'client-id');
        config()->set('bitrix24.application.code', 'local.app.code');
        config()->set('bitrix24.callbacks.install_url', 'https://project.example.com/prefix/callbacks/bitrix24/install');

        $matchingConnection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'application_token' => 'application-token-1',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        app(BackfillBitrix24ConnectionProfilesAction::class)->handle();

        $matchingConnection->refresh();

        $profile = Bitrix24Profile::query()->firstOrFail();

        $this->assertNotNull($matchingConnection->profile_id);
        $this->assertSame('https://project.example.com/prefix', $profile->callback_base_url);
        $this->assertSame(
            'https://project.example.com/prefix/callbacks/bitrix24/install',
            $profile->installCallbackUrl(),
        );
    }

    public function test_backfill_profiles_skips_default_callback_owner_before_owner_table_exists(): void
    {
        config()->set('bitrix24.portal_domain', 'crm.alexlesley.biz');
        config()->set('bitrix24.application.client_id', 'client-id');
        config()->set('bitrix24.application.code', 'local.app.code');
        config()->set('bitrix24.callbacks.install_url', 'https://project.example.com/callbacks/bitrix24/install');

        $matchingConnection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'application_token' => 'application-token-1',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        Schema::table('bitrix24_open_line_routes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('callback_owner_id');
        });
        Schema::dropIfExists('bitrix24_callback_owners');

        app(BackfillBitrix24ConnectionProfilesAction::class)->handle();

        $profile = Bitrix24Profile::query()->firstOrFail();

        $this->assertNotNull($matchingConnection->refresh()->profile_id);
        $this->assertSame('https://project.example.com', $profile->callback_base_url);
        $this->assertFalse(Schema::hasTable('bitrix24_callback_owners'));
    }

    public function test_backfill_profiles_rejects_callback_url_used_by_another_profile_callback_owner(): void
    {
        config()->set('bitrix24.portal_domain', 'crm.alexlesley.biz');
        config()->set('bitrix24.application.client_id', 'client-id');
        config()->set('bitrix24.application.code', 'local.app.code');
        config()->set('bitrix24.callbacks.install_url', 'https://owner-tunnel.example.test/callbacks/bitrix24/install');

        $matchingConnection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'application_token' => 'application-token-1',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $foreignProfile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.foreign.biz',
            'profile_key' => 'dev-foreign',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Foreign',
            'callback_base_url' => 'https://foreign.example.test',
        ]);

        Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $foreignProfile->id,
            'owner_key' => Bitrix24CallbackOwner::DEFAULT_LOCAL_OWNER_KEY,
            'display_name' => 'Локалка 1',
            'callback_base_url' => 'https://owner-tunnel.example.test',
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);

        try {
            app(BackfillBitrix24ConnectionProfilesAction::class)->handle();
            $this->fail('Expected backfill to reject a callback owner URL conflict.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'callback_base_url `https://owner-tunnel.example.test` is already assigned to callback owner `local-1` on profile `dev-foreign`.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('bitrix24_profiles', [
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
        ]);
        $this->assertNull($matchingConnection->refresh()->profile_id);
    }

    public function test_webhook_event_dedupe_is_scoped_by_callback_base_url(): void
    {
        $firstEvent = Bitrix24WebhookEvent::query()->create([
            'callback_base_url' => 'https://first.example.test',
            'callback_type' => Bitrix24WebhookEvent::TYPE_EVENTS,
            'event_name' => 'ONCRMCONTACTUPDATE',
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'payload_hash' => str_repeat('b', 64),
            'payload' => ['event' => 'ONCRMCONTACTUPDATE'],
            'processing_status' => Bitrix24WebhookEvent::STATUS_PENDING,
        ]);

        $secondEvent = Bitrix24WebhookEvent::query()->create([
            'callback_base_url' => 'https://second.example.test',
            'callback_type' => Bitrix24WebhookEvent::TYPE_EVENTS,
            'event_name' => 'ONCRMCONTACTUPDATE',
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'payload_hash' => str_repeat('b', 64),
            'payload' => ['event' => 'ONCRMCONTACTUPDATE'],
            'processing_status' => Bitrix24WebhookEvent::STATUS_PENDING,
        ]);

        $this->assertNotSame($firstEvent->id, $secondEvent->id);
        $this->assertSame(2, Bitrix24WebhookEvent::query()->count());
    }

    public function test_current_runtime_selector_resolves_single_profile_and_connection_from_configured_callbacks(): void
    {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'https://project.example.com/prefix',
        ]);

        $connection = Bitrix24Connection::query()->forceCreate([
            'profile_id' => $profile->id,
            'portal_domain' => 'crm.alexlesley.biz',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        config()->set('bitrix24.callbacks.install_url', 'https://project.example.com/prefix/callbacks/bitrix24/install');
        config()->set('bitrix24.callbacks.events_url', 'https://project.example.com/prefix/callbacks/bitrix24/events');
        config()->set('bitrix24.callbacks.openlines_url', 'https://project.example.com/prefix/callbacks/bitrix24/openlines');

        $this->assertSame(
            'https://project.example.com/prefix',
            app(ResolveCurrentBitrix24CallbackBaseUrlAction::class)->handle(),
        );
        $this->assertTrue(app(ResolveCurrentBitrix24ProfileAction::class)->handle()->is($profile));
        $this->assertTrue(app(ResolveCurrentBitrix24ConnectionAction::class)->handle()->is($connection));
    }

    public function test_current_runtime_selector_rejects_profile_that_does_not_allow_openlines_runtime(): void
    {
        Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_CRM_ONLY,
            'display_name' => 'Staging CRM',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'https://project.example.com/prefix',
        ]);

        config()->set('bitrix24.callbacks.install_url', 'https://project.example.com/prefix/callbacks/bitrix24/install');
        config()->set('bitrix24.callbacks.events_url', 'https://project.example.com/prefix/callbacks/bitrix24/events');
        config()->set('bitrix24.callbacks.openlines_url', 'https://project.example.com/prefix/callbacks/bitrix24/openlines');

        $this->expectException(Bitrix24ConnectionStateException::class);
        $this->expectExceptionMessage('does not allow openlines runtime');

        app(ResolveCurrentBitrix24ProfileAction::class)->handle();
    }

    public function test_existing_profile_rows_are_normalized_for_runtime_selection_rollout(): void
    {
        DB::table('bitrix24_profiles')->insert([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'HTTPS://Project.Example.com/prefix/',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config()->set('bitrix24.callbacks.install_url', 'https://project.example.com/prefix/callbacks/bitrix24/install');
        config()->set('bitrix24.callbacks.events_url', 'https://project.example.com/prefix/callbacks/bitrix24/events');
        config()->set('bitrix24.callbacks.openlines_url', 'https://project.example.com/prefix/callbacks/bitrix24/openlines');

        app(NormalizeBitrix24ProfileCallbackBaseUrlsAction::class)->handle();

        $profile = Bitrix24Profile::query()->firstOrFail();

        $this->assertSame('https://project.example.com/prefix', $profile->callback_base_url);
        $this->assertTrue(app(ResolveCurrentBitrix24ProfileAction::class)->handle()->is($profile));
    }

    public function test_backfill_profile_routing_fields_assigns_non_line_values_from_legacy_config(): void
    {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'https://project.example.com/prefix',
        ]);

        config()->set('bitrix24.callbacks.install_url', 'https://project.example.com/prefix/callbacks/bitrix24/install');
        config()->set('bitrix24.callbacks.events_url', 'https://project.example.com/prefix/callbacks/bitrix24/events');
        config()->set('bitrix24.callbacks.openlines_url', 'https://project.example.com/prefix/callbacks/bitrix24/openlines');
        config()->set('bitrix24.sources.telegram_id', 'ABRIKOSOFF_TELEGRAM');
        config()->set('bitrix24.sources.max_id', 'ABRIKOSOFF_MAX');
        config()->set('bitrix24.openlines.telegram_connector_code', 'abrikosoff_telegram');
        config()->set('bitrix24.openlines.max_connector_code', 'abrikosoff_max');
        config()->set('bitrix24.openlines.telegram_line_id', 'line-telegram');
        config()->set('bitrix24.openlines.max_line_id', 'line-max');

        app(BackfillBitrix24ProfileRoutingFieldsAction::class)->handle();

        $profile->refresh();

        $this->assertSame('ABRIKOSOFF_TELEGRAM', $profile->telegram_source_id);
        $this->assertSame('ABRIKOSOFF_MAX', $profile->max_source_id);
        $this->assertSame('abrikosoff_telegram', $profile->telegram_connector_code);
        $this->assertSame('abrikosoff_max', $profile->max_connector_code);
        $this->assertNull($profile->telegram_line_id);
        $this->assertNull($profile->max_line_id);
    }

    public function test_current_runtime_selector_fails_when_configured_callbacks_resolve_to_different_base_urls(): void
    {
        config()->set('bitrix24.callbacks.install_url', 'https://project.example.com/callbacks/bitrix24/install');
        config()->set('bitrix24.callbacks.events_url', 'https://other.example.com/callbacks/bitrix24/events');

        $this->expectException(Bitrix24ConnectionStateException::class);

        app(ResolveCurrentBitrix24CallbackBaseUrlAction::class)->handle();
    }
}
