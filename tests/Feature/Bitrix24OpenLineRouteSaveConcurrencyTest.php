<?php

namespace Tests\Feature;

use App\Filament\Resources\Bitrix24Connections\Pages\ViewBitrix24Connection;
use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use App\Models\User;
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
            'portal_domain' => 'crm.route-concurrency.test',
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
            'scope' => ['crm'],
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'installed_at' => now()->subHour(),
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Конкурентный Telegram',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
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
}
