<?php

namespace Tests\Feature;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24SyncLog;
use App\Models\Channel;
use App\Services\Bitrix24\Bitrix24ApiClient;
use App\Services\Bitrix24\Bitrix24OpenLineMutationAuthority;
use App\Services\Bitrix24\Bitrix24OpenLineMutationAuthorityContext;
use App\Services\Bitrix24\Bitrix24OpenLineMutationAuthorityException;
use App\Services\Bitrix24\Bitrix24OpenLineRepairException;
use App\Services\Bitrix24\Bitrix24OpenLineRouteLeaseDeadline;
use App\Services\Bitrix24\Bitrix24OpenLineRouteMutationFence;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryClient;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use App\Services\Bitrix24\MarkBitrix24OpenLineRouteMisconfiguredAction;
use App\Services\Bitrix24\PersistBitrix24RefreshResultAction;
use App\Services\Bitrix24\RepairStaleBitrix24OpenLineAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Bitrix24OpenLineMutationFenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_local_mutations_keep_the_same_operation_fence_current(): void
    {
        $route = $this->makeRoute();
        [$authority, $fence] = $this->beginAuthority($route);

        $fence->runMutation(
            $authority,
            function (?Bitrix24OpenLineRoute $lockedRoute): void {
                $lockedRoute?->forceFill([
                    'last_error_message' => 'first',
                ])->save();
            },
        );
        $fence->assertCurrent($authority);

        $fence->runMutation(
            $authority,
            function (?Bitrix24OpenLineRoute $lockedRoute): void {
                $lockedRoute?->forceFill([
                    'last_error_message' => 'second',
                ])->save();
            },
        );
        $fence->assertCurrent($authority);

        $route->refresh();
        $this->assertSame('second', $route->last_error_message);
        $this->assertSame($authority->operationId, $route->mutation_operation_id);
        $this->assertSame($authority->expectedStateVersion, $route->mutation_state_version);
    }

    public function test_route_returned_from_guarded_mutation_resumes_versioning(): void
    {
        $route = $this->makeRoute();
        [$authority, $fence] = $this->beginAuthority($route);

        $returnedRoute = $fence->runMutation(
            $authority,
            fn (?Bitrix24OpenLineRoute $lockedRoute): ?Bitrix24OpenLineRoute => $lockedRoute,
        );

        $this->assertInstanceOf(Bitrix24OpenLineRoute::class, $returnedRoute);

        $returnedRoute->forceFill([
            'last_error_message' => 'outside guarded mutation',
        ])->save();

        $this->assertSame(
            $authority->expectedStateVersion + 1,
            $returnedRoute->mutation_state_version,
        );
        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'id' => $route->id,
            'mutation_state_version' => $authority->expectedStateVersion + 1,
            'last_error_message' => 'outside guarded mutation',
        ]);
    }

    public function test_authority_rejects_noncanonical_alias_of_the_same_line(): void
    {
        $route = $this->makeRoute();
        [$authority] = $this->beginAuthority($route);

        $this->expectException(Bitrix24OpenLineMutationAuthorityException::class);
        $this->expectExceptionMessage('другому маршруту');

        $authority->assertSameRoute(
            (string) $route->portal_domain,
            (string) $route->connector_code,
            '013',
        );
    }

    public function test_stale_operation_cannot_demote_state_written_by_newer_operation(): void
    {
        $route = $this->makeRoute();
        [$authority, $fence] = $this->beginAuthority($route);
        $newOperationId = (string) Str::uuid();

        Bitrix24OpenLineRoute::query()
            ->whereKey($route->id)
            ->update([
                'mutation_operation_id' => $newOperationId,
                'mutation_state_version' => $authority->expectedStateVersion + 1,
                'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            ]);

        try {
            $fence->runMutation(
                $authority,
                function (?Bitrix24OpenLineRoute $lockedRoute): void {
                    $lockedRoute?->forceFill([
                        'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
                    ])->save();
                },
            );
            $this->fail('Stale operation must not commit a late route update.');
        } catch (Bitrix24OpenLineMutationAuthorityException $exception) {
            $this->assertSame('openlines_mutation_fence_stale', $exception->errorCode);
        }

        $route->refresh();
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->status);
        $this->assertSame($newOperationId, $route->mutation_operation_id);
    }

    public function test_authority_cannot_commit_after_identity_changes_without_version_bump(): void
    {
        $route = $this->makeRoute();
        [$authority, $fence] = $this->beginAuthority($route);

        Bitrix24OpenLineRoute::query()
            ->whereKey($route->id)
            ->update([
                'line_id' => '14',
                'line_owner_key' => 'crm.example.test#14',
            ]);

        try {
            $fence->runMutation(
                $authority,
                function (?Bitrix24OpenLineRoute $lockedRoute): void {
                    $lockedRoute?->forceFill([
                        'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
                    ])->save();
                },
            );
            $this->fail('Authority старой identity не должна выполнить поздний commit.');
        } catch (Bitrix24OpenLineMutationAuthorityException $exception) {
            $this->assertSame('openlines_mutation_fence_stale', $exception->errorCode);
        }

        $route->refresh();
        $this->assertSame('14', $route->line_id);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->status);
        $this->assertSame($authority->operationId, $route->mutation_operation_id);
        $this->assertSame($authority->expectedStateVersion, $route->mutation_state_version);
    }

    public function test_stale_authority_cannot_persist_refreshed_tokens(): void
    {
        $route = $this->makeRoute();
        [$authority] = $this->beginAuthority($route);
        $connection = $this->makeConnection($route);
        $this->supersedeAuthority($route, $authority);

        try {
            app(Bitrix24OpenLineMutationAuthorityContext::class)->run(
                $authority,
                fn () => app(PersistBitrix24RefreshResultAction::class)->handleSuccess(
                    $connection,
                    'new-access-token',
                    'new-refresh-token',
                    now()->addHour(),
                ),
            );
            $this->fail('Stale authority must not persist refreshed credentials.');
        } catch (Bitrix24OpenLineMutationAuthorityException $exception) {
            $this->assertSame('openlines_mutation_fence_stale', $exception->errorCode);
        }

        $connection->refresh();
        $this->assertSame('old-access-token', $connection->access_token_encrypted);
        $this->assertSame('old-refresh-token', $connection->refresh_token_encrypted);
    }

    public function test_stale_authority_drops_best_effort_api_log(): void
    {
        $route = $this->makeRoute();
        [$authority] = $this->beginAuthority($route);
        $connection = $this->makeConnection($route);
        $this->supersedeAuthority($route, $authority);

        $result = app(Bitrix24OpenLineMutationAuthorityContext::class)->run(
            $authority,
            fn () => app(LogBitrix24ApiCallAction::class)->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'stale_authority_log',
                status: Bitrix24SyncLog::STATUS_FAILED,
                connection: $connection,
            ),
        );

        $this->assertNull($result);
        $this->assertDatabaseMissing('bitrix24_sync_logs', [
            'operation' => 'stale_authority_log',
        ]);
    }

    public function test_expected_state_demotion_does_not_overwrite_newer_route_version(): void
    {
        $route = $this->makeRoute();
        $expectedRoute = $route->fresh();

        Bitrix24OpenLineRoute::query()
            ->whereKey($route->id)
            ->update([
                'source_id' => 'SOURCE_FROM_NEWER_OPERATION',
                'mutation_state_version' => 1,
            ]);

        $result = app(MarkBitrix24OpenLineRouteMisconfiguredAction::class)
            ->handleExpected($expectedRoute, 'ownership conflict');

        $this->assertNull($result);
        $route->refresh();
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->status);
        $this->assertSame('SOURCE_FROM_NEWER_OPERATION', $route->source_id);
        $this->assertSame(1, $route->mutation_state_version);
    }

    public function test_stale_repair_rejects_whitespace_alias_of_canonical_line_id(): void
    {
        $route = $this->makeRoute();
        $connection = $this->makeConnection($route);
        $staleLog = Bitrix24SyncLog::query()->create([
            'connection_id' => $connection->id,
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'openlines_stale_chat_detected',
            'request_payload' => [
                'source_bitrix_chat_id' => '23',
                'current_bitrix_chat_id' => '26',
                'connector_code' => $route->connector_code,
                'line_id' => ' 13 ',
            ],
            'status' => Bitrix24SyncLog::STATUS_FAILED,
        ]);

        $registryClient = $this->mock(Bitrix24OpenLinesRouteRegistryClient::class);
        $registryClient->shouldReceive('acquireLineLease')
            ->once()
            ->andReturn([
                'lease_token' => str_repeat('b', 64),
                'expires_at' => now()->addMinutes(6)->toIso8601String(),
            ]);
        $registryClient->shouldReceive('releaseLineLease')->once();
        $this->mock(Bitrix24ApiClient::class)
            ->shouldNotReceive('call');

        $this->expectException(Bitrix24OpenLineRepairException::class);
        $this->expectExceptionMessage('Диагностика относится к другому маршруту');

        app(RepairStaleBitrix24OpenLineAction::class)->handle($connection, $route, $staleLog);
    }

    /**
     * @return array{Bitrix24OpenLineMutationAuthority, Bitrix24OpenLineRouteMutationFence}
     */
    private function beginAuthority(Bitrix24OpenLineRoute $route): array
    {
        $fence = app(Bitrix24OpenLineRouteMutationFence::class);
        $deadline = Bitrix24OpenLineRouteLeaseDeadline::fromRegistryLease(
            now()->addMinutes(6)->toAtomString(),
            360,
            1,
        );
        $operationId = (string) Str::uuid();
        $expectedVersion = $fence->begin($route, $operationId, $deadline);

        return [
            new Bitrix24OpenLineMutationAuthority(
                portalDomain: (string) $route->portal_domain,
                lineId: (string) $route->line_id,
                ownerProfileKey: (string) $route->callbackOwner?->owner_key,
                ownerCallbackBaseUrl: (string) $route->callbackOwner?->callback_base_url,
                connectorCode: (string) $route->connector_code,
                connectorType: 'telegram',
                scope: Bitrix24OpenLineMutationAuthority::SCOPE_LINE_RUNTIME,
                leaseToken: str_repeat('a', 64),
                deadline: $deadline,
                operationId: $operationId,
                operationType: 'test',
                routeId: (int) $route->id,
                expectedStateVersion: $expectedVersion,
            ),
            $fence,
        ];
    }

    private function makeRoute(): Bitrix24OpenLineRoute
    {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.example.test',
            'profile_key' => 'staging',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'callback_base_url' => 'https://project.example.test',
        ]);
        $owner = Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => 'local-1',
            'display_name' => 'Local 1',
            'callback_base_url' => 'https://project.example.test',
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        return Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'callback_owner_id' => $owner->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '13',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
    }

    private function makeConnection(Bitrix24OpenLineRoute $route): Bitrix24Connection
    {
        return Bitrix24Connection::query()->forceCreate([
            'profile_id' => $route->bitrix24_profile_id,
            'portal_domain' => $route->portal_domain,
            'application_name' => 'ABC test',
            'client_id' => 'test.app',
            'member_id' => 'test-member',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_encrypted' => 'old-access-token',
            'refresh_token_encrypted' => 'old-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'scope' => ['crm', 'imopenlines', 'imconnector'],
            'client_endpoint' => 'https://client.example.test/rest/',
            'server_endpoint' => 'https://server.example.test/rest/',
            'install_payload' => [],
            'installed_at' => now(),
        ]);
    }

    private function supersedeAuthority(
        Bitrix24OpenLineRoute $route,
        Bitrix24OpenLineMutationAuthority $authority,
    ): void {
        Bitrix24OpenLineRoute::query()
            ->whereKey($route->id)
            ->update([
                'mutation_operation_id' => (string) Str::uuid(),
                'mutation_state_version' => $authority->expectedStateVersion + 1,
            ]);
    }
}
