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
            '014' => $this->lease('local-1', '014'),
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
            ' 14 ' => $this->lease('local-1', ' 14 '),
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

    /**
     * @param  array<string, array<string, mixed>>  $owners
     * @return array<string, mixed>
     */
    private function registry(array $owners): array
    {
        return [
            'schema_version' => 1,
            'portal_domain' => 'stagecrm.fvds.ru',
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
    private function lease(string $ownerKey, string $lineId): array
    {
        return [
            'line_id' => $lineId,
            'owner_profile_key' => $ownerKey,
            'owner_callback_base_url' => 'https://'.$ownerKey.'.example.test',
            'connector_code' => 'abrikosoff_telegram',
            'connector_type' => 'telegram',
            'lease_scope' => 'line_runtime',
            'token_hash' => str_repeat('a', 64),
            'expires_at' => now()->addMinutes(5)->timestamp,
        ];
    }

    private function insertNonCanonicalDatabaseRoute(
        string $lineId,
        string $status = Bitrix24OpenLineRoute::STATUS_ACTIVE,
    ): int {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'callback_base_url' => 'https://local-1.example.test',
        ]);
        $owner = Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => 'local-1',
            'display_name' => 'Local 1',
            'callback_base_url' => 'https://local-1.example.test',
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
            'connector_code' => 'abrikosoff_telegram',
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
