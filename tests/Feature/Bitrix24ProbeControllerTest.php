<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class Bitrix24ProbeControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->restoreEnvironment('testing');

        parent::tearDown();
    }

    public function test_probe_endpoint_accepts_post_callbacks_and_logs_sanitized_context(): void
    {
        Storage::fake('local');

        Log::shouldReceive('channel')
            ->once()
            ->with('bitrix24_probe')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('bitrix24 probe callback received', Mockery::on(function (array $context): bool {
                return $context['method'] === 'POST'
                    && $context['path'] === 'callbacks/bitrix24/probe'
                    && $context['bitrix_event'] === 'ONCRMCONTACTUPDATE'
                    && $context['bitrix_auth']['domain'] === 'crm.alexlesley.biz'
                    && ! array_key_exists('authorization', $context['headers'])
                    && ! array_key_exists('cookie', $context['headers'])
                    && $context['payload']['data']['FIELDS']['ID'] === 123
                    && $context['payload']['auth']['domain'] === 'crm.alexlesley.biz'
                    && ! array_key_exists('access_token', $context['payload']['auth'])
                    && ! array_key_exists('refresh_token', $context['payload']['auth']);
            }));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer secret-token',
            'Cookie' => 'session=secret-cookie',
            'X-Bitrix-Test' => 'probe',
        ])->postJson('/callbacks/bitrix24/probe', [
            'event' => 'ONCRMCONTACTUPDATE',
            'data' => [
                'FIELDS' => [
                    'ID' => 123,
                ],
            ],
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'application_token' => 'test-token',
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('received', true)
            ->assertJsonPath('method', 'POST');

        Storage::disk('local')->assertExists('bitrix24-probe/latest-auth.json');
        Storage::disk('local')->assertMissing('bitrix24-probe/non-existent.json');

        $storedPayload = json_decode(
            Storage::disk('local')->get('bitrix24-probe/latest-auth.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('ONCRMCONTACTUPDATE', $storedPayload['event']);
        $this->assertSame('crm.alexlesley.biz', $storedPayload['auth']['domain']);
        $this->assertSame('test-token', $storedPayload['auth']['application_token']);
        $this->assertArrayNotHasKey('access_token', $storedPayload['auth']);
        $this->assertArrayNotHasKey('refresh_token', $storedPayload['auth']);
    }

    public function test_probe_endpoint_accepts_get_callbacks(): void
    {
        Storage::fake('local');

        Log::shouldReceive('channel')
            ->once()
            ->with('bitrix24_probe')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('bitrix24 probe callback received', Mockery::on(function (array $context): bool {
                return $context['method'] === 'GET'
                    && $context['query']['event'] === 'ONCRMDEALUPDATE'
                    && $context['bitrix_event'] === 'ONCRMDEALUPDATE';
            }));

        $response = $this->getJson('/callbacks/bitrix24/probe?event=ONCRMDEALUPDATE');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('received', true)
            ->assertJsonPath('method', 'GET');

        Storage::disk('local')->assertMissing('bitrix24-probe/latest-auth.json');
    }

    public function test_probe_route_is_unavailable_outside_local_and_testing(): void
    {
        $this->switchEnvironment('production');

        $this->assertFalse(app('router')->has('callbacks.bitrix24.probe'));

        $this->postJson('/callbacks/bitrix24/probe', [
            'event' => 'ONCRMCONTACTUPDATE',
        ])->assertNotFound();
    }

    private function switchEnvironment(string $environment): void
    {
        putenv("APP_ENV={$environment}");
        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_ENV'] = $environment;

        $this->refreshApplication();
    }

    private function restoreEnvironment(string $environment): void
    {
        putenv("APP_ENV={$environment}");
        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_ENV'] = $environment;

        $this->refreshApplication();
    }
}
