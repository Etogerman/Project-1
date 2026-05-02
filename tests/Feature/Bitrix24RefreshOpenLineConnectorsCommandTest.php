<?php

namespace Tests\Feature;

use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Channel;
use App\Services\Bitrix24\AutoSetupBitrix24OpenLineRouteAction;
use App\Services\Bitrix24\Bitrix24ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithBitrix24RuntimeProfile;
use Tests\TestCase;

class Bitrix24RefreshOpenLineConnectorsCommandTest extends TestCase
{
    use InteractsWithBitrix24RuntimeProfile;
    use RefreshDatabase;

    public function test_refreshes_telegram_bot_connector_registration_without_recreating_open_line(): void
    {
        config()->set('bitrix24.application.name', 'Старое имя из env');

        $connection = $this->makeProfileLinkedActiveBitrix24Connection(
            connectionOverrides: [
                'portal_domain' => AutoSetupBitrix24OpenLineRouteAction::SUPPORTED_PORTAL_DOMAIN,
                'application_name' => 'Old Connector Name',
                'scope' => AutoSetupBitrix24OpenLineRouteAction::REQUIRED_SCOPES,
            ],
            profileOverrides: [
                'portal_domain' => AutoSetupBitrix24OpenLineRouteAction::SUPPORTED_PORTAL_DOMAIN,
                'telegram_connector_code' => 'abc_telegram',
                'telegram_source_id' => 'ABC_TELEGRAM_DEV',
            ],
        );
        $profile = $connection->profile()->firstOrFail();
        $channel = Channel::factory()->create([
            'name' => 'Локальный бот',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abc_telegram',
            'line_id' => '5',
            'source_id' => 'ABC_TELEGRAM_DEV',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            'last_error_message' => 'Старое предупреждение',
            'last_error_at' => now()->subMinute(),
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($channel, $connection): void {
            $mock->shouldNotReceive('call')->with('imopenlines.config.add', \Mockery::any(), \Mockery::any());
            $mock->shouldNotReceive('call')->with('imconnector.activate', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'app.info'
                    && $params === []
                    && $usedConnection->is($connection))
                ->andReturn($this->bitrixResponse(true, ['NAME' => 'Герман-4']));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(function (string $method, array $params, Bitrix24Connection $usedConnection) use ($connection): bool {
                    return $method === 'imconnector.register'
                        && $usedConnection->is($connection)
                        && $params['ID'] === 'abc_telegram'
                        && $params['NAME'] === 'Герман-4 ABC Telegram bot'
                        && $params['ICON']['COLOR'] === '#2AABEE'
                        && $params['ICON_DISABLED']['COLOR'] === '#99ADB3'
                        && str_contains($this->decodedSvgDataImage($params['ICON']['DATA_IMAGE'] ?? null), 'path fill="white"');
                })
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.connector.data.set'
                    && $usedConnection->is($connection)
                    && $params['CONNECTOR'] === 'abc_telegram'
                    && $params['LINE'] === '5'
                    && $params['DATA']['ID'] === 'channel:'.$channel->id)
                ->andReturn($this->bitrixResponse(true, true));
        });

        $this->artisan('bitrix24:refresh-openline-connectors', [
            '--connection' => $connection->id,
        ])->assertSuccessful();

        $this->assertSame('Герман-4', $connection->refresh()->application_name);
        $this->assertSame('5', $route->refresh()->line_id);
        $this->assertSame('stagecrm.fvds.ru#5', $route->line_owner_key);
        $this->assertNull($route->last_error_message);
        $this->assertNull($route->last_error_at);
    }

