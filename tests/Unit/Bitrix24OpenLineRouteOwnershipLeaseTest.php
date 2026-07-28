<?php

namespace Tests\Unit;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Profile;
use App\Services\Bitrix24\Bitrix24OpenLineRouteOwnershipLease;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryClient;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryException;
use Tests\TestCase;

class Bitrix24OpenLineRouteOwnershipLeaseTest extends TestCase
{
    public function test_callback_runs_only_inside_signed_registry_lease(): void
    {
        [$profile, $owner] = $this->identity();
        $client = $this->mock(Bitrix24OpenLinesRouteRegistryClient::class);
        $client->shouldReceive('acquireLineLease')
            ->once()
            ->ordered()
            ->with($profile, $owner, 'abc_max', 'max', '14', 360)
            ->andReturn([
                'lease_token' => str_repeat('a', 64),
                'expires_at' => now()->addMinutes(6)->toIso8601String(),
            ]);
        $client->shouldReceive('releaseLineLease')
            ->once()
            ->ordered()
            ->with($profile, $owner, '14', str_repeat('a', 64));
        $callbackRan = false;

        $result = app(Bitrix24OpenLineRouteOwnershipLease::class)->run(
            $profile,
            $owner,
            'abc_max',
            'max',
            '14',
            360,
            function () use (&$callbackRan): string {
                $callbackRan = true;

                return 'completed';
            },
        );

        $this->assertTrue($callbackRan);
        $this->assertSame('completed', $result);
    }

    public function test_registry_ownership_conflict_blocks_callback_and_release(): void
    {
        [$profile, $owner] = $this->identity();
        $client = $this->mock(Bitrix24OpenLinesRouteRegistryClient::class);
        $client->shouldReceive('acquireLineLease')
            ->once()
            ->andThrow(new Bitrix24OpenLinesRouteRegistryException(
                'route_registry_line_owner_conflict',
            ));
        $client->shouldNotReceive('releaseLineLease');
        $callbackRan = false;

        try {
            app(Bitrix24OpenLineRouteOwnershipLease::class)->run(
                $profile,
                $owner,
                'abc_max',
                'max',
                '14',
                360,
                function () use (&$callbackRan): void {
                    $callbackRan = true;
                },
            );
            $this->fail('Ownership conflict must fail closed.');
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->assertSame('route_registry_line_owner_conflict', $exception->errorCode);
        }

        $this->assertFalse($callbackRan);
    }

    /**
     * @return array{Bitrix24Profile, Bitrix24CallbackOwner}
     */
    private function identity(): array
    {
        $profile = new Bitrix24Profile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'staging',
        ]);
        $profile->setAttribute('id', 77);
        $owner = new Bitrix24CallbackOwner([
            'bitrix24_profile_id' => 77,
            'owner_key' => 'local-1',
            'callback_base_url' => 'https://abr-8000-local.abrikosov.biz',
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);

        return [$profile, $owner];
    }
}
