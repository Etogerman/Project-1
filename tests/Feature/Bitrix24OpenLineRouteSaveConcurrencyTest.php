<?php

namespace Tests\Feature;

use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Filament\Resources\Bitrix24Connections\Pages\ViewBitrix24Connection;
use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use App\Models\User;
use App\Services\Bitrix24\AutoSetupBitrix24OpenLineRouteAction;
use App\Services\Bitrix24\Bitrix24ApiClient;
use App\Services\Bitrix24\Bitrix24OpenLineAutoSetupException;
use App\Services\Bitrix24\Bitrix24OpenLineRouteOperationLock;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class Bitrix24OpenLineRouteSaveConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_generic_save_serializes_a_concurrent_misconfigured_transition(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The production row-locking contract is PostgreSQL-specific.');
        }

        [$admin, $connection, $channel, $route] = $this->makeRouteFixture();
        $component = Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set(
                "openLineRouteForms.{$channel->id}.status",
                Bitrix24OpenLineRoute::STATUS_INACTIVE,
            );

        $defaultConnection = config('database.default');
        $concurrentConnection = 'bitrix24_route_save_concurrent';
        config([
            'database.connections.'.$concurrentConnection => config(
                'database.connections.'.$defaultConnection,
            ),
        ]);
        DB::purge($concurrentConnection);

        $concurrentWriteAttempted = false;
        $lockedReadObserved = false;
        $concurrentWriteException = null;

        DB::listen(function (QueryExecuted $query) use (
            &$concurrentWriteAttempted,
            &$lockedReadObserved,
            &$concurrentWriteException,
            $concurrentConnection,
            $defaultConnection,
            $route,
        ): void {
            if ($query->connectionName !== $defaultConnection || $concurrentWriteAttempted) {
                return;
            }

            $sql = mb_strtolower(trim($query->sql));

            if (
                ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'bitrix24_open_line_routes')
                || ! str_contains($sql, 'bitrix24_profile_id')
                || ! str_contains($sql, 'channel_id')
            ) {
                return;
            }

            $concurrentWriteAttempted = true;
            $lockedReadObserved = str_contains($sql, 'for update');
            $connection = DB::connection($concurrentConnection);
            $connection->statement("SET lock_timeout TO '100ms'");

            try {
                $connection->table('bitrix24_open_line_routes')
                    ->where('id', $route->id)
                    ->update([
                        'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
                        'line_owner_key' => null,
                        'last_error_message' => 'Конкурентная ошибка refresh.',
                        'last_error_at' => now(),
                        'updated_at' => now(),
                    ]);
            } catch (QueryException $exception) {
                $concurrentWriteException = $exception;
            } finally {
                $connection->statement('SET lock_timeout TO DEFAULT');
            }
        });

        try {
            $component
                ->call('saveOpenLineRoute', $channel->id)
                ->assertSet('openLineRouteErrorMessage', null);

            $this->assertTrue($concurrentWriteAttempted);
            $this->assertTrue($lockedReadObserved);
            $this->assertInstanceOf(QueryException::class, $concurrentWriteException);
            $this->assertSame(
                '55P03',
                (string) ($concurrentWriteException->errorInfo[0] ?? $concurrentWriteException->getCode()),
            );
            $this->assertSame(
                Bitrix24OpenLineRoute::STATUS_INACTIVE,
                $route->fresh()->status,
            );

            DB::connection($concurrentConnection)
                ->table('bitrix24_open_line_routes')
                ->where('id', $route->id)
                ->update([
                    'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
                    'line_owner_key' => null,
                    'last_error_message' => 'Конкурентная ошибка refresh.',
                    'last_error_at' => now(),
                    'updated_at' => now(),
                ]);
        } finally {
            DB::purge($concurrentConnection);
        }

        $route->refresh();

        $this->assertSame(Bitrix24OpenLineRoute::STATUS_MISCONFIGURED, $route->status);
        $this->assertNull($route->line_owner_key);
        $this->assertSame('Конкурентная ошибка refresh.', $route->last_error_message);
        $this->assertSame('abc_telegram', $route->connector_code);
        $this->assertSame('line-original', $route->line_id);
    }

    public function test_stale_form_cannot_override_a_misconfigured_transition_committed_first(): void
    {
        [$admin, $connection, $channel, $route] = $this->makeRouteFixture();
        $component = Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set(
                "openLineRouteForms.{$channel->id}.status",
                Bitrix24OpenLineRoute::STATUS_LEGACY,
            );

        Bitrix24OpenLineRoute::query()
            ->whereKey($route->id)
            ->update([
                'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
                'line_owner_key' => null,
                'last_error_message' => 'Refresh завершился раньше сохранения.',
                'last_error_at' => now(),
                'updated_at' => now(),
            ]);

        $component
            ->call('saveOpenLineRoute', $channel->id)
            ->assertSet(
                'openLineRouteErrorMessage',
                'Статус маршрута с ошибкой нельзя менять обычным сохранением.',
            );

        $route->refresh();

        $this->assertSame(Bitrix24OpenLineRoute::STATUS_MISCONFIGURED, $route->status);
        $this->assertNull($route->line_owner_key);
        $this->assertSame('Refresh завершился раньше сохранения.', $route->last_error_message);
        $this->assertSame('abc_telegram', $route->connector_code);
        $this->assertSame('line-original', $route->line_id);
    }

    public function test_refresh_lock_prevents_a_generic_save_from_committing_stale_state(): void
    {
        [$admin, $connection, $channel, $route] = $this->makeRouteFixture();
        $saveAttempted = false;

        $this->mock(Bitrix24ApiClient::class, function ($mock) use (
            $admin,
            $connection,
            $channel,
            &$saveAttempted,
        ): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.register')
                ->andReturnUsing(function () use (
                    $admin,
                    $connection,
                    $channel,
                    &$saveAttempted,
                ): Bitrix24RestResponseData {
                    $saveAttempted = true;

                    Livewire::actingAs($admin)
                        ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
                        ->set(
                            "openLineRouteForms.{$channel->id}.status",
                            Bitrix24OpenLineRoute::STATUS_INACTIVE,
                        )
                        ->set(
                            "openLineRouteForms.{$channel->id}.source_id",
                            'ABC_TELEGRAM_STALE_SAVE',
                        )
                        ->call('saveOpenLineRoute', $channel->id)
                        ->assertSet(
                            'openLineRouteErrorMessage',
                            Bitrix24OpenLineRouteOperationLock::BUSY_MESSAGE,
                        );

                    return $this->bitrixResponse(['result' => true]);
                });

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.connector.data.set')
                ->andReturn($this->bitrixResponse(true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.activate')
                ->andReturn($this->bitrixResponse(true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params): bool => $method === 'imopenlines.config.update'
                    && data_get($params, 'PARAMS.CRM_SOURCE') === 'ABC_TELEGRAM')
                ->andReturn($this->bitrixResponse(true));
        });

        app(AutoSetupBitrix24OpenLineRouteAction::class)
            ->refreshConnectorRegistration($connection, $route);

        $route->refresh();

        $this->assertTrue($saveAttempted);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->status);
        $this->assertSame('ABC_TELEGRAM', $route->source_id);
        $this->assertNull($route->last_error_message);
    }

    public function test_generic_save_lock_prevents_refresh_from_reading_stale_state(): void
    {
        [$admin, $connection, $channel, $route] = $this->makeRouteFixture();
        $component = Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set(
                "openLineRouteForms.{$channel->id}.status",
                Bitrix24OpenLineRoute::STATUS_INACTIVE,
            );
        $defaultConnection = config('database.default');
        $refreshAttempted = false;
        $refreshException = null;

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldNotReceive('call');
        });

        DB::listen(function (QueryExecuted $query) use (
            &$refreshAttempted,
            &$refreshException,
            $connection,
            $defaultConnection,
            $route,
        ): void {
            if ($query->connectionName !== $defaultConnection || $refreshAttempted) {
                return;
            }

            $sql = mb_strtolower(trim($query->sql));

            if (
                ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'bitrix24_open_line_routes')
                || ! str_contains($sql, 'for update')
            ) {
                return;
            }

            $refreshAttempted = true;

            try {
                app(AutoSetupBitrix24OpenLineRouteAction::class)
                    ->refreshConnectorRegistration($connection, $route);
            } catch (Bitrix24OpenLineAutoSetupException $exception) {
                $refreshException = $exception;
            }
        });

        $component
            ->call('saveOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', null);

        $this->assertTrue($refreshAttempted);
        $this->assertInstanceOf(Bitrix24OpenLineAutoSetupException::class, $refreshException);
        $this->assertSame(
            Bitrix24OpenLineRouteOperationLock::BUSY_MESSAGE,
            $refreshException->getMessage(),
        );
        $route->refresh();

        $this->assertSame(Bitrix24OpenLineRoute::STATUS_INACTIVE, $route->status);
        $this->assertSame('ABC_TELEGRAM', $route->source_id);
    }

    /**
     * @return array{User, Bitrix24Connection, Channel, Bitrix24OpenLineRoute}
     */
    private function makeRouteFixture(): array
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => AutoSetupBitrix24OpenLineRouteAction::SUPPORTED_PORTAL_DOMAIN,
            'profile_key' => 'staging',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'callback_base_url' => 'https://route-concurrency.example.test',
        ]);
        $callbackOwner = Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => Bitrix24CallbackOwner::DEFAULT_LOCAL_OWNER_KEY,
            'display_name' => 'Локалка 1',
            'callback_base_url' => $profile->callback_base_url,
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);
        $connection = Bitrix24Connection::query()->forceCreate([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'application_name' => 'Abrikosoff Connector',
            'client_id' => 'local.app',
            'member_id' => 'member-route-concurrency',
            'application_token' => 'application-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_encrypted' => 'secret-access-token',
            'refresh_token_encrypted' => 'secret-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'scope' => AutoSetupBitrix24OpenLineRouteAction::REQUIRED_SCOPES,
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'installed_at' => now()->subHour(),
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Конкурентный Telegram',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abc_telegram',
            'line_id' => 'line-original',
            'callback_owner_id' => $callbackOwner->id,
            'source_id' => 'ABC_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        return [$admin, $connection, $channel, $route];
    }

    private function bitrixResponse(mixed $result): Bitrix24RestResponseData
    {
        return new Bitrix24RestResponseData(
            successful: true,
            httpStatus: 200,
            result: $result,
            errorCode: null,
            errorMessage: null,
            raw: ['result' => $result],
            requestMethod: 'POST',
            restMethod: 'test.method',
            attemptedRefresh: false,
        );
    }
}