    public function test_refreshes_max_connector_registration_with_max_name_and_icon(): void
    {
        config()->set('bitrix24.application.name', 'Старое имя из env');

        $connection = $this->makeProfileLinkedActiveBitrix24Connection(
            connectionOverrides: [
                'portal_domain' => AutoSetupBitrix24OpenLineRouteAction::SUPPORTED_PORTAL_DOMAIN,
                'application_name' => 'Old Connector Name',
                'scope' => AutoSetupBitrix24OpenLineRouteAction::REQUIRED_SCOPES,
            ],
            profileOverrides: [
                'portal_domain' => AutoSetupBitrix24OpenLineRouteAction::SUPPORTED_PORTAL_DOMAIN,
                'max_connector_code' => 'abc_max',
                'max_source_id' => 'ABC_MAX_DEV',
            ],
        );
        $profile = $connection->profile()->firstOrFail();
        $channel = Channel::factory()->create([
            'name' => 'MAX канал',
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'max-token'],
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX,
            'connector_code' => 'abc_max',
            'line_id' => '7',
            'source_id' => 'ABC_MAX_DEV',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($channel, $connection): void {
            $mock->shouldNotReceive('call')->with('imopenlines.config.add', \Mockery::any(), \Mockery::any());
            $mock->shouldNotReceive('call')->with('imconnector.activate', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'app.info'
                    && $params === []
                    && $usedConnection->is($connection))
                ->andReturn($this->bitrixResponse(true, ['NAME' => 'Герман-4']));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(function (string $method, array $params): bool {
                    return $method === 'imconnector.register'
                        && $params['ID'] === 'abc_max'
                        && $params['NAME'] === 'Герман-4 ABC MAX bot'
                        && $params['ICON']['COLOR'] === '#7C3AED'
                        && str_contains($this->decodedSvgDataImage($params['ICON']['DATA_IMAGE'] ?? null), '>MAX<');
                })
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params): bool => $method === 'imconnector.connector.data.set'
                    && $params['CONNECTOR'] === 'abc_max'
                    && $params['LINE'] === '7'
                    && $params['DATA']['ID'] === 'channel:'.$channel->id)
                ->andReturn($this->bitrixResponse(true, true));
        });

        $this->artisan('bitrix24:refresh-openline-connectors', [
            '--connection' => $connection->id,
        ])->assertSuccessful();

        $this->assertSame('Герман-4', $connection->refresh()->application_name);
    }

    public function test_dry_run_does_not_call_bitrix24(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(
            connectionOverrides: [
                'portal_domain' => AutoSetupBitrix24OpenLineRouteAction::SUPPORTED_PORTAL_DOMAIN,
                'scope' => AutoSetupBitrix24OpenLineRouteAction::REQUIRED_SCOPES,
            ],
            profileOverrides: [
                'portal_domain' => AutoSetupBitrix24OpenLineRouteAction::SUPPORTED_PORTAL_DOMAIN,
                'telegram_connector_code' => 'abc_telegram',
                'telegram_source_id' => 'ABC_TELEGRAM_DEV',
            ],
        );
        $profile = $connection->profile()->firstOrFail();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abc_telegram',
            'line_id' => '5',
            'source_id' => 'ABC_TELEGRAM_DEV',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldNotReceive('call');
        });

        $this->artisan('bitrix24:refresh-openline-connectors', [
            '--connection' => $connection->id,
            '--dry-run' => true,
        ])->assertSuccessful();
    }

    private function decodedSvgDataImage(mixed $value): string
    {
        if (! is_string($value) || ! str_starts_with($value, 'data:image/svg+xml,')) {
            return '';
        }

        return rawurldecode(Str::after($value, 'data:image/svg+xml,'));
    }

    private function bitrixResponse(
        bool $successful,
        mixed $result,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): Bitrix24RestResponseData {
        return new Bitrix24RestResponseData(
            successful: $successful,
            httpStatus: $successful ? 200 : 400,
            result: $result,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            raw: [
                'result' => $result,
                'error' => $errorCode,
                'error_description' => $errorMessage,
            ],
            requestMethod: 'POST',
            restMethod: 'test.method',
            attemptedRefresh: false,
        );
    }
}
