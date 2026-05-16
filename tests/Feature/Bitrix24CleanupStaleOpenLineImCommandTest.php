<?php

namespace Tests\Feature;

use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Models\Bitrix24Connection;
use App\Services\Bitrix24\Bitrix24ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithBitrix24RuntimeProfile;
use Tests\TestCase;

class Bitrix24CleanupStaleOpenLineImCommandTest extends TestCase
{
    use InteractsWithBitrix24RuntimeProfile;
    use RefreshDatabase;

    private const STALE_USER_CODE = 'imol|abrikosoff_max|3|abrikosoff-dialog:3|32';

    private const KEEP_USER_CODE = 'imol|abrikosoff_max|3|abrikosoff-dialog:23|17';

    public function test_dry_run_finds_stale_im_row_without_mutating_bitrix24(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection();

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (
                    string $method,
                    array $params,
                    Bitrix24Connection $usedConnection,
                    bool $transportRetry,
                ): bool => $method === 'crm.contact.get'
                    && $params === ['ID' => '9']
                    && $usedConnection->is($connection)
                    && $transportRetry === false)
                ->andReturn($this->bitrixResponse($this->contactPayload()));
        });

        $this->artisan('bitrix24:cleanup-stale-openline-im', [
            '--connection' => $connection->id,
            '--contact' => '9',
            '--remove-user-code' => self::STALE_USER_CODE,
            '--keep-user-code' => self::KEEP_USER_CODE,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry-run completed. Bitrix24 was not mutated.')
            ->assertSuccessful();
    }

    public function test_apply_deletes_only_matched_stale_im_row_and_verifies_keep_row(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection();

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (
                    string $method,
                    array $params,
                    Bitrix24Connection $usedConnection,
                    bool $transportRetry,
                ): bool => $method === 'crm.contact.get'
                    && $params === ['ID' => '9']
                    && $usedConnection->is($connection)
                    && $transportRetry === false)
                ->ordered()
                ->andReturn($this->bitrixResponse($this->contactPayload()));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (
                    string $method,
                    array $params,
                    Bitrix24Connection $usedConnection,
                    bool $transportRetry,
                ): bool => $method === 'crm.contact.update'
                    && $params === [
                        'ID' => '9',
                        'FIELDS' => [
                            'IM' => [[
                                'ID' => '101',
                                'DELETE' => 'Y',
                            ]],
                        ],
                    ]
                    && $usedConnection->is($connection)
                    && $transportRetry === false)
                ->ordered()
                ->andReturn($this->bitrixResponse(true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (
                    string $method,
                    array $params,
                    Bitrix24Connection $usedConnection,
                    bool $transportRetry,
                ): bool => $method === 'crm.contact.get'
                    && $params === ['ID' => '9']
                    && $usedConnection->is($connection)
                    && $transportRetry === false)
                ->ordered()
                ->andReturn($this->bitrixResponse($this->contactPayload(includeStale: false)));
        });

        $this->artisan('bitrix24:cleanup-stale-openline-im', [
            '--connection' => $connection->id,
            '--contact' => '9',
            '--remove-user-code' => self::STALE_USER_CODE,
            '--keep-user-code' => self::KEEP_USER_CODE,
            '--expected-im-id' => '101',
            '--apply' => true,
        ])
            ->expectsOutputToContain('Stale Bitrix24 Open Lines IM row deleted and verified.')
            ->assertSuccessful();
    }

    public function test_cleanup_is_blocked_when_required_keep_row_is_missing(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection();

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (
                    string $method,
                    array $params,
                    Bitrix24Connection $usedConnection,
                    bool $transportRetry,
                ): bool => $method === 'crm.contact.get'
                    && $params === ['ID' => '9']
                    && $usedConnection->is($connection)
                    && $transportRetry === false)
                ->andReturn($this->bitrixResponse($this->contactPayload(includeKeep: false)));
        });

        $this->artisan('bitrix24:cleanup-stale-openline-im', [
            '--connection' => $connection->id,
            '--contact' => '9',
            '--remove-user-code' => self::STALE_USER_CODE,
            '--keep-user-code' => self::KEEP_USER_CODE,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Required keep-user-code row was not found. Cleanup is blocked.')
            ->assertFailed();
    }

    /**
     * @return array<string, mixed>
     */
    private function contactPayload(bool $includeStale = true, bool $includeKeep = true): array
    {
        $imRows = [];

        if ($includeStale) {
            $imRows[] = [
                'ID' => '101',
                'VALUE_TYPE' => 'IMOL',
                'VALUE' => self::STALE_USER_CODE,
            ];
        }

        if ($includeKeep) {
            $imRows[] = [
                'ID' => '102',
                'VALUE_TYPE' => 'IMOL',
                'VALUE' => self::KEEP_USER_CODE,
            ];
        }

        return [
            'ID' => '9',
            'IM' => $imRows,
        ];
    }

    private function bitrixResponse(mixed $result, bool $successful = true): Bitrix24RestResponseData
    {
        return new Bitrix24RestResponseData(
            successful: $successful,
            httpStatus: $successful ? 200 : 400,
            result: $result,
            errorCode: $successful ? null : 'ERROR',
            errorMessage: $successful ? null : 'Bitrix24 error',
            raw: ['result' => $result],
            requestMethod: 'POST',
            restMethod: 'test',
            attemptedRefresh: false,
        );
    }
}
