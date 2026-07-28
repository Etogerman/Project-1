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
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryClient;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryException;
use App\Services\Bitrix24\MarkBitrix24OpenLineRouteMisconfiguredAction;
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
        $this->mock(Bitrix24OpenLinesRouteRegistryClient::class, function ($mock): void {
            $mock->shouldReceive('acquireLineLease')
                ->byDefault()
                ->andReturn([
                    'lease_token' => str_repeat('c', 64),
                    'expires_at' => now()->addHour()->toIso8601String(),
                ]);
            $mock->shouldReceive('releaseLineLease')
                ->byDefault();
        });
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

            $mock->shouldNotReceive('call')
                ->with('imconnector.activate', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params): bool => $method === 'imopenlines.config.update'
                    && ($params['CONFIG_ID'] ?? null) === 'line-original'
                    && ($params['PARAMS'] ?? null) === [
                        'CRM' => 'Y',
                        'CRM_CREATE' => 'deal',
                        'CRM_SOURCE' => 'ABC_TELEGRAM',
                    ])
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

    public function test_refresh_line_lock_prevents_another_channel_from_claiming_the_same_line(): void
    {
        [$admin, $connection, , $route] = $this->makeRouteFixture();
        $profile = $connection->profile()->firstOrFail();
        $route->forceFill([
            'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
            'last_error_message' => 'Маршрут ожидает безопасного refresh.',
            'last_error_at' => now()->subMinute(),
        ])->save();
        $secondChannel = Channel::factory()->create([
            'name' => 'Второй Telegram',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'second-telegram-token'],
        ]);
        $secondRoute = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $secondChannel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($secondChannel),
            'connector_code' => 'abc_second',
            'line_id' => 'line-original',
            'callback_owner_id' => $route->callback_owner_id,
            'source_id' => 'ABC_SECOND',
            'status' => Bitrix24OpenLineRoute::STATUS_INACTIVE,
        ]);
        $saveAttempted = false;

        $this->mock(Bitrix24ApiClient::class, function ($mock) use (
            $admin,
            $connection,
            $secondChannel,
            &$saveAttempted,
        ): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.register')
                ->andReturn($this->bitrixResponse(['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.connector.data.set')
                ->andReturn($this->bitrixResponse(true));

            $mock->shouldNotReceive('call')
                ->with('imconnector.activate', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params): bool => $method === 'imopenlines.config.update'
                    && ($params['CONFIG_ID'] ?? null) === 'line-original'
                    && ($params['PARAMS'] ?? null) === [
                        'CRM' => 'Y',
                        'CRM_CREATE' => 'deal',
                        'CRM_SOURCE' => 'ABC_TELEGRAM',
                    ])
                ->andReturnUsing(function () use (
                    $admin,
                    $connection,
                    $secondChannel,
                    &$saveAttempted,
                ): Bitrix24RestResponseData {
                    $saveAttempted = true;

                    Livewire::actingAs($admin)
                        ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
                        ->set(
                            "openLineRouteForms.{$secondChannel->id}.status",
                            Bitrix24OpenLineRoute::STATUS_ACTIVE,
                        )
                        ->call('saveOpenLineRoute', $secondChannel->id)
                        ->assertSet(
                            'openLineRouteErrorMessage',
                            Bitrix24OpenLineRouteOperationLock::BUSY_MESSAGE,
                        );

                    return $this->bitrixResponse(true);
                });
        });

        app(AutoSetupBitrix24OpenLineRouteAction::class)
            ->refreshConnectorRegistration($connection, $route);

        $route->refresh();
        $secondRoute->refresh();

        $this->assertTrue($saveAttempted);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_MISCONFIGURED, $route->status);
        $this->assertNull($route->line_owner_key);
        $this->assertNull($route->last_error_message);
        $this->assertNull($route->last_error_at);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_INACTIVE, $secondRoute->status);
        $this->assertNull($secondRoute->line_owner_key);
        $this->assertSame('abc_second', $secondRoute->connector_code);
        $this->assertSame('line-original', $secondRoute->line_id);
    }

    public function test_registry_owner_conflict_blocks_refresh_before_any_bitrix_mutation(): void
    {
        [, $connection, , $route] = $this->makeRouteFixture();
        $this->mock(Bitrix24OpenLinesRouteRegistryClient::class, function ($mock): void {
            $mock->shouldReceive('acquireLineLease')
                ->once()
                ->andThrow(new Bitrix24OpenLinesRouteRegistryException(
                    'route_registry_line_owner_conflict',
                ));
            $mock->shouldNotReceive('releaseLineLease');
        });
        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldNotReceive('call');
        });

        try {
            app(AutoSetupBitrix24OpenLineRouteAction::class)
                ->refreshConnectorRegistration($connection, $route);
            $this->fail('Чужой владелец LINE_ID должен блокировать refresh.');
        } catch (Bitrix24OpenLineAutoSetupException $exception) {
            $this->assertSame(
                'Открытая линия закреплена в общем OpenLines registry за другим контуром.',
                $exception->getMessage(),
            );
        }

        $route->refresh();

        $this->assertSame(Bitrix24OpenLineRoute::STATUS_MISCONFIGURED, $route->status);
        $this->assertSame(
            'Открытая линия закреплена в общем OpenLines registry за другим контуром.',
            $route->last_error_message,
        );
    }

    public function test_expiring_registry_lease_stops_refresh_before_next_bitrix_mutation(): void
    {
        [, $connection, , $route] = $this->makeRouteFixture();
        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.register')
                ->andReturnUsing(function (): Bitrix24RestResponseData {
                    $this->travel(400)->seconds();

                    return $this->bitrixResponse(['result' => true]);
                });
            $mock->shouldNotReceive('call')
                ->with('imconnector.connector.data.set', \Mockery::any(), \Mockery::any());
            $mock->shouldNotReceive('call')
                ->with('imopenlines.config.update', \Mockery::any(), \Mockery::any());
        });

        try {
            app(AutoSetupBitrix24OpenLineRouteAction::class)
                ->refreshConnectorRegistration($connection, $route);
            $this->fail('Недостаточный остаток lease должен остановить следующую Bitrix24-мутацию.');
        } catch (Bitrix24OpenLineAutoSetupException $exception) {
            $this->assertSame(
                'Срок общей аренды открытой линии недостаточен для безопасного завершения операции. Повторите попытку.',
                $exception->getMessage(),
            );
        } finally {
            $this->travelBack();
        }

        $route->refresh();

        $this->assertSame(Bitrix24OpenLineRoute::STATUS_MISCONFIGURED, $route->status);
        $this->assertSame(
            'Срок общей аренды открытой линии недостаточен для безопасного завершения операции. Повторите попытку.',
            $route->last_error_message,
        );
    }

    public function test_fail_closed_transition_during_connector_metadata_refresh_cancels_completion_without_activation_calls(): void
    {
        [, $connection, , $route] = $this->makeRouteFixture();
        $transitionCommitted = false;

        $this->mock(Bitrix24ApiClient::class, function ($mock) use (
            $route,
            &$transitionCommitted,
        ): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.register')
                ->andReturn($this->bitrixResponse(['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.connector.data.set')
                ->andReturnUsing(function () use (
                    $route,
                    &$transitionCommitted,
                ): Bitrix24RestResponseData {
                    app(MarkBitrix24OpenLineRouteMisconfiguredAction::class)->handle(
                        (int) $route->getKey(),
                        'Live export обнаружил неактивную линию.',
                    );
                    $transitionCommitted = true;

                    return $this->bitrixResponse(true);
                });

            $mock->shouldReceive('call')
                ->never()
                ->withArgs(fn (string $method): bool => in_array($method, [
                    'imconnector.activate',
                    'imopenlines.config.update',
                ], true));
        });

        try {
            app(AutoSetupBitrix24OpenLineRouteAction::class)
                ->refreshConnectorRegistration($connection, $route);
            $this->fail('Expected a concurrent route transition exception.');
        } catch (Bitrix24OpenLineAutoSetupException $exception) {
            $this->assertSame(
                'Маршрут ОЛ изменился во время обновления. Обновление карточки не завершено; проверьте актуальное состояние маршрута.',
                $exception->getMessage(),
            );
        }

        $route->refresh();

        $this->assertTrue($transitionCommitted);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_MISCONFIGURED, $route->status);
        $this->assertNull($route->line_owner_key);
        $this->assertSame('Live export обнаружил неактивную линию.', $route->last_error_message);
        $this->assertNotNull($route->last_error_at);
    }

    public function test_fail_closed_transition_during_crm_sync_preserves_misconfigured_state_without_activation_calls(): void
    {
        [, $connection, , $route] = $this->makeRouteFixture();
        $route->forceFill([
            'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
            'last_error_message' => 'Старая ошибка refresh.',
            'last_error_at' => now()->subMinute(),
        ])->save();
        $transitionCommitted = false;
        $transactionLevelDuringCrmSync = null;
        $stateVersionBeforeTransition = null;
        $stateVersionAfterTransition = null;

        $this->mock(Bitrix24ApiClient::class, function ($mock) use (
            $route,
            &$transitionCommitted,
            &$transactionLevelDuringCrmSync,
            &$stateVersionBeforeTransition,
            &$stateVersionAfterTransition,
        ): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.register')
                ->andReturn($this->bitrixResponse(['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.connector.data.set')
                ->andReturn($this->bitrixResponse(true));

            $mock->shouldReceive('call')
                ->never()
                ->withArgs(fn (string $method): bool => in_array($method, [
                    'imconnector.activate',
                    'imopenlines.config.add',
                ], true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params): bool => $method === 'imopenlines.config.update'
                    && ($params['CONFIG_ID'] ?? null) === 'line-original'
                    && ($params['PARAMS'] ?? null) === [
                        'CRM' => 'Y',
                        'CRM_CREATE' => 'deal',
                        'CRM_SOURCE' => 'ABC_TELEGRAM',
                    ])
                ->andReturnUsing(function () use (
                    $route,
                    &$transitionCommitted,
                    &$transactionLevelDuringCrmSync,
                    &$stateVersionBeforeTransition,
                    &$stateVersionAfterTransition,
                ): Bitrix24RestResponseData {
                    $transactionLevelDuringCrmSync = DB::transactionLevel();
                    $stateVersionBeforeTransition = (string) DB::table('bitrix24_open_line_routes')
                        ->where('id', $route->getKey())
                        ->value(DB::raw('xmin::text'));
                    app(MarkBitrix24OpenLineRouteMisconfiguredAction::class)->handle(
                        (int) $route->getKey(),
                        'Конкурентная ошибка live export во время CRM sync.',
                    );
                    $stateVersionAfterTransition = (string) DB::table('bitrix24_open_line_routes')
                        ->where('id', $route->getKey())
                        ->value(DB::raw('xmin::text'));
                    $transitionCommitted = true;

                    return $this->bitrixResponse(true);
                });
        });

        $caughtException = null;

        try {
            app(AutoSetupBitrix24OpenLineRouteAction::class)
                ->refreshConnectorRegistration($connection, $route);
        } catch (Bitrix24OpenLineAutoSetupException $exception) {
            $caughtException = $exception;
        }

        $route->refresh();

        $this->assertTrue(
            $transitionCommitted,
            $caughtException?->getMessage() ?? 'CRM sync transition callback was not executed.',
        );
        $this->assertSame(0, $transactionLevelDuringCrmSync);
        $this->assertNotSame($stateVersionBeforeTransition, $stateVersionAfterTransition);
        $this->assertInstanceOf(Bitrix24OpenLineAutoSetupException::class, $caughtException);
        $this->assertSame(
            'Маршрут ОЛ изменился во время обновления. Обновление карточки не завершено; проверьте актуальное состояние маршрута.',
            $caughtException->getMessage(),
        );
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_MISCONFIGURED, $route->status);
        $this->assertNull($route->line_owner_key);
        $this->assertSame(
            'Конкурентная ошибка live export во время CRM sync.',
            $route->last_error_message,
        );
        $this->assertNotNull($route->last_error_at);
        $this->assertSame('abc_telegram', $route->connector_code);
        $this->assertSame('line-original', $route->line_id);
    }

    public function test_active_route_refresh_syncs_crm_settings_without_activating_connector(): void
    {
        [, $connection, , $route] = $this->makeRouteFixture();

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.register')
                ->andReturn($this->bitrixResponse(['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.connector.data.set')
                ->andReturn($this->bitrixResponse(true));

            $mock->shouldNotReceive('call')
                ->with('imconnector.activate', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params): bool => $method === 'imopenlines.config.update'
                    && ($params['CONFIG_ID'] ?? null) === 'line-original'
                    && ($params['PARAMS'] ?? null) === [
                        'CRM' => 'Y',
                        'CRM_CREATE' => 'deal',
                        'CRM_SOURCE' => 'ABC_TELEGRAM',
                    ])
                ->andReturn($this->bitrixResponse(true));
        });

        app(AutoSetupBitrix24OpenLineRouteAction::class)
            ->refreshConnectorRegistration($connection, $route);

        $route->refresh();

        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->status);
        $this->assertSame('stagecrm.fvds.ru#line-original', $route->line_owner_key);
        $this->assertNull($route->last_error_message);
        $this->assertNull($route->last_error_at);
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
