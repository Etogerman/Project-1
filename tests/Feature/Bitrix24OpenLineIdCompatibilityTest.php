<?php

namespace Tests\Feature;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use App\Services\Bitrix24\Bitrix24OpenLineIdCompatibilityException;
use App\Services\Bitrix24\Bitrix24OpenLineIdCompatibilityService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Bitrix24OpenLineIdCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $storageDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDirectory = sys_get_temp_dir()
            .'/b24-line-id-compatibility-'.bin2hex(random_bytes(8));
        mkdir($this->storageDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storageDirectory);

        parent::tearDown();
    }

    public function test_014_is_migrated_to_14_with_backups_and_verifiable_artifact(): void
    {
        $routeId = $this->insertNonCanonicalDatabaseRoute('014');
        $registry = $this->registry([
            'local-1' => $this->owner('local-1', '014'),
        ]);
        $leases = [
            '014' => $this->lease(
                'local-1',
                '014',
                now()->subMinute()->timestamp,
            ),
        ];
        $this->writeJson('route_registry.json', $registry);
        $this->writeJson('route_registry.previous.json', $registry);
        $this->writeJson('route_registry_line_leases.json', $leases);
        $artifactPath = $this->storageDirectory.'/artifacts/line-id.json';
        mkdir(dirname($artifactPath), 0700, true);

        $service = app(Bitrix24OpenLineIdCompatibilityService::class);
        $preflight = $service->preflight($this->storageDirectory);

        $this->assertTrue($preflight['ready']);
        $this->assertCount(4, $preflight['migrations']);
        $this->assertSame([], $preflight['collisions']);

        $artifact = $service->migrate($this->storageDirectory, $artifactPath);

        $this->assertTrue($artifact['ready']);
        $this->assertTrue($artifact['migration_applied']);
        $this->assertFileExists($artifactPath);
        $this->assertSame(hash_file('sha256', $artifactPath), $artifact['artifact_sha256']);
        $this->assertCount(3, $artifact['backup_files']);
        $this->assertPrivateFile($artifactPath);

        foreach ($artifact['backup_files'] as $backupPath) {
            $this->assertPrivateFile($backupPath);
        }

        foreach ([
            'route_registry.json',
            'route_registry.previous.json',
            'route_registry_line_leases.json',
        ] as $fileName) {
            $this->assertPrivateFile($this->storageDirectory.'/'.$fileName);
        }

        $current = $this->readJson('route_registry.json');
        $previous = $this->readJson('route_registry.previous.json');
        $migratedLeases = $this->readJson('route_registry_line_leases.json');

        $this->assertArrayHasKey('abrikosoff_telegram:14', $current['owners']['local-1']['routes']);
        $this->assertArrayHasKey('abrikosoff_telegram:14', $previous['owners']['local-1']['routes']);
        $this->assertArrayHasKey('14', $migratedLeases);
        $this->assertSame('14', $migratedLeases['14']['line_id']);
        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'id' => $routeId,
            'line_id' => '14',
            'line_owner_key' => 'stagecrm.fvds.ru#14',
        ]);
        $this->assertSame([], $service->preflight($this->storageDirectory)['migrations']);
    }

    public function test_whitespace_line_alias_is_explicitly_migrated_instead_of_false_ready(): void
    {
        $routeId = $this->insertNonCanonicalDatabaseRoute(' 14 ');
        $registry = $this->registry([
            'local-1' => $this->owner('local-1', ' 14 '),
        ]);
        $leases = [
            ' 14 ' => $this->lease(
                'local-1',
                ' 14 ',
                now()->subMinute()->timestamp,
            ),
        ];
        $this->writeJson('route_registry.json', $registry);
        $this->writeJson('route_registry.previous.json', $registry);
        $this->writeJson('route_registry_line_leases.json', $leases);
        $artifactPath = $this->storageDirectory.'/whitespace-line-id.json';
        $service = app(Bitrix24OpenLineIdCompatibilityService::class);

        $preflight = $service->preflight($this->storageDirectory);

        $this->assertTrue($preflight['ready']);
        $this->assertCount(4, $preflight['migrations']);

        $artifact = $service->migrate($this->storageDirectory, $artifactPath);

        $this->assertTrue($artifact['migration_applied']);
        $this->assertArrayHasKey(
            'abrikosoff_telegram:14',
            $this->readJson('route_registry.json')['owners']['local-1']['routes'],
        );
        $this->assertArrayHasKey('14', $this->readJson('route_registry_line_leases.json'));
        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'id' => $routeId,
            'line_id' => '14',
            'line_owner_key' => 'stagecrm.fvds.ru#14',
        ]);
        $this->assertSame([], $service->preflight($this->storageDirectory)['migrations']);
    }

    public function test_active_lease_blocks_line_id_migration_without_data_loss(): void
    {
        $routeId = $this->insertNonCanonicalDatabaseRoute('014');
        $this->writeJson('route_registry.json', $this->registry([]));
        $leases = [
            '014' => $this->lease(
                'local-1',
                '014',
                now()->addMinutes(5)->timestamp,
            ),
        ];
        $this->writeJson('route_registry_line_leases.json', $leases);
        $leasePath = $this->storageDirectory.'/route_registry_line_leases.json';
        $leasesBefore = (string) file_get_contents($leasePath);
        $artifactPath = $this->storageDirectory.'/active-lease-block.json';
        $service = app(Bitrix24OpenLineIdCompatibilityService::class);

        $preflight = $service->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertSame([
            [
                'source' => 'active_leases',
                'locator' => 'lease:014',
                'portal' => 'stagecrm.fvds.ru',
                'line_id' => '014',
                'canonical_line_id' => '14',
                'connector_code' => 'abrikosoff_telegram',
                'lease_scope' => 'line_runtime',
                'expires_at' => $leases['014']['expires_at'],
                'matched_resources' => [
                    'line:stagecrm.fvds.ru#14',
                ],
            ],
        ], $preflight['active_lease_blocks']);

        try {
            $service->migrate($this->storageDirectory, $artifactPath);
            $this->fail('Active lease must block LINE_ID migration.');
        } catch (Bitrix24OpenLineIdCompatibilityException $exception) {
            $this->assertSame('openlines_line_id_compatibility_blocked', $exception->errorCode);
        }

        $this->assertSame($leasesBefore, file_get_contents($leasePath));
        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'id' => $routeId,
            'line_id' => '014',
        ]);
        $this->assertFileDoesNotExist($artifactPath);
        $this->assertSame([], glob($this->storageDirectory.'/*.backup.*') ?: []);
    }

    public function test_connector_registration_lease_blocks_same_connector_on_another_line(): void
    {
        $routeId = $this->insertNonCanonicalDatabaseRoute('014');
        $this->writeJson('route_registry.json', $this->registry([]));
        $leases = [
            '15' => $this->lease(
                ownerKey: 'another-owner',
                lineId: '15',
                scope: 'connector_registration',
            ),
        ];
        $this->writeJson('route_registry_line_leases.json', $leases);
        $artifactPath = $this->storageDirectory.'/connector-registration-block.json';
        $service = app(Bitrix24OpenLineIdCompatibilityService::class);

        $preflight = $service->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertSame([
            [
                'source' => 'active_leases',
                'locator' => 'lease:15',
                'portal' => 'stagecrm.fvds.ru',
                'line_id' => '15',
                'canonical_line_id' => '15',
                'connector_code' => 'abrikosoff_telegram',
                'lease_scope' => 'connector_registration',
                'expires_at' => $leases['15']['expires_at'],
                'matched_resources' => [
                    'connector:stagecrm.fvds.ru#abrikosoff_telegram',
                ],
            ],
        ], $preflight['active_lease_blocks']);

        $artifact = $service->preflightArtifact($this->storageDirectory, $artifactPath);

        $this->assertFalse($artifact['ready']);
        $this->assertSame($preflight['active_lease_blocks'], $artifact['active_lease_blocks']);
        $this->assertFileExists($artifactPath);

        try {
            $service->migrate(
                $this->storageDirectory,
                $this->storageDirectory.'/blocked-migration.json',
            );
            $this->fail('Connector registration lease must block connector migration.');
        } catch (Bitrix24OpenLineIdCompatibilityException $exception) {
            $this->assertSame('openlines_line_id_compatibility_blocked', $exception->errorCode);
        }

        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'id' => $routeId,
            'line_id' => '014',
        ]);
        $this->assertFileDoesNotExist($this->storageDirectory.'/blocked-migration.json');
    }

    public function test_legacy_missing_or_empty_scope_is_connector_registration(): void
    {
        $this->insertNonCanonicalDatabaseRoute('014');
        $this->writeJson('route_registry.json', $this->registry([]));
        $service = app(Bitrix24OpenLineIdCompatibilityService::class);

        foreach (['missing', 'empty'] as $variant) {
            $lease = $this->lease('another-owner', '15');

            if ($variant === 'missing') {
                unset($lease['lease_scope']);
            } else {
                $lease['lease_scope'] = '';
            }

            $this->writeJson('route_registry_line_leases.json', ['15' => $lease]);
            $preflight = $service->preflight($this->storageDirectory);

            $this->assertFalse($preflight['ready'], $variant);
            $this->assertSame(
                'connector_registration',
                $preflight['active_lease_blocks'][0]['lease_scope'],
                $variant,
            );
            $this->assertSame([
                'connector:stagecrm.fvds.ru#abrikosoff_telegram',
            ], $preflight['active_lease_blocks'][0]['matched_resources'], $variant);
        }
    }

    public function test_line_runtime_lease_on_another_line_does_not_block_connector_migration(): void
    {
        $this->insertNonCanonicalDatabaseRoute('014');
        $this->writeJson('route_registry.json', $this->registry([]));
        $this->writeJson('route_registry_line_leases.json', [
            '15' => $this->lease('another-owner', '15', scope: 'line_runtime'),
        ]);

        $preflight = app(Bitrix24OpenLineIdCompatibilityService::class)
            ->preflight($this->storageDirectory);

        $this->assertTrue($preflight['ready']);
        $this->assertCount(1, $preflight['migrations']);
        $this->assertSame([], $preflight['active_lease_blocks']);
    }

    public function test_same_connector_on_another_portal_does_not_block_migration(): void
    {
        $this->insertNonCanonicalDatabaseRoute('014', portal: 'portal-b.example.test');
        $this->writeJson(
            'route_registry.json',
            $this->registry([], 'portal-a.example.test'),
        );
        $this->writeJson('route_registry_line_leases.json', [
            '15' => $this->lease(
                ownerKey: 'another-owner',
                lineId: '15',
                scope: 'connector_registration',
            ),
        ]);

        $preflight = app(Bitrix24OpenLineIdCompatibilityService::class)
            ->preflight($this->storageDirectory);

        $this->assertTrue($preflight['ready']);
        $this->assertSame([], $preflight['active_lease_blocks']);
    }

    public function test_connector_registration_lease_matching_line_and_connector_has_one_additive_block(): void
    {
        $this->insertNonCanonicalDatabaseRoute('014');
        $this->writeJson('route_registry.json', $this->registry([]));
        $lease = $this->lease(
            ownerKey: 'another-owner',
            lineId: '014',
            scope: 'connector_registration',
        );
        $this->writeJson('route_registry_line_leases.json', ['014' => $lease]);

        $preflight = app(Bitrix24OpenLineIdCompatibilityService::class)
            ->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertCount(1, $preflight['active_lease_blocks']);
        $this->assertSame([
            'connector:stagecrm.fvds.ru#abrikosoff_telegram',
            'line:stagecrm.fvds.ru#14',
        ], $preflight['active_lease_blocks'][0]['matched_resources']);
    }

    public function test_lease_expiring_at_snapshot_is_inactive_and_entries_are_time_stable(): void
    {
        $snapshotAt = now()->startOfSecond();
        $this->travelTo($snapshotAt);
        $this->insertNonCanonicalDatabaseRoute('014');
        $this->writeJson('route_registry.json', $this->registry([]));
        $this->writeJson('route_registry_line_leases.json', [
            '014' => $this->lease(
                ownerKey: 'local-1',
                lineId: '014',
                expiresAt: $snapshotAt->timestamp + 1,
            ),
        ]);
        $service = app(Bitrix24OpenLineIdCompatibilityService::class);

        $beforeExpiry = $service->preflight($this->storageDirectory);
        $this->travelTo($snapshotAt->copy()->addSecond());
        $atExpiry = $service->preflight($this->storageDirectory);
        $this->travelBack();

        $this->assertFalse($beforeExpiry['ready']);
        $this->assertCount(1, $beforeExpiry['active_lease_blocks']);
        $this->assertTrue($atExpiry['ready']);
        $this->assertSame([], $atExpiry['active_lease_blocks']);
        $this->assertSame($beforeExpiry['entries'], $atExpiry['entries']);
        $this->assertSame($beforeExpiry['source_hashes'], $atExpiry['source_hashes']);
        $this->assertArrayNotHasKey(
            'lease_active',
            collect($atExpiry['entries'])->firstWhere('source', 'active_leases'),
        );
    }

    public function test_invalid_registry_portal_fails_closed(): void
    {
        $this->writeJson('route_registry.json', $this->registry([], '...'));

        $preflight = app(Bitrix24OpenLineIdCompatibilityService::class)
            ->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertContains([
            'source' => 'current_registry',
            'locator' => 'portal_domain',
            'line_id' => 'invalid_registry_portal',
        ], $preflight['invalid']);
    }

    public function test_mismatched_registry_portals_fail_closed(): void
    {
        $this->writeJson(
            'route_registry.json',
            $this->registry([], 'portal-a.example.test'),
        );
        $this->writeJson(
            'route_registry.previous.json',
            $this->registry([], 'portal-b.example.test'),
        );

        $preflight = app(Bitrix24OpenLineIdCompatibilityService::class)
            ->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertContains([
            'source' => 'registry_portal',
            'locator' => 'current_registry/previous_registry',
            'line_id' => 'registry_portal_mismatch',
        ], $preflight['invalid']);
    }

    public function test_nonempty_lease_file_without_registry_portal_fails_closed(): void
    {
        $this->writeJson('route_registry_line_leases.json', [
            '14' => $this->lease('local-1', '14'),
        ]);

        $preflight = app(Bitrix24OpenLineIdCompatibilityService::class)
            ->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertContains([
            'source' => 'active_leases',
            'locator' => 'portal_domain',
            'line_id' => 'lease_portal_unresolved',
        ], $preflight['invalid']);
    }

    public function test_expired_lease_still_participates_in_structural_collision_detection(): void
    {
        $this->insertNonCanonicalDatabaseRoute('014');
        $this->writeJson('route_registry.json', $this->registry([]));
        $this->writeJson('route_registry_line_leases.json', [
            '014' => $this->lease(
                ownerKey: 'another-owner',
                lineId: '014',
                expiresAt: now()->subMinute()->timestamp,
            ),
        ]);

        $preflight = app(Bitrix24OpenLineIdCompatibilityService::class)
            ->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertCount(1, $preflight['collisions']);
        $this->assertSame('stagecrm.fvds.ru#14', $preflight['collisions'][0]['resource']);
        $this->assertSame([], $preflight['active_lease_blocks']);
    }

    public function test_lease_only_migration_does_not_create_connector_resource_block(): void
    {
        $this->writeJson('route_registry.json', $this->registry([]));
        $this->writeJson('route_registry_line_leases.json', [
            '014' => $this->lease(
                ownerKey: 'local-1',
                lineId: '014',
                expiresAt: now()->subMinute()->timestamp,
            ),
            '15' => $this->lease(
                ownerKey: 'another-owner',
                lineId: '15',
                scope: 'connector_registration',
            ),
        ]);

        $preflight = app(Bitrix24OpenLineIdCompatibilityService::class)
            ->preflight($this->storageDirectory);

        $this->assertTrue($preflight['ready']);
        $this->assertCount(1, $preflight['migrations']);
        $this->assertSame('active_leases', $preflight['migrations'][0]['source']);
        $this->assertSame([], $preflight['active_lease_blocks']);
    }

    public function test_callback_and_identity_normalization_avoids_false_collision(): void
    {
        $this->writeJson('route_registry.json', $this->registry([
            'local-1' => $this->owner('local-1', '014'),
        ]));
        $lease = $this->lease(
            ownerKey: ' local-1 ',
            lineId: '014',
            expiresAt: now()->subMinute()->timestamp,
            connectorCode: ' abrikosoff_telegram ',
            callbackBaseUrl: 'HTTPS://LOCAL-1.EXAMPLE.TEST/callbacks/bitrix24/openlines/',
            connectorType: ' telegram ',
        );
        $this->writeJson('route_registry_line_leases.json', ['014' => $lease]);

        $preflight = app(Bitrix24OpenLineIdCompatibilityService::class)
            ->preflight($this->storageDirectory);

        $this->assertTrue($preflight['ready']);
        $this->assertSame([], $preflight['collisions']);
        $this->assertSame(
            $preflight['entries'][0]['identity'],
            collect($preflight['entries'])->firstWhere('source', 'active_leases')['identity'],
        );
    }

    public function test_legacy_profile_owner_selection_uses_normalized_callback_identity(): void
    {
        $this->insertLegacyProfile(
            telegramLineId: '014',
            maxLineId: null,
            profileCallbackBaseUrl: 'HTTPS://LOCAL-1.EXAMPLE.TEST/callbacks/bitrix24/openlines/',
            ownerCallbackBaseUrl: 'https://local-1.example.test',
        );
        $this->writeJson('route_registry.json', $this->registry([
            'local-1' => $this->owner('local-1', '014'),
        ]));

        $preflight = app(Bitrix24OpenLineIdCompatibilityService::class)
            ->preflight($this->storageDirectory);

        $this->assertTrue($preflight['ready']);
        $this->assertSame([], $preflight['collisions']);
        $this->assertSame(
            collect($preflight['entries'])->firstWhere('source', 'current_registry')['identity'],
            collect($preflight['entries'])->firstWhere('source', 'profile_database')['identity'],
        );
    }

    public function test_invalid_database_portal_and_connector_fail_closed(): void
    {
        $routeId = $this->insertNonCanonicalDatabaseRoute('014');
        DB::table('bitrix24_open_line_routes')
            ->where('id', $routeId)
            ->update([
                'portal_domain' => '...',
                'connector_code' => 'invalid connector',
            ]);

        $preflight = app(Bitrix24OpenLineIdCompatibilityService::class)
            ->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertContains([
            'source' => 'database',
            'locator' => 'route:'.$routeId,
            'line_id' => 'invalid_portal',
        ], $preflight['invalid']);
        $this->assertContains([
            'source' => 'database',
            'locator' => 'route:'.$routeId,
            'line_id' => 'invalid_connector_code',
        ], $preflight['invalid']);
    }

    public function test_read_only_preflight_can_write_verifiable_artifact_without_mutation(): void
    {
        $registry = $this->registry([
            'local-1' => $this->owner('local-1', '14'),
        ]);
        $this->writeJson('route_registry.json', $registry);
        $before = (string) file_get_contents($this->storageDirectory.'/route_registry.json');
        $artifactPath = $this->storageDirectory.'/preflight.json';

        $artifact = app(Bitrix24OpenLineIdCompatibilityService::class)
            ->preflightArtifact($this->storageDirectory, $artifactPath);

        $this->assertTrue($artifact['ready']);
        $this->assertFalse($artifact['migration_applied']);
        $this->assertSame([], $artifact['migrations']);
        $this->assertSame($before, file_get_contents($this->storageDirectory.'/route_registry.json'));
        $this->assertFileExists($artifactPath);
        $this->assertSame(hash_file('sha256', $artifactPath), $artifact['artifact_sha256']);
        $this->assertPrivateFile($artifactPath);
        $this->assertSame([], glob($this->storageDirectory.'/*.backup.*') ?: []);
    }

    public function test_artifact_cannot_overwrite_registry_runtime_or_lock_files(): void
    {
        $registry = $this->registry([
            'local-1' => $this->owner('local-1', '14'),
        ]);
        $this->writeJson('route_registry.json', $registry);
        $service = app(Bitrix24OpenLineIdCompatibilityService::class);

        foreach ([
            'route_registry.json',
            'route_registry.lock',
            'route_registry_replay_cache.json',
        ] as $fileName) {
            $path = $this->storageDirectory.'/'.$fileName;

            if (! is_file($path)) {
                file_put_contents($path, 'protected-'.$fileName);
            }

            $before = (string) file_get_contents($path);

            try {
                $service->preflightArtifact($this->storageDirectory, $path);
                $this->fail($fileName.' must not be accepted as an artifact path.');
            } catch (Bitrix24OpenLineIdCompatibilityException $exception) {
                $this->assertSame('openlines_line_id_artifact_path_conflict', $exception->errorCode);
            }

            $this->assertSame($before, file_get_contents($path));
        }
    }

    public function test_migration_rejects_artifact_path_that_would_overwrite_active_leases(): void
    {
        $routeId = $this->insertNonCanonicalDatabaseRoute('014');
        $leases = [
            '014' => $this->lease('local-1', '014'),
        ];
        $this->writeJson('route_registry_line_leases.json', $leases);
        $leasePath = $this->storageDirectory.'/route_registry_line_leases.json';
        $before = (string) file_get_contents($leasePath);

        try {
            app(Bitrix24OpenLineIdCompatibilityService::class)
                ->migrate($this->storageDirectory, $leasePath);
            $this->fail('Active leases must not be accepted as an artifact path.');
        } catch (Bitrix24OpenLineIdCompatibilityException $exception) {
            $this->assertSame('openlines_line_id_artifact_path_conflict', $exception->errorCode);
        }

        $this->assertSame($before, file_get_contents($leasePath));
        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'id' => $routeId,
            'line_id' => '014',
        ]);
        $this->assertSame([], glob($this->storageDirectory.'/*.backup.*') ?: []);
    }

    public function test_invalid_database_line_id_blocks_preflight_and_migration(): void
    {
        $routeId = $this->insertNonCanonicalDatabaseRoute('not-a-line-id');
        $artifactPath = $this->storageDirectory.'/invalid-database-line-id.json';
        $service = app(Bitrix24OpenLineIdCompatibilityService::class);

        $preflight = $service->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertSame([
            [
                'source' => 'database',
                'locator' => 'route:'.$routeId,
                'line_id' => 'not-a-line-id',
            ],
        ], $preflight['invalid']);

        try {
            $service->migrate($this->storageDirectory, $artifactPath);
            $this->fail('Invalid database LINE_ID must block compatibility migration.');
        } catch (Bitrix24OpenLineIdCompatibilityException $exception) {
            $this->assertSame('openlines_line_id_compatibility_blocked', $exception->errorCode);
        }

        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'id' => $routeId,
            'line_id' => 'not-a-line-id',
        ]);
        $this->assertFileDoesNotExist($artifactPath);
    }

    public function test_misconfigured_014_keeps_ownership_claim_without_recreating_runtime_owner_key(): void
    {
        $routeId = $this->insertNonCanonicalDatabaseRoute(
            '014',
            Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
        );
        $artifactPath = $this->storageDirectory.'/misconfigured-line-id.json';

        $artifact = app(Bitrix24OpenLineIdCompatibilityService::class)
            ->migrate($this->storageDirectory, $artifactPath);

        $this->assertTrue($artifact['ready']);
        $this->assertTrue($artifact['migration_applied']);
        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'id' => $routeId,
            'line_id' => '14',
            'line_owner_key' => null,
            'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
        ]);
    }

    public function test_014_and_14_collision_blocks_migration_without_changing_sources(): void
    {
        $registry = $this->registry([
            'local-1' => $this->owner('local-1', '014'),
            'staging' => $this->owner('staging', '14'),
        ]);
        $this->writeJson('route_registry.json', $registry);
        $before = (string) file_get_contents($this->storageDirectory.'/route_registry.json');

        $service = app(Bitrix24OpenLineIdCompatibilityService::class);
        $preflight = $service->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertCount(1, $preflight['collisions']);

        try {
            $service->migrate(
                $this->storageDirectory,
                $this->storageDirectory.'/line-id-artifact.json',
            );
            $this->fail('Collision must block migration.');
        } catch (Bitrix24OpenLineIdCompatibilityException $exception) {
            $this->assertSame('openlines_line_id_compatibility_blocked', $exception->errorCode);
        }

        $this->assertSame(
            $before,
            file_get_contents($this->storageDirectory.'/route_registry.json'),
        );
        $this->assertFileDoesNotExist($this->storageDirectory.'/line-id-artifact.json');
        $this->assertSame([], glob($this->storageDirectory.'/*.backup.*') ?: []);
    }

    public function test_legacy_profile_line_id_is_included_in_preflight_hash_and_migration(): void
    {
        $profile = $this->insertLegacyProfile(
            telegramLineId: '014',
            maxLineId: null,
        );
        $artifactPath = $this->storageDirectory.'/legacy-profile-line-id.json';
        $service = app(Bitrix24OpenLineIdCompatibilityService::class);

        $preflight = $service->preflight($this->storageDirectory);

        $this->assertTrue($preflight['ready']);
        $this->assertSame(1, $preflight['source_counts']['profile_database']);
        $this->assertSame('profile_database', $preflight['migrations'][0]['source']);
        $this->assertSame('telegram_line_id', $preflight['migrations'][0]['record_field']);
        $this->assertSame('014', $preflight['migrations'][0]['from']);
        $this->assertSame('14', $preflight['migrations'][0]['to']);
        $this->assertIsString($preflight['source_hashes']['profile_database']);

        $artifact = $service->migrate($this->storageDirectory, $artifactPath);

        $this->assertTrue($artifact['ready']);
        $this->assertTrue($artifact['migration_applied']);
        $this->assertSame(
            $preflight['source_hashes']['profile_database'],
            $artifact['preflight_source_hashes']['profile_database'],
        );
        $this->assertNotSame(
            $preflight['source_hashes']['profile_database'],
            $artifact['source_hashes']['profile_database'],
        );
        $this->assertSame('14', $profile->fresh()->telegram_line_id);
        $this->assertSame([], $service->preflight($this->storageDirectory)['migrations']);
    }

    public function test_invalid_legacy_profile_line_id_blocks_preflight_and_migration(): void
    {
        $profile = $this->insertLegacyProfile(
            telegramLineId: 'not-a-line-id',
            maxLineId: null,
        );
        $artifactPath = $this->storageDirectory.'/invalid-legacy-profile-line-id.json';
        $service = app(Bitrix24OpenLineIdCompatibilityService::class);

        $preflight = $service->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertSame([
            [
                'source' => 'profile_database',
                'locator' => 'profile:'.$profile->id.'/telegram_line_id',
                'line_id' => 'not-a-line-id',
            ],
        ], $preflight['invalid']);

        try {
            $service->migrate($this->storageDirectory, $artifactPath);
            $this->fail('Invalid legacy profile LINE_ID must block compatibility migration.');
        } catch (Bitrix24OpenLineIdCompatibilityException $exception) {
            $this->assertSame('openlines_line_id_compatibility_blocked', $exception->errorCode);
        }

        $this->assertSame('not-a-line-id', $profile->fresh()->telegram_line_id);
        $this->assertFileDoesNotExist($artifactPath);
    }

    public function test_legacy_profile_014_and_14_collision_blocks_migration(): void
    {
        $profile = $this->insertLegacyProfile(
            telegramLineId: '014',
            maxLineId: '14',
        );
        $artifactPath = $this->storageDirectory.'/legacy-profile-collision.json';
        $service = app(Bitrix24OpenLineIdCompatibilityService::class);

        $preflight = $service->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertSame(2, $preflight['source_counts']['profile_database']);
        $this->assertCount(1, $preflight['collisions']);
        $this->assertSame('stagecrm.fvds.ru#14', $preflight['collisions'][0]['resource']);

        try {
            $service->migrate($this->storageDirectory, $artifactPath);
            $this->fail('Legacy profile LINE_ID collision must block compatibility migration.');
        } catch (Bitrix24OpenLineIdCompatibilityException $exception) {
            $this->assertSame('openlines_line_id_compatibility_blocked', $exception->errorCode);
        }

        $profile->refresh();
        $this->assertSame('014', $profile->telegram_line_id);
        $this->assertSame('14', $profile->max_line_id);
        $this->assertFileDoesNotExist($artifactPath);
    }

    public function test_malformed_registry_and_lease_records_block_migration_without_data_loss(): void
    {
        $owner = $this->owner('local-1', '14');
        $owner['routes']['broken-route'] = 'not-an-array';
        $owner['routes']['broken-line-id'] = [
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => ['14'],
            'line_name' => 'Broken line',
            'active' => true,
        ];
        $registry = $this->registry([
            'local-1' => $owner,
        ]);
        $leases = [
            '14' => $this->lease('local-1', '14'),
            'broken-lease' => 'not-an-array',
            'broken-line-id' => [
                ...$this->lease('local-1', '14'),
                'line_id' => ['14'],
            ],
        ];
        $this->writeJson('route_registry.json', $registry);
        $this->writeJson('route_registry_line_leases.json', $leases);
        $registryBefore = (string) file_get_contents(
            $this->storageDirectory.'/route_registry.json',
        );
        $leasesBefore = (string) file_get_contents(
            $this->storageDirectory.'/route_registry_line_leases.json',
        );
        $artifactPath = $this->storageDirectory.'/malformed-source.json';
        $service = app(Bitrix24OpenLineIdCompatibilityService::class);

        $preflight = $service->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertContains([
            'source' => 'current_registry',
            'locator' => 'owner:local-1/route:broken-route',
            'line_id' => 'invalid_route',
        ], $preflight['invalid']);
        $this->assertContains([
            'source' => 'active_leases',
            'locator' => 'lease:broken-lease',
            'line_id' => 'invalid_lease',
        ], $preflight['invalid']);
        $this->assertContains([
            'source' => 'current_registry',
            'locator' => 'owner:local-1/route:broken-line-id',
            'line_id' => 'invalid_route',
        ], $preflight['invalid']);
        $this->assertContains([
            'source' => 'active_leases',
            'locator' => 'lease:broken-line-id',
            'line_id' => 'invalid_lease',
        ], $preflight['invalid']);

        try {
            $service->migrate($this->storageDirectory, $artifactPath);
            $this->fail('Malformed sources must block compatibility migration.');
        } catch (Bitrix24OpenLineIdCompatibilityException $exception) {
            $this->assertSame('openlines_line_id_compatibility_blocked', $exception->errorCode);
        }

        $this->assertSame(
            $registryBefore,
            file_get_contents($this->storageDirectory.'/route_registry.json'),
        );
        $this->assertSame(
            $leasesBefore,
            file_get_contents($this->storageDirectory.'/route_registry_line_leases.json'),
        );
        $this->assertFileDoesNotExist($artifactPath);
    }

    public function test_lease_preflight_matches_runtime_reader_schema(): void
    {
        $this->writeJson('route_registry.json', $this->registry([]));
        $leases = [
            '14' => [
                ...$this->lease('local-1', '14'),
                'owner_profile_key' => 'invalid owner',
            ],
            '15' => [
                ...$this->lease('local-1', '15'),
                'owner_callback_base_url' => '/',
            ],
            '16' => [
                ...$this->lease('local-1', '16'),
                'connector_code' => 'invalid connector',
            ],
            '17' => [
                ...$this->lease('local-1', '17'),
                'connector_type' => 'whatsapp',
            ],
            '18' => [
                ...$this->lease('local-1', '18'),
                'lease_scope' => 'unknown',
            ],
            '19' => [
                ...$this->lease('local-1', '19'),
                'token_hash' => 'invalid',
            ],
            '20' => [
                ...$this->lease('local-1', '20'),
                'expires_at' => (string) now()->addMinutes(5)->timestamp,
            ],
            '21' => [
                ...$this->lease('local-1', '21'),
                'line_id' => '22',
            ],
        ];
        $this->writeJson('route_registry_line_leases.json', $leases);
        $leasePath = $this->storageDirectory.'/route_registry_line_leases.json';
        $before = (string) file_get_contents($leasePath);
        $artifactPath = $this->storageDirectory.'/invalid-lease-schema.json';
        $service = app(Bitrix24OpenLineIdCompatibilityService::class);

        $preflight = $service->preflight($this->storageDirectory);

        $this->assertFalse($preflight['ready']);
        $this->assertCount(count($leases), $preflight['invalid']);

        foreach (array_keys($leases) as $lineId) {
            $this->assertContains([
                'source' => 'active_leases',
                'locator' => 'lease:'.$lineId,
                'line_id' => 'invalid_lease',
            ], $preflight['invalid']);
        }

        try {
            $service->migrate($this->storageDirectory, $artifactPath);
            $this->fail('Lease records rejected by runtime reader must block migration.');
        } catch (Bitrix24OpenLineIdCompatibilityException $exception) {
            $this->assertSame('openlines_line_id_compatibility_blocked', $exception->errorCode);
        }

        $this->assertSame($before, file_get_contents($leasePath));
        $this->assertFileDoesNotExist($artifactPath);
    }

    public function test_lease_preflight_enforces_runtime_reader_limits(): void
    {
        $this->writeJson('route_registry.json', $this->registry([]));
        $leases = [];

        for ($lineId = 1; $lineId <= 1001; $lineId++) {
            $leases[(string) $lineId] = $this->lease('local-1', (string) $lineId);
        }

        $this->writeJson('route_registry_line_leases.json', $leases);
        $service = app(Bitrix24OpenLineIdCompatibilityService::class);
        $tooManyLeases = $service->preflight($this->storageDirectory);
        $resolvedStorageDirectory = realpath($this->storageDirectory);

        $this->assertIsString($resolvedStorageDirectory);
        $this->assertFalse($tooManyLeases['ready']);
        $this->assertContains([
            'source' => 'active_leases',
            'locator' => $resolvedStorageDirectory.'/route_registry_line_leases.json',
            'line_id' => 'too_many_leases',
        ], $tooManyLeases['invalid']);

        $oversizedLease = $this->lease('local-1', '14');
        $oversizedLease['padding'] = str_repeat('x', 1048576);
        $this->writeJson('route_registry_line_leases.json', [
            '14' => $oversizedLease,
        ]);
        $oversizedFile = $service->preflight($this->storageDirectory);

        $this->assertFalse($oversizedFile['ready']);
        $this->assertSame([
            [
                'source' => 'active_leases',
                'locator' => $resolvedStorageDirectory.'/route_registry_line_leases.json',
                'line_id' => 'invalid_lease_file',
            ],
        ], $oversizedFile['invalid']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $owners
     * @return array<string, mixed>
     */
    private function registry(
        array $owners,
        string $portal = 'stagecrm.fvds.ru',
    ): array {
        return [
            'schema_version' => 1,
            'portal_domain' => $portal,
            'updated_at' => now()->toAtomString(),
            'owners' => $owners,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function owner(string $ownerKey, string $lineId): array
    {
        return [
            'owner_profile_key' => $ownerKey,
            'owner_callback_base_url' => 'https://'.$ownerKey.'.example.test',
            'connectors' => [
                'abrikosoff_telegram' => [
                    'connector_code' => 'abrikosoff_telegram',
                    'connector_type' => 'telegram',
                ],
            ],
            'routes' => [
                'abrikosoff_telegram:'.$lineId => [
                    'connector_code' => 'abrikosoff_telegram',
                    'line_id' => $lineId,
                    'line_name' => 'Telegram',
                    'active' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lease(
        string $ownerKey,
        string $lineId,
        ?int $expiresAt = null,
        string $scope = 'line_runtime',
        string $connectorCode = 'abrikosoff_telegram',
        ?string $callbackBaseUrl = null,
        string $connectorType = 'telegram',
    ): array {
        return [
            'line_id' => $lineId,
            'owner_profile_key' => $ownerKey,
            'owner_callback_base_url' => $callbackBaseUrl ?? 'https://'.$ownerKey.'.example.test',
            'connector_code' => $connectorCode,
            'connector_type' => $connectorType,
            'lease_scope' => $scope,
            'token_hash' => str_repeat('a', 64),
            'expires_at' => $expiresAt ?? now()->addMinutes(5)->timestamp,
        ];
    }

    private function insertNonCanonicalDatabaseRoute(
        string $lineId,
        string $status = Bitrix24OpenLineRoute::STATUS_ACTIVE,
        string $portal = 'stagecrm.fvds.ru',
        string $connectorCode = 'abrikosoff_telegram',
        string $callbackBaseUrl = 'https://local-1.example.test',
    ): int {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => $portal,
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'callback_base_url' => $callbackBaseUrl,
        ]);
        $owner = Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => 'local-1',
            'display_name' => 'Local 1',
            'callback_base_url' => $callbackBaseUrl,
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        return DB::table('bitrix24_open_line_routes')->insertGetId([
            'bitrix24_profile_id' => $profile->id,
            'callback_owner_id' => $owner->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => $connectorCode,
            'line_id' => $lineId,
            'line_name' => 'Telegram',
            'line_owner_key' => in_array($status, Bitrix24OpenLineRoute::usableStatuses(), true)
                ? $profile->portal_domain.'#'.$lineId
                : null,
            'status' => $status,
            'mutation_state_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLegacyProfile(
        ?string $telegramLineId,
        ?string $maxLineId,
        string $profileCallbackBaseUrl = 'https://local-1.example.test',
        string $ownerCallbackBaseUrl = 'https://local-1.example.test',
    ): Bitrix24Profile {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'callback_base_url' => $profileCallbackBaseUrl,
            'telegram_connector_code' => 'abrikosoff_telegram',
            'max_connector_code' => 'abrikosoff_max',
        ]);
        Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => 'local-1',
            'display_name' => 'Local 1',
            'callback_base_url' => $ownerCallbackBaseUrl,
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);
        $profile->forceFill([
            'telegram_line_id' => $telegramLineId,
            'max_line_id' => $maxLineId,
        ])->save();

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function writeJson(string $fileName, array $document): void
    {
        file_put_contents(
            $this->storageDirectory.'/'.$fileName,
            json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $fileName): array
    {
        return json_decode(
            (string) file_get_contents($this->storageDirectory.'/'.$fileName),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function assertPrivateFile(string $path): void
    {
        $permissions = fileperms($path);

        $this->assertIsInt($permissions);
        $this->assertSame(0600, $permissions & 0777, $path.' must have 0600 permissions.');
    }
}
