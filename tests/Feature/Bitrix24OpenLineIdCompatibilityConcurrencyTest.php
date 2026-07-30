<?php

namespace Tests\Feature;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use App\Services\Bitrix24\Bitrix24OpenLineIdCompatibilityException;
use App\Services\Bitrix24\Bitrix24OpenLineIdCompatibilityService;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistrySnapshotLock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Bitrix24OpenLineIdCompatibilityConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private string $storageDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDirectory = sys_get_temp_dir()
            .'/b24-line-id-compatibility-concurrency-'.bin2hex(random_bytes(8));
        mkdir($this->storageDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storageDirectory);

        parent::tearDown();
    }

    public function test_artifact_locks_callback_owner_identity_until_snapshot_is_written(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The production compatibility locking contract is PostgreSQL-specific.');
        }

        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'callback_base_url' => 'https://owner-before.example.test',
        ]);
        $owner = Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => 'local-1',
            'display_name' => 'Local 1',
            'callback_base_url' => 'https://owner-before.example.test',
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'callback_owner_id' => $owner->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '14',
            'line_name' => 'Telegram',
            'line_owner_key' => $profile->portal_domain.'#14',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        $defaultConnection = (string) config('database.default');
        $concurrentConnection = 'bitrix24_compatibility_owner_concurrent';
        config([
            'database.connections.'.$concurrentConnection => config(
                'database.connections.'.$defaultConnection,
            ),
        ]);
        DB::purge($concurrentConnection);

        $concurrentWriteAttempted = false;
        $ownerLockObserved = false;
        $concurrentWriteException = null;

        DB::listen(function (QueryExecuted $query) use (
            &$concurrentWriteAttempted,
            &$ownerLockObserved,
            &$concurrentWriteException,
            $concurrentConnection,
            $defaultConnection,
            $owner,
        ): void {
            if ($query->connectionName !== $defaultConnection || $concurrentWriteAttempted) {
                return;
            }

            $sql = mb_strtolower(trim($query->sql));

            if (! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'bitrix24_callback_owners')
                || ! str_contains($sql, 'for update')
            ) {
                return;
            }

            $concurrentWriteAttempted = true;
            $ownerLockObserved = true;
            $connection = DB::connection($concurrentConnection);
            $connection->statement("SET lock_timeout TO '100ms'");

            try {
                $connection->table('bitrix24_callback_owners')
                    ->where('id', $owner->id)
                    ->update([
                        'owner_key' => 'owner-after',
                        'callback_base_url' => 'https://owner-after.example.test',
                        'updated_at' => now(),
                    ]);
            } catch (QueryException $exception) {
                $concurrentWriteException = $exception;
            } finally {
                $connection->statement('SET lock_timeout TO DEFAULT');
            }
        });

        try {
            $artifact = app(Bitrix24OpenLineIdCompatibilityService::class)
                ->preflightArtifact(
                    $this->storageDirectory,
                    $this->storageDirectory.'/compatibility.json',
                );
        } finally {
            DB::purge($concurrentConnection);
        }

        $databaseEntry = collect($artifact['entries'])
            ->firstWhere('locator', 'route:'.$route->id);

        $this->assertTrue($concurrentWriteAttempted);
        $this->assertTrue($ownerLockObserved);
        $this->assertInstanceOf(QueryException::class, $concurrentWriteException);
        $this->assertSame(
            '55P03',
            (string) ($concurrentWriteException->errorInfo[0] ?? $concurrentWriteException->getCode()),
        );
        $this->assertIsArray($databaseEntry);
        $this->assertSame(
            'local-1|https://owner-before.example.test|abrikosoff_telegram|telegram',
            $databaseEntry['identity'],
        );
        $this->assertSame('local-1', $owner->fresh()->owner_key);
        $this->assertSame(
            'https://owner-before.example.test',
            $owner->fresh()->callback_base_url,
        );
    }

    public function test_busy_snapshot_lock_is_reported_as_compatibility_error(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The production compatibility locking contract is PostgreSQL-specific.');
        }

        $defaultConnection = (string) config('database.default');
        $concurrentConnection = 'bitrix24_compatibility_snapshot_concurrent';
        config([
            'database.connections.'.$concurrentConnection => config(
                'database.connections.'.$defaultConnection,
            ),
        ]);
        DB::purge($concurrentConnection);
        $heldLock = new Bitrix24OpenLinesRouteRegistrySnapshotLock(
            DB::connection($concurrentConnection),
        );

        try {
            $heldLock->run(function (): void {
                try {
                    app(Bitrix24OpenLineIdCompatibilityService::class)
                        ->preflight($this->storageDirectory);

                    $this->fail('Compatibility preflight must fail closed while a snapshot is published.');
                } catch (Bitrix24OpenLineIdCompatibilityException $exception) {
                    $this->assertSame(
                        'openlines_line_id_snapshot_busy',
                        $exception->errorCode,
                    );
                    $this->assertSame(
                        Bitrix24OpenLinesRouteRegistrySnapshotLock::BUSY_MESSAGE,
                        $exception->getMessage(),
                    );
                }
            });
        } finally {
            DB::purge($concurrentConnection);
        }
    }
}
