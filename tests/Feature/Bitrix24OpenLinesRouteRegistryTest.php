<?php

namespace Tests\Feature;

use App\Filament\Resources\Bitrix24Connections\Pages\ViewBitrix24Connection;
use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use App\Models\User;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryClient;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryException;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistrySnapshotLock;
use App\Services\Bitrix24\DoctorBitrix24OpenLinesRouteRegistryAction;
use App\Services\Bitrix24\PublishBitrix24OpenLinesRouteRegistryAction;
use Filament\Facades\Filament;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Bitrix24OpenLinesRouteRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_publish_sends_signed_owner_snapshots_to_bitrix_registry(): void
    {
        $secret = 'registry-secret-for-feature-test-123456';
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => $secret,
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();
        $inactiveOwner = Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => 'z-inactive',
            'display_name' => 'Отключенный владелец',
            'callback_base_url' => 'https://inactive.example.test/callback',
            'status' => Bitrix24CallbackOwner::STATUS_INACTIVE,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram Local',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '32',
            'line_name' => 'Telegram bot local',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABRIKOSOFF_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        Http::fake(function (Request $request) {
            $payload = json_decode($request->body(), true);

            return Http::response([
                'ok' => true,
                'owner_profile_key' => $payload['owner_profile_key'] ?? '',
                'published_routes' => count($payload['routes'] ?? []),
            ]);
        });

        $result = app(PublishBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());

        $this->assertSame(2, $result['published_owners']);
        $this->assertSame(1, $result['published_routes']);

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_PUBLISHED, $profile->openlines_route_registry_last_status);
        $this->assertNull($profile->openlines_route_registry_last_error);
        $this->assertNotNull($profile->openlines_route_registry_last_published_at);

        $requests = Http::recorded();
        $this->assertCount(2, $requests);

        /** @var Request $activeRequest */
        $activeRequest = $requests[0][0];
        $activePayload = json_decode($activeRequest->body(), true);

        $this->assertSame('POST', $activeRequest->method());
        $this->assertSame('https://stagecrm.fvds.ru/local/tools/abrikosoff_openlines/route-registry.php', $activeRequest->url());
        $this->assertSame('stagecrm.fvds.ru', $activePayload['portal_domain']);
        $this->assertSame($owner->owner_key, $activePayload['owner_profile_key']);
        $this->assertSame($owner->callback_base_url, $activePayload['owner_callback_base_url']);
        $this->assertSame([
            'abrikosoff_telegram' => [
                'connector_code' => 'abrikosoff_telegram',
                'connector_type' => 'telegram',
            ],
        ], $activePayload['connectors']);
        $this->assertSame([
            'abrikosoff_telegram:32' => [
                'connector_code' => 'abrikosoff_telegram',
                'line_id' => '32',
                'line_name' => 'Telegram bot local',
                'active' => true,
            ],
        ], $activePayload['routes']);
        $this->assertRegistryRequestSigned($activeRequest, $secret, 'POST', '');

        /** @var Request $inactiveRequest */
        $inactiveRequest = $requests[1][0];
        $inactivePayload = json_decode($inactiveRequest->body(), true);

        $this->assertSame($inactiveOwner->owner_key, $inactivePayload['owner_profile_key']);
        $this->assertSame([], $inactivePayload['connectors']);
        $this->assertSame([], $inactivePayload['routes']);
        $this->assertRegistryRequestSigned($inactiveRequest, $secret, 'POST', '');
    }

    public function test_publish_holds_snapshot_lock_through_remote_request(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The production registry snapshot lock is PostgreSQL-specific.');
        }

        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => 'registry-secret-for-snapshot-lock-test',
        ]);
        $defaultConnection = (string) config('database.default');
        $concurrentConnection = 'bitrix24_registry_publish_snapshot_concurrent';
        config([
            'database.connections.'.$concurrentConnection => config(
                'database.connections.'.$defaultConnection,
            ),
        ]);
        DB::purge($concurrentConnection);
        $concurrentLock = new Bitrix24OpenLinesRouteRegistrySnapshotLock(
            DB::connection($concurrentConnection),
        );
        $lockObserved = false;

        Http::fake(function () use (&$lockObserved, $concurrentLock) {
            try {
                $concurrentLock->run(fn (): bool => true);
                $this->fail('A concurrent snapshot source write must not enter during publish.');
            } catch (LockTimeoutException $exception) {
                $lockObserved = true;
                $this->assertSame(
                    Bitrix24OpenLinesRouteRegistrySnapshotLock::BUSY_MESSAGE,
                    $exception->getMessage(),
                );
            }

            return Http::response([
                'ok' => true,
                'owner_profile_key' => Bitrix24CallbackOwner::DEFAULT_LOCAL_OWNER_KEY,
                'published_routes' => 0,
            ]);
        });

        try {
            $result = app(PublishBitrix24OpenLinesRouteRegistryAction::class)
                ->handle($profile->fresh());
        } finally {
            DB::purge($concurrentConnection);
        }

        $this->assertTrue($lockObserved);
        $this->assertSame(1, $result['published_owners']);
        $this->assertSame(0, $result['published_routes']);
    }

    public function test_publish_sends_numeric_only_max_connector_as_json_object_key(): void
    {
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => 'registry-secret-for-numeric-max-test',
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();
        $channel = Channel::factory()->create([
            'name' => 'Numeric MAX',
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX,
            'connector_code' => '0',
            'line_id' => '45',
            'line_name' => 'Numeric MAX',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABRIKOSOFF_MAX',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        Http::fake(fn () => Http::response([
            'ok' => true,
            'owner_profile_key' => $owner->owner_key,
            'published_routes' => 1,
        ]));

        $result = app(PublishBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());

        $this->assertSame(1, $result['published_routes']);

        $requests = Http::recorded();
        $this->assertCount(1, $requests);

        /** @var Request $request */
        $request = $requests[0][0];
        $payload = json_decode($request->body());

        $this->assertIsObject($payload);
        $this->assertIsObject($payload->connectors);
        $this->assertSame('0', $payload->connectors->{'0'}->connector_code);
        $this->assertSame('max', $payload->connectors->{'0'}->connector_type);
        $this->assertSame('0', $payload->routes->{'0:45'}->connector_code);
    }

    public function test_publish_keeps_misconfigured_line_as_inactive_ownership_claim(): void
    {
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => 'registry-secret-for-misconfigured-claim',
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();
        $channel = Channel::factory()->create([
            'name' => 'Repairing MAX',
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX,
            'connector_code' => 'abc_max',
            'line_id' => '14',
            'line_name' => 'Repairing MAX',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABC_MAX',
            'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
        ]);

        Http::fake(fn (Request $request) => Http::response([
            'ok' => true,
            'owner_profile_key' => $owner->owner_key,
            'published_routes' => count(json_decode($request->body(), true)['routes'] ?? []),
        ]));

        $result = app(PublishBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());
        $requests = Http::recorded();
        $payload = json_decode($requests[0][0]->body(), true);

        $this->assertSame(1, $result['published_routes']);
        $this->assertSame([
            'abc_max:14' => [
                'connector_code' => 'abc_max',
                'line_id' => '14',
                'line_name' => 'Repairing MAX',
                'active' => false,
            ],
        ], $payload['routes']);
    }

    public function test_acquire_line_lease_sends_complete_signed_ownership_identity(): void
    {
        $secret = 'registry-secret-for-line-lease-client';
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => $secret,
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();

        Http::fake(fn () => Http::response([
            'ok' => true,
            'lease_token' => str_repeat('a', 64),
            'expires_at' => now()->addMinutes(6)->toIso8601String(),
        ]));

        $lease = app(Bitrix24OpenLinesRouteRegistryClient::class)->acquireLineLease(
            $profile,
            $owner,
            'abc_max',
            'max',
            '14',
            360,
        );
        $requests = Http::recorded();

        $this->assertSame(str_repeat('a', 64), $lease['lease_token']);
        $this->assertCount(1, $requests);

        /** @var Request $request */
        $request = $requests[0][0];
        $payload = json_decode($request->body(), true);

        $this->assertSame(
            'https://stagecrm.fvds.ru/local/tools/abrikosoff_openlines/route-registry.php?action=acquire-line-lease',
            $request->url(),
        );
        $this->assertSame([
            'portal_domain' => 'stagecrm.fvds.ru',
            'owner_profile_key' => $owner->owner_key,
            'owner_callback_base_url' => $owner->callback_base_url,
            'connector_code' => 'abc_max',
            'connector_type' => 'max',
            'line_id' => '14',
            'lease_seconds' => 360,
            'lease_scope' => 'connector_registration',
        ], $payload);
        $this->assertRegistryRequestSigned(
            $request,
            $secret,
            'POST',
            'action=acquire-line-lease',
        );
    }

    public function test_acquire_line_lease_rejects_noncanonical_line_id_before_http_request(): void
    {
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => 'registry-secret-for-invalid-line-test',
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();
        Http::preventStrayRequests();

        foreach (['line-editable', '014', ' 14 '] as $lineId) {
            try {
                app(Bitrix24OpenLinesRouteRegistryClient::class)->acquireLineLease(
                    $profile,
                    $owner,
                    'abc_max',
                    'max',
                    $lineId,
                    360,
                );
                $this->fail('Noncanonical LINE_ID must fail before the registry request.');
            } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
                $this->assertSame('route_registry_line_id_invalid', $exception->errorCode);
                $this->assertSame(
                    'LINE_ID открытой линии должен быть канонической строкой из 1–64 цифр.',
                    $exception->getMessage(),
                );
            }
        }

        $this->assertCount(0, Http::recorded());
    }

    public function test_doctor_compares_signed_snapshot_with_local_owner_scope(): void
    {
        $secret = 'registry-secret-for-doctor-test-123456';
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => $secret,
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();
        $channel = Channel::factory()->create([
            'name' => 'Telegram Local',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '32',
            'line_name' => 'Telegram bot local',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABRIKOSOFF_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_LEGACY,
        ]);

        Http::fake([
            'https://stagecrm.fvds.ru/local/tools/abrikosoff_openlines/route-registry.php?action=snapshot' => Http::response([
                'ok' => true,
                'registry' => [
                    'schema_version' => 1,
                    'portal_domain' => 'stagecrm.fvds.ru',
                    'updated_at' => now()->toIso8601String(),
                    'owners' => [
                        $owner->owner_key => [
                            'owner_profile_key' => $owner->owner_key,
                            'owner_callback_base_url' => $owner->callback_base_url,
                            'connectors' => [
                                'abrikosoff_telegram' => [
                                    'connector_code' => 'abrikosoff_telegram',
                                    'connector_type' => 'telegram',
                                ],
                            ],
                            'routes' => [
                                'abrikosoff_telegram:32' => [
                                    'connector_code' => 'abrikosoff_telegram',
                                    'line_id' => '32',
                                    'line_name' => 'Telegram bot local',
                                    'active' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(DoctorBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());

        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_SYNCED, $result['status']);
        $this->assertSame(0, $result['diff_count']);

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_SYNCED, $profile->openlines_route_registry_last_status);
        $this->assertNull($profile->openlines_route_registry_last_error);

        $requests = Http::recorded();
        $this->assertCount(1, $requests);

        /** @var Request $request */
        $request = $requests[0][0];

        $this->assertSame('GET', $request->method());
        $this->assertSame('https://stagecrm.fvds.ru/local/tools/abrikosoff_openlines/route-registry.php?action=snapshot', $request->url());
        $this->assertRegistryRequestSigned($request, $secret, 'GET', 'action=snapshot');
    }

    public function test_doctor_reports_extra_remote_owners_as_portal_audit_warning(): void
    {
        $secret = 'registry-secret-for-extra-owner-test';
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => $secret,
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();

        Http::fake([
            'https://stagecrm.fvds.ru/local/tools/abrikosoff_openlines/route-registry.php?action=snapshot' => Http::response([
                'ok' => true,
                'registry' => [
                    'schema_version' => 1,
                    'portal_domain' => 'stagecrm.fvds.ru',
                    'updated_at' => now()->toIso8601String(),
                    'owners' => [
                        $owner->owner_key => [
                            'owner_profile_key' => $owner->owner_key,
                            'owner_callback_base_url' => $owner->callback_base_url,
                            'routes' => [],
                        ],
                        'stale-local' => [
                            'owner_profile_key' => 'stale-local',
                            'owner_callback_base_url' => 'https://stale.example.test',
                            'routes' => [
                                'abc_telegram:12' => [
                                    'connector_code' => 'abc_telegram',
                                    'line_id' => '12',
                                    'line_name' => 'Stale Telegram',
                                    'active' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(DoctorBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());

        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_SYNCED, $result['status']);
        $this->assertSame(0, $result['diff_count']);
        $this->assertSame(1, $result['warning_count']);
        $this->assertSame(['stale-local'], $result['extra_owners']);
        $this->assertSame(['portal_audit_extra_owners: stale-local'], $result['warnings']);

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_SYNCED, $profile->openlines_route_registry_last_status);
        $this->assertSame('portal_audit_extra_owners: stale-local', $profile->openlines_route_registry_last_error);
    }

    public function test_doctor_marks_local_known_transition_fallback_route_as_fallback_only(): void
    {
        $secret = 'registry-secret-for-fallback-only-test';
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => $secret,
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();
        $channel = Channel::factory()->create([
            'name' => 'Telegram disabled',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '32',
            'line_name' => 'Telegram disabled',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABRIKOSOFF_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_INACTIVE,
        ]);

        Http::fake([
            'https://stagecrm.fvds.ru/local/tools/abrikosoff_openlines/route-registry.php?action=snapshot' => Http::response([
                'ok' => true,
                'transition_fallback_routes' => ['abrikosoff_telegram:32'],
                'registry' => [
                    'schema_version' => 1,
                    'portal_domain' => 'stagecrm.fvds.ru',
                    'updated_at' => now()->toIso8601String(),
                    'owners' => [
                        $owner->owner_key => [
                            'owner_profile_key' => $owner->owner_key,
                            'owner_callback_base_url' => $owner->callback_base_url,
                            'routes' => [],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(DoctorBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());

        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_DIFF, $result['status']);
        $this->assertSame(1, $result['diff_count']);
        $this->assertSame(['fallback_only: abrikosoff_telegram:32'], $result['diffs']);

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_DIFF, $profile->openlines_route_registry_last_status);
        $this->assertSame('fallback_only: abrikosoff_telegram:32', $profile->openlines_route_registry_last_error);
    }

    public function test_doctor_marks_transition_fallback_wildcard_as_diff(): void
    {
        $secret = 'registry-secret-for-fallback-wildcard-test';
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => $secret,
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();

        Http::fake([
            'https://stagecrm.fvds.ru/local/tools/abrikosoff_openlines/route-registry.php?action=snapshot' => Http::response([
                'ok' => true,
                'transition_fallback_routes' => ['*'],
                'registry' => [
                    'schema_version' => 1,
                    'portal_domain' => 'stagecrm.fvds.ru',
                    'updated_at' => now()->toIso8601String(),
                    'owners' => [
                        $owner->owner_key => [
                            'owner_profile_key' => $owner->owner_key,
                            'owner_callback_base_url' => $owner->callback_base_url,
                            'routes' => [],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(DoctorBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());

        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_DIFF, $result['status']);
        $this->assertSame(1, $result['diff_count']);
        $this->assertSame(['fallback_wildcard: *'], $result['diffs']);

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_DIFF, $profile->openlines_route_registry_last_status);
        $this->assertSame('fallback_wildcard: *', $profile->openlines_route_registry_last_error);
    }

    public function test_doctor_marks_unknown_transition_fallback_route_as_diff(): void
    {
        $secret = 'registry-secret-for-fallback-unknown-test';
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => $secret,
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();

        Http::fake([
            'https://stagecrm.fvds.ru/local/tools/abrikosoff_openlines/route-registry.php?action=snapshot' => Http::response([
                'ok' => true,
                'transition_fallback_routes' => ['abc_telegram:32'],
                'registry' => [
                    'schema_version' => 1,
                    'portal_domain' => 'stagecrm.fvds.ru',
                    'updated_at' => now()->toIso8601String(),
                    'owners' => [
                        $owner->owner_key => [
                            'owner_profile_key' => $owner->owner_key,
                            'owner_callback_base_url' => $owner->callback_base_url,
                            'routes' => [],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(DoctorBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());

        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_DIFF, $result['status']);
        $this->assertSame(1, $result['diff_count']);
        $this->assertSame(['fallback_unknown: abc_telegram:32'], $result['diffs']);

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_DIFF, $profile->openlines_route_registry_last_status);
        $this->assertSame('fallback_unknown: abc_telegram:32', $profile->openlines_route_registry_last_error);
    }

    public function test_doctor_marks_extra_remote_owner_duplicate_line_id_as_diff(): void
    {
        $secret = 'registry-secret-for-extra-owner-line-conflict-test';
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => $secret,
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();
        $channel = Channel::factory()->create([
            'name' => 'Telegram staging',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '2',
            'line_name' => 'Telegram staging',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABRIKOSOFF_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        Http::fake([
            'https://stagecrm.fvds.ru/local/tools/abrikosoff_openlines/route-registry.php?action=snapshot' => Http::response([
                'ok' => true,
                'registry' => [
                    'schema_version' => 1,
                    'portal_domain' => 'stagecrm.fvds.ru',
                    'updated_at' => now()->toIso8601String(),
                    'owners' => [
                        $owner->owner_key => [
                            'owner_profile_key' => $owner->owner_key,
                            'owner_callback_base_url' => $owner->callback_base_url,
                            'connectors' => [
                                'abrikosoff_telegram' => [
                                    'connector_code' => 'abrikosoff_telegram',
                                    'connector_type' => 'telegram',
                                ],
                            ],
                            'routes' => [
                                'abrikosoff_telegram:2' => [
                                    'connector_code' => 'abrikosoff_telegram',
                                    'line_id' => '2',
                                    'line_name' => 'Telegram staging',
                                    'active' => true,
                                ],
                            ],
                        ],
                        'stale-local' => [
                            'owner_profile_key' => 'stale-local',
                            'owner_callback_base_url' => 'https://stale.example.test',
                            'routes' => [
                                'abc_max:2' => [
                                    'connector_code' => 'abc_max',
                                    'line_id' => '2',
                                    'line_name' => 'Stale MAX',
                                    'active' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(DoctorBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());

        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_DIFF, $result['status']);
        $this->assertSame(1, $result['diff_count']);
        $this->assertSame(['portal_audit_duplicate_line_id: 2'], $result['diffs']);
        $this->assertSame(1, $result['warning_count']);
        $this->assertSame(['portal_audit_extra_owners: stale-local'], $result['warnings']);

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_DIFF, $profile->openlines_route_registry_last_status);
        $this->assertSame('portal_audit_duplicate_line_id: 2; portal_audit_extra_owners: stale-local', $profile->openlines_route_registry_last_error);
    }

    public function test_publish_marks_profile_failed_on_registry_transport_error(): void
    {
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => 'registry-secret-for-publish-transport-test',
        ]);

        Http::fake(fn () => throw new ConnectionException('Connection timed out.'));

        try {
            app(PublishBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());
            $this->fail('Expected transport error to block registry publish.');
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->assertSame('route_registry_transport_failed', $exception->errorCode);
        }

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_FAILED, $profile->openlines_route_registry_last_status);
        $this->assertSame('route_registry_transport_failed', $profile->openlines_route_registry_last_error);
    }

    public function test_publish_fails_before_http_when_misconfigured_route_has_non_numeric_line_id(): void
    {
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => 'registry-secret-for-invalid-snapshot-line-test',
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();
        $channel = Channel::factory()->create([
            'name' => 'Misconfigured MAX',
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        DB::table('bitrix24_open_line_routes')->insert([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX,
            'connector_code' => 'abc_max',
            'line_id' => 'line-editable',
            'line_name' => 'Misconfigured MAX',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABC_MAX',
            'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake();

        try {
            app(PublishBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());
            $this->fail('Expected non-numeric LINE_ID to block registry publish.');
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->assertSame('route_registry_line_id_invalid', $exception->errorCode);
            $this->assertSame(
                'LINE_ID открытой линии должен состоять из 1–64 цифр.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_FAILED, $profile->openlines_route_registry_last_status);
        $this->assertSame('route_registry_line_id_invalid', $profile->openlines_route_registry_last_error);
    }

    public function test_doctor_marks_profile_failed_on_registry_transport_error(): void
    {
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => 'registry-secret-for-doctor-transport-test',
        ]);

        Http::fake(fn () => throw new ConnectionException('Connection timed out.'));

        try {
            app(DoctorBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());
            $this->fail('Expected transport error to block registry doctor.');
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->assertSame('route_registry_transport_failed', $exception->errorCode);
        }

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_FAILED, $profile->openlines_route_registry_last_status);
        $this->assertSame('route_registry_transport_failed', $profile->openlines_route_registry_last_error);
    }

    public function test_publish_fails_before_http_when_owner_has_duplicate_line_id(): void
    {
        $secret = 'registry-secret-for-duplicate-line-test';
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => $secret,
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();
        foreach (['abrikosoff_telegram', 'abc_telegram'] as $connectorCode) {
            $channel = Channel::factory()->create([
                'name' => 'Telegram '.$connectorCode,
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
            ]);

            Bitrix24OpenLineRoute::query()->create([
                'bitrix24_profile_id' => $profile->id,
                'channel_id' => $channel->id,
                'portal_domain' => $connectorCode === 'abrikosoff_telegram'
                    ? $profile->portal_domain
                    : 'stagecrm-alias.fvds.ru',
                'profile_key' => $profile->profile_key,
                'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
                'connector_code' => $connectorCode,
                'line_id' => '2',
                'line_name' => 'Telegram staging',
                'callback_owner_id' => $owner->id,
                'source_id' => 'ABRIKOSOFF_TELEGRAM',
                'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            ]);
        }

        Http::fake();

        try {
            app(PublishBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());
            $this->fail('Expected duplicate line id to block registry publish.');
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->assertSame('route_registry_duplicate_line_id', $exception->errorCode);
        }

        Http::assertNothingSent();

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_FAILED, $profile->openlines_route_registry_last_status);
        $this->assertSame('route_registry_duplicate_line_id', $profile->openlines_route_registry_last_error);
    }

    public function test_publish_fails_before_http_when_profile_owners_share_line_id(): void
    {
        $secret = 'registry-secret-for-cross-owner-line-test';
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => $secret,
        ]);
        $stagingOwner = $profile->callbackOwners()->firstOrFail();
        $localOwner = Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => 'local-2',
            'display_name' => 'Локалка 2',
            'callback_base_url' => 'https://local-one.example.test/callback',
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);
        $owners = [
            ['owner' => $stagingOwner, 'connector_code' => 'abrikosoff_telegram', 'portal_domain' => $profile->portal_domain],
            ['owner' => $localOwner, 'connector_code' => 'abc_telegram', 'portal_domain' => 'stagecrm-local.fvds.ru'],
        ];

        foreach ($owners as $ownerConfig) {
            $channel = Channel::factory()->create([
                'name' => 'Telegram '.$ownerConfig['connector_code'],
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
            ]);

            Bitrix24OpenLineRoute::query()->create([
                'bitrix24_profile_id' => $profile->id,
                'channel_id' => $channel->id,
                'portal_domain' => $ownerConfig['portal_domain'],
                'profile_key' => $profile->profile_key,
                'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
                'connector_code' => $ownerConfig['connector_code'],
                'line_id' => '2',
                'line_name' => 'Telegram staging',
                'callback_owner_id' => $ownerConfig['owner']->id,
                'source_id' => 'ABRIKOSOFF_TELEGRAM',
                'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            ]);
        }

        Http::fake();

        try {
            app(PublishBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());
            $this->fail('Expected cross-owner duplicate line id to block registry publish.');
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->assertSame('route_registry_duplicate_line_id', $exception->errorCode);
        }

        Http::assertNothingSent();

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_FAILED, $profile->openlines_route_registry_last_status);
        $this->assertSame('route_registry_duplicate_line_id', $profile->openlines_route_registry_last_error);
    }

    public function test_publish_fails_before_http_when_profile_owners_conflict_on_connector_type(): void
    {
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => 'registry-secret-for-connector-conflict-test',
        ]);
        $telegramOwner = $profile->callbackOwners()->firstOrFail();
        $maxOwner = Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => 'local-max',
            'display_name' => 'Локальный MAX',
            'callback_base_url' => 'https://local-max.example.test/callback',
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);

        foreach ([
            [$telegramOwner, Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT, '41'],
            [$maxOwner, Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX, '42'],
        ] as [$owner, $channelType, $lineId]) {
            $channel = Channel::factory()->create([
                'platform' => $channelType === Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX
                    ? Channel::PLATFORM_MAX
                    : Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
            ]);

            Bitrix24OpenLineRoute::query()->create([
                'bitrix24_profile_id' => $profile->id,
                'channel_id' => $channel->id,
                'portal_domain' => $profile->portal_domain,
                'profile_key' => $profile->profile_key,
                'channel_type' => $channelType,
                'connector_code' => 'shared_connector',
                'line_id' => $lineId,
                'line_name' => 'Shared connector '.$lineId,
                'callback_owner_id' => $owner->id,
                'source_id' => 'ABRIKOSOFF_TEST',
                'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            ]);
        }

        Http::fake();

        try {
            app(PublishBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());
            $this->fail('Expected connector type conflict to block registry publish.');
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->assertSame('route_registry_connector_type_conflict', $exception->errorCode);
        }

        Http::assertNothingSent();

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_FAILED, $profile->openlines_route_registry_last_status);
        $this->assertSame('route_registry_connector_type_conflict', $profile->openlines_route_registry_last_error);
    }

    public function test_publish_fails_before_http_when_one_owner_conflicts_on_connector_type(): void
    {
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => 'registry-secret-for-owner-connector-conflict-test',
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();

        foreach ([
            [Channel::PLATFORM_TELEGRAM, Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT, '46'],
            [Channel::PLATFORM_MAX, Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX, '47'],
        ] as [$platform, $channelType, $lineId]) {
            $channel = Channel::factory()->create([
                'platform' => $platform,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
            ]);

            Bitrix24OpenLineRoute::query()->create([
                'bitrix24_profile_id' => $profile->id,
                'channel_id' => $channel->id,
                'portal_domain' => $profile->portal_domain,
                'profile_key' => $profile->profile_key,
                'channel_type' => $channelType,
                'connector_code' => 'same_owner_connector',
                'line_id' => $lineId,
                'line_name' => 'Same owner '.$lineId,
                'callback_owner_id' => $owner->id,
                'source_id' => 'ABRIKOSOFF_TEST',
                'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            ]);
        }

        Http::fake();

        try {
            app(PublishBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());
            $this->fail('Expected same-owner connector type conflict to block registry publish.');
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->assertSame('route_registry_connector_type_conflict', $exception->errorCode);
        }

        Http::assertNothingSent();

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_FAILED, $profile->openlines_route_registry_last_status);
        $this->assertSame('route_registry_connector_type_conflict', $profile->openlines_route_registry_last_error);
    }

    public function test_publish_fails_before_http_for_unsupported_connector_type(): void
    {
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => 'registry-secret-for-invalid-connector-type-test',
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();
        $channel = Channel::factory()->create();

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => 'unsupported',
            'connector_code' => 'unsupported_connector',
            'line_id' => '43',
            'line_name' => 'Unsupported connector',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABRIKOSOFF_TEST',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        Http::fake();

        try {
            app(PublishBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());
            $this->fail('Expected unsupported connector type to block registry publish.');
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->assertSame('route_registry_connector_type_invalid', $exception->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_publish_fails_before_http_for_invalid_connector_code(): void
    {
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => 'registry-secret-for-invalid-connector-code-test',
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();
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
            'connector_code' => 'invalid connector code',
            'line_id' => '44',
            'line_name' => 'Invalid connector code',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABRIKOSOFF_TEST',
            'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
        ]);

        Http::fake();

        try {
            app(PublishBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());
            $this->fail('Expected invalid connector code to block registry publish.');
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->assertSame('route_registry_connector_code_invalid', $exception->errorCode);
        }

        Http::assertNothingSent();

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_FAILED, $profile->openlines_route_registry_last_status);
        $this->assertSame('route_registry_connector_code_invalid', $profile->openlines_route_registry_last_error);
    }

    #[DataProvider('usableOpenLinesRouteStatusProvider')]
    public function test_publish_fails_before_http_for_telegram_account_route(string $status): void
    {
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => 'registry-secret-for-telegram-account-test',
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abrikosoff_telegram_account',
            'line_id' => '43',
            'line_name' => 'Telegram account',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABRIKOSOFF_TELEGRAM_ACCOUNT',
            'status' => $status,
        ]);

        Http::fake();

        try {
            app(PublishBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());
            $this->fail('Expected Telegram account route to block registry publish.');
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->assertSame('route_registry_connector_type_invalid', $exception->errorCode);
        }

        Http::assertNothingSent();

        $profile->refresh();
        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_FAILED, $profile->openlines_route_registry_last_status);
        $this->assertSame('route_registry_connector_type_invalid', $profile->openlines_route_registry_last_error);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function usableOpenLinesRouteStatusProvider(): array
    {
        return [
            'active' => [Bitrix24OpenLineRoute::STATUS_ACTIVE],
            'legacy' => [Bitrix24OpenLineRoute::STATUS_LEGACY],
        ];
    }

    public function test_doctor_reports_missing_connector_catalog_from_remote_snapshot(): void
    {
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
            'openlines_route_registry_secret_encrypted' => 'registry-secret-for-missing-catalog-test',
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX,
            'connector_code' => 'registry_max',
            'line_id' => '44',
            'line_name' => 'Registry MAX',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABRIKOSOFF_MAX',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        Http::fake([
            'https://stagecrm.fvds.ru/local/tools/abrikosoff_openlines/route-registry.php?action=snapshot' => Http::response([
                'ok' => true,
                'registry' => [
                    'schema_version' => 1,
                    'portal_domain' => 'stagecrm.fvds.ru',
                    'updated_at' => now()->toIso8601String(),
                    'owners' => [
                        $owner->owner_key => [
                            'owner_profile_key' => $owner->owner_key,
                            'owner_callback_base_url' => $owner->callback_base_url,
                            'routes' => [
                                'registry_max:44' => [
                                    'connector_code' => 'registry_max',
                                    'line_id' => '44',
                                    'line_name' => 'Registry MAX',
                                    'active' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(DoctorBitrix24OpenLinesRouteRegistryAction::class)->handle($profile->fresh());

        $this->assertSame(Bitrix24Profile::ROUTE_REGISTRY_STATUS_DIFF, $result['status']);
        $this->assertSame(['owner local-1: connectors'], $result['diffs']);
    }

    public function test_superadmin_can_store_registry_secret_write_only_from_profile_settings(): void
    {
        $superadmin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_SUPERADMIN,
        ]);
        $secret = 'registry-secret-from-admin-ui-1234567890';
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'callback_base_url' => 'https://local.example.test/callback',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
        ]);

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->assertSee('OpenLines registry')
            ->assertSee('Не настроен')
            ->set('openLinesRouteRegistryForm.secret', $secret)
            ->call('saveOpenLinesRouteRegistrySecret')
            ->assertSet('openLinesRouteRegistryForm.secret', '')
            ->assertSet('openLinesRouteRegistryErrorMessage', null)
            ->assertSet('openLinesRouteRegistrySuccessMessage', 'Registry secret сохранён. Значение скрыто и повторно не показывается.')
            ->assertDontSee($secret);

        $profile->refresh();
        $this->assertSame($secret, $profile->openlines_route_registry_secret_encrypted);
        $this->assertNotSame(
            $secret,
            DB::table('bitrix24_profiles')
                ->where('id', $profile->id)
                ->value('openlines_route_registry_secret_encrypted'),
        );
    }

    public function test_registry_commands_fail_when_profile_filter_is_ambiguous(): void
    {
        $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'staging',
            'callback_base_url' => 'https://local-one.example.test/callback',
        ]);
        $this->makeProfile([
            'portal_domain' => 'prodcrm.fvds.ru',
            'profile_key' => 'staging',
            'callback_base_url' => 'https://local-two.example.test/callback',
        ]);

        $this->artisan('bitrix24:openlines-routes:doctor --profile=staging')
            ->expectsOutput('Укажите --portal и --profile так, чтобы был выбран ровно один Bitrix24-профиль.')
            ->assertExitCode(1);

        $this->artisan('bitrix24:openlines-routes:publish --profile=staging')
            ->expectsOutput('Укажите --portal и --profile так, чтобы был выбран ровно один Bitrix24-профиль.')
            ->assertExitCode(1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeProfile(array $overrides = []): Bitrix24Profile
    {
        $profile = Bitrix24Profile::query()->create(array_merge([
            'portal_domain' => 'crm.default.test',
            'profile_key' => 'staging',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'callback_base_url' => 'https://project.example.test/'.str()->uuid(),
        ], $overrides));

        Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => Bitrix24CallbackOwner::DEFAULT_LOCAL_OWNER_KEY,
            'display_name' => 'Локалка 1',
            'callback_base_url' => $profile->callback_base_url,
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeConnection(array $overrides = []): Bitrix24Connection
    {
        return Bitrix24Connection::query()->forceCreate(array_merge([
            'portal_domain' => 'crm.default.test',
            'application_name' => 'Abrikosoff Connector',
            'client_id' => 'local.app',
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_encrypted' => 'secret-access-token',
            'refresh_token_encrypted' => 'secret-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'scope' => ['crm'],
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'installed_at' => now()->subHour(),
        ], $overrides));
    }

    private function assertRegistryRequestSigned(
        Request $request,
        string $secret,
        string $method,
        string $query,
    ): void {
        $timestamp = (string) ($request->header('X-ABR-Timestamp')[0] ?? '');
        $requestId = (string) ($request->header('X-ABR-Request-Id')[0] ?? '');
        $signature = (string) ($request->header('X-ABR-Signature')[0] ?? '');
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $canonical = implode("\n", [
            $method,
            $path,
            $query,
            $timestamp,
            $requestId,
            hash('sha256', $request->body()),
        ]);

        $this->assertNotSame('', $timestamp);
        $this->assertNotSame('', $requestId);
        $this->assertSame('sha256='.hash_hmac('sha256', $canonical, $secret), $signature);
    }
}
