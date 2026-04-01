<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class Bitrix24CallbackControllerTest extends TestCase
{
    public function test_install_callback_accepts_post_requests_and_logs_sanitized_context(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('bitrix24_callbacks')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('bitrix24 production callback received', Mockery::on(function (array $context): bool {
                return $context['callback_type'] === 'install'
                    && $context['method'] === 'POST'
                    && $context['path'] === 'callbacks/bitrix24/install'
                    && $context['payload']['auth']['domain'] === 'crm.alexlesley.biz'
                    && ! array_key_exists('access_token', $context['payload']['auth'])
                    && ! array_key_exists('authorization', $context['headers'])
                    && ! array_key_exists('cookie', $context['headers']);
            }));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer secret-token',
            'Cookie' => 'session=secret-cookie',
            'X-Bitrix-Test' => 'install',
        ])->postJson('/callbacks/bitrix24/install', [
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
            ->assertJsonPath('callback_type', 'install')
            ->assertJsonPath('method', 'POST');
    }

    public function test_events_callback_accepts_get_requests(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('bitrix24_callbacks')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('bitrix24 production callback received', Mockery::on(function (array $context): bool {
                return $context['callback_type'] === 'events'
                    && $context['method'] === 'GET'
                    && $context['path'] === 'callbacks/bitrix24/events'
                    && $context['bitrix_event'] === 'ONCRMCONTACTUPDATE';
            }));

        $response = $this->getJson('/callbacks/bitrix24/events?event=ONCRMCONTACTUPDATE');

        $response->assertOk()
            ->assertJsonPath('callback_type', 'events')
            ->assertJsonPath('method', 'GET');
    }

    public function test_openlines_callback_accepts_post_requests(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('bitrix24_callbacks')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('bitrix24 production callback received', Mockery::on(function (array $context): bool {
                return $context['callback_type'] === 'openlines'
                    && $context['method'] === 'POST'
                    && $context['path'] === 'callbacks/bitrix24/openlines'
                    && $context['bitrix_event'] === 'OnImConnectorMessageAdd';
            }));

        $response = $this->postJson('/callbacks/bitrix24/openlines', [
            'event' => 'OnImConnectorMessageAdd',
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'openlines')
            ->assertJsonPath('method', 'POST');
    }
}
