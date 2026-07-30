<?php

namespace Tests\Feature;

use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Services\Bitrix24\AutoSetupBitrix24OpenLineRouteAction;
use App\Services\Bitrix24\Bitrix24OpenLineAutoSetupException;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistrySnapshotLock;
use App\Services\Bitrix24\MarkBitrix24OpenLineRouteMisconfiguredAction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class Bitrix24OpenLineRouteDatabasePortabilityTest extends TestCase
{
    private const CONNECTION = 'bitrix24_route_portability_sqlite';

    private const STATE_TRANSITION_CONNECTION = 'bitrix24_route_state_transition';

    private string $databasePath;

    private mixed $originalDefaultConnection;

    private mixed $originalPortabilityConnection;

    private mixed $originalStateTransitionConnection;

    private mixed $originalCacheStore;

    protected function setUp(): void
    {
        parent::setUp();

        $databasePath = tempnam(sys_get_temp_dir(), 'ab-connector-route-sqlite-');

        if ($databasePath === false) {
            throw new RuntimeException('Не удалось создать временную SQLite-базу.');
        }

        $this->databasePath = $databasePath;
        $this->originalDefaultConnection = config('database.default');
        $this->originalPortabilityConnection = config('database.connections.'.self::CONNECTION);
        $this->originalStateTransitionConnection = config(
            'database.connections.'.self::STATE_TRANSITION_CONNECTION,
        );
        $this->originalCacheStore = config('cache.default');

        $connection = [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => null,
            'synchronous' => null,
        ];

        config()->set([
            'database.default' => self::CONNECTION,
            'database.connections.'.self::CONNECTION => $connection,
            'database.connections.'.self::STATE_TRANSITION_CONNECTION => $connection,
            'cache.default' => 'array',
        ]);

        DB::purge(self::CONNECTION);
        DB::purge(self::STATE_TRANSITION_CONNECTION);
        Cache::flush();
        $this->app->forgetInstance(Bitrix24OpenLinesRouteRegistrySnapshotLock::class);

        $this->createSqliteSchema();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        $this->app->forgetInstance(Bitrix24OpenLinesRouteRegistrySnapshotLock::class);
        DB::disconnect(self::STATE_TRANSITION_CONNECTION);
        DB::disconnect(self::CONNECTION);
        DB::purge(self::STATE_TRANSITION_CONNECTION);
        DB::purge(self::CONNECTION);

        config()->set([
            'database.default' => $this->originalDefaultConnection,
            'database.connections.'.self::CONNECTION => $this->originalPortabilityConnection,
            'database.connections.'.self::STATE_TRANSITION_CONNECTION => $this->originalStateTransitionConnection,
            'cache.default' => $this->originalCacheStore,
        ]);

        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_short_state_transition_runs_on_sqlite_without_postgresql_system_columns(): void
    {
        $routeId = $this->insertRoute();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (in_array($query->connectionName, [
                self::CONNECTION,
                self::STATE_TRANSITION_CONNECTION,
            ], true)) {
                $queries[] = $query->sql;
            }
        });

        $route = app(MarkBitrix24OpenLineRouteMisconfiguredAction::class)->handle(
            $routeId,
            'SQLite transition',
        );

        $this->assertInstanceOf(Bitrix24OpenLineRoute::class, $route);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_MISCONFIGURED, $route->status);
        $this->assertSame(1, $route->mutation_state_version);
        $this->assertRouteQueriesArePortable($queries);
    }

    public function test_connector_refresh_route_lookup_runs_on_sqlite_without_postgresql_system_columns(): void
    {
        $routeId = $this->insertRoute();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if ($query->connectionName === self::CONNECTION) {
                $queries[] = $query->sql;
            }
        });

        $connection = new Bitrix24Connection([
            'profile_id' => 999,
            'portal_domain' => 'stagecrm.fvds.ru',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);
        $connection->setRelation('profile', new Bitrix24Profile);

        $route = new Bitrix24OpenLineRoute([
            'bitrix24_profile_id' => 10,
            'channel_id' => 30,
        ]);
        $route->setAttribute('id', $routeId);

        try {
            app(AutoSetupBitrix24OpenLineRouteAction::class)
                ->refreshConnectorRegistration($connection, $route);

            $this->fail('Ожидалась доменная ошибка несовпадения профиля.');
        } catch (Bitrix24OpenLineAutoSetupException $exception) {
            $this->assertSame(
                'Bitrix24-подключение относится к другому профилю.',
                $exception->getMessage(),
            );
        }

        $this->assertRouteQueriesArePortable($queries);
    }

    private function createSqliteSchema(): void
    {
        $schema = Schema::connection(self::CONNECTION);

        $schema->create('bitrix24_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('portal_domain');
            $table->string('profile_key');
            $table->string('profile_type');
            $table->string('display_name')->nullable();
            $table->string('callback_base_url')->nullable();
            $table->timestamps();
        });

        $schema->create('bitrix24_callback_owners', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bitrix24_profile_id');
            $table->string('owner_key');
            $table->string('display_name')->nullable();
            $table->string('callback_base_url');
            $table->string('status');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        $schema->create('channels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('platform');
            $table->string('connection_type');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('bitrix24_open_line_routes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bitrix24_profile_id');
            $table->unsignedBigInteger('channel_id');
            $table->string('portal_domain');
            $table->string('profile_key');
            $table->string('channel_type');
            $table->string('connector_code')->nullable();
            $table->string('line_id')->nullable();
            $table->string('line_name')->nullable();
            $table->string('line_owner_key')->nullable();
            $table->unsignedBigInteger('callback_owner_id')->nullable();
            $table->string('source_id')->nullable();
            $table->string('status');
            $table->string('last_error_message', 1024)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->uuid('mutation_operation_id')->nullable();
            $table->unsignedBigInteger('mutation_state_version')->default(0);
            $table->timestamp('mutation_lease_expires_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    private function insertRoute(): int
    {
        $connection = DB::connection(self::CONNECTION);
        $now = now();

        $connection->table('bitrix24_profiles')->insert([
            'id' => 10,
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'staging',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'callback_base_url' => 'https://callback.example.test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->table('bitrix24_callback_owners')->insert([
            'id' => 20,
            'bitrix24_profile_id' => 10,
            'owner_key' => 'local-1',
            'display_name' => 'Локалка',
            'callback_base_url' => 'https://callback.example.test',
            'status' => 'active',
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->table('channels')->insert([
            'id' => 30,
            'name' => 'MAX bot',
            'platform' => 'max',
            'connection_type' => 'bot',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->table('bitrix24_open_line_routes')->insert([
            'id' => 40,
            'bitrix24_profile_id' => 10,
            'channel_id' => 30,
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'staging',
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX,
            'connector_code' => 'abc_max',
            'line_id' => '14',
            'line_name' => 'MAX bot',
            'line_owner_key' => 'stagecrm.fvds.ru#14',
            'callback_owner_id' => 20,
            'source_id' => 'ABC_MAX',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            'mutation_state_version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return 40;
    }

    /**
     * @param  list<string>  $queries
     */
    private function assertRouteQueriesArePortable(array $queries): void
    {
        $routeQueries = array_values(array_filter(
            $queries,
            static fn (string $query): bool => str_contains(
                mb_strtolower($query),
                'bitrix24_open_line_routes',
            ),
        ));

        $this->assertNotEmpty($routeQueries);

        foreach ($routeQueries as $query) {
            $this->assertStringNotContainsString('xmin', mb_strtolower($query));
            $this->assertStringNotContainsString('::text', mb_strtolower($query));
        }
    }
}
